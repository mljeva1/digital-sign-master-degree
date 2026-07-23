<?php

declare(strict_types=1);

namespace Tests\Unit\CertificateRequests;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Support\CertificateRequests\TransientDatabaseFailureClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * P2-3 — only an allow-listed lock/deadlock/serialization SQLSTATE is transient,
 * detected through the WHOLE exception chain (including a wrapping registrar
 * exception), only from REAL DB exception context, and never by leaking a raw
 * driver message.
 *
 * Extends the Laravel TestCase because the SQLite-locked path resolves the
 * connection's real driver through configuration (a PostgreSQL connection with
 * the same wording must NOT be transient).
 */
final class TransientDatabaseFailureClassifierTest extends TestCase
{
    private function classifier(): TransientDatabaseFailureClassifier
    {
        return new TransientDatabaseFailureClassifier;
    }

    private function pdoWithSqlState(string $sqlState, string $driverMessage = 'boom'): PDOException
    {
        $e = new PDOException("SQLSTATE[{$sqlState}]: {$driverMessage}", 0);
        $e->errorInfo = [$sqlState, 7, $driverMessage];

        return $e;
    }

    private function queryException(PDOException $previous, string $connection = 'pgsql'): QueryException
    {
        return new QueryException($connection, 'insert into "certificate_requests" ...', [], $previous);
    }

    // --- positive: real DB lock/deadlock/serialization ------------------------

    public function test_serialization_failure_40001_is_transient(): void
    {
        $this->assertTrue($this->classifier()->isTransient($this->queryException($this->pdoWithSqlState('40001'))));
    }

    public function test_deadlock_40_p01_wrapped_in_a_registrar_exception_is_transient(): void
    {
        $query = $this->queryException($this->pdoWithSqlState('40P01'));
        $wrapped = RegistrationException::of(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, $query);

        $this->assertTrue($this->classifier()->isTransient($wrapped), 'a wrapped deadlock must still be transient');
    }

    public function test_lock_not_available_55_p03_deep_in_the_chain_is_transient(): void
    {
        $pdo = $this->pdoWithSqlState('55P03');
        $query = $this->queryException($pdo);
        $wrapped = RegistrationException::of(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, $query);
        $outer = new RuntimeException('outer', 0, $wrapped);

        $this->assertTrue($this->classifier()->isTransient($outer));
    }

    public function test_sqlite_database_is_locked_on_a_sqlite_connection_is_transient(): void
    {
        $pdo = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 0);
        $pdo->errorInfo = ['HY000', 5, 'database is locked'];

        // A real QueryException whose connection's driver is sqlite.
        $this->assertTrue($this->classifier()->isTransient($this->queryException($pdo, 'sqlite')));
    }

    // --- P2-A: driver from the REAL resolved connection, never config ----------

    public function test_a_resolved_pgsql_connection_stays_pgsql_after_config_is_mutated_to_sqlite(): void
    {
        config(['database.connections.divergence_probe' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
            'database' => 'unused', 'username' => 'unused', 'password' => 'unused',
        ]]);

        // Resolve (and cache) the REAL connection object while it is still pgsql.
        // No physical connection is opened — only the driver identity is read.
        $this->assertSame('pgsql', DB::connection('divergence_probe')->getDriverName());

        // Config is then mutated to falsely claim the same connection is sqlite.
        config(['database.connections.divergence_probe.driver' => 'sqlite']);

        // The already-resolved connection object still reports pgsql, so the sqlite
        // lock wording must NOT be treated as transient.
        $pdo = new PDOException('SQLSTATE[HY000]: General error: database is locked', 0);
        $pdo->errorInfo = ['HY000', 5, 'database is locked'];

        $this->assertFalse($this->classifier()->isTransient($this->queryException($pdo, 'divergence_probe')));
    }

    public function test_pgsql_xx000_database_is_locked_is_not_transient_even_when_config_lies_sqlite(): void
    {
        config(['database.connections.lying_probe' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
            'database' => 'unused', 'username' => 'unused', 'password' => 'unused',
        ]]);
        $this->assertSame('pgsql', DB::connection('lying_probe')->getDriverName());
        config(['database.connections.lying_probe.driver' => 'sqlite']);

        // A genuine PostgreSQL failure (XX000) carrying the sqlite wording is a
        // permanent error; the config lie must not turn it into a retry.
        $pdo = new PDOException('SQLSTATE[XX000]: Internal error: database is locked', 0);
        $pdo->errorInfo = ['XX000', 7, 'database is locked'];

        $this->assertFalse($this->classifier()->isTransient($this->queryException($pdo, 'lying_probe')));
    }

    public function test_a_resolved_sqlite_connection_stays_sqlite_after_config_is_mutated_to_pgsql(): void
    {
        config(['database.connections.sqlite_probe' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $this->assertSame('sqlite', DB::connection('sqlite_probe')->getDriverName());

        // Even if config later lies that this connection is pgsql, the resolved
        // object stays sqlite, so a real sqlite lock stays transient.
        config(['database.connections.sqlite_probe.driver' => 'pgsql']);

        $pdo = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 0);
        $pdo->errorInfo = ['HY000', 5, 'database is locked'];

        $this->assertTrue($this->classifier()->isTransient($this->queryException($pdo, 'sqlite_probe')));
    }

    public function test_a_missing_or_empty_connection_name_is_not_transient(): void
    {
        // getConnectionName() is typed `:string`, so a real QueryException can never
        // carry a literal null; an empty name is the reachable "no usable connection
        // identity" case and the same guard fails closed for both.
        $pdo = new PDOException('SQLSTATE[HY000]: General error: database is locked', 0);
        $pdo->errorInfo = ['HY000', 5, 'database is locked'];

        $this->assertFalse($this->classifier()->isTransient($this->queryException($pdo, '')));
    }

    public function test_an_unresolvable_connection_name_is_not_transient(): void
    {
        // A connection name with no configuration cannot be resolved to a driver;
        // the resolver's exception is swallowed and the failure is not transient.
        $pdo = new PDOException('SQLSTATE[HY000]: General error: database is locked', 0);
        $pdo->errorInfo = ['HY000', 5, 'database is locked'];

        $this->assertFalse($this->classifier()->isTransient($this->queryException($pdo, 'no_such_connection_xyz')));
    }

    // --- negative: closed contract --------------------------------------------

    public function test_generic_runtime_exception_with_a_sqlstate_looking_code_is_not_transient(): void
    {
        // Regression: a SQLSTATE-looking code on a NON-DB throwable is never
        // transient — SQLSTATEs are only meaningful inside a real DB exception.
        $this->assertFalse($this->classifier()->isTransient(new RuntimeException('generic storage failure', 40001)));
    }

    public function test_pgsql_query_exception_containing_database_is_locked_is_not_transient(): void
    {
        // Regression: the SQLite wording is meaningless on a non-sqlite driver;
        // this is a genuine, permanent failure.
        $pdo = new PDOException('SQLSTATE[XX000]: Internal error: database is locked', 0);
        $pdo->errorInfo = ['XX000', 7, 'database is locked'];

        $this->assertFalse($this->classifier()->isTransient($this->queryException($pdo, 'pgsql')));
    }

    public function test_unique_violation_23505_is_not_transient(): void
    {
        $this->assertFalse($this->classifier()->isTransient($this->queryException($this->pdoWithSqlState('23505'))));
    }

    public function test_generic_runtime_exception_is_not_transient(): void
    {
        $this->assertFalse($this->classifier()->isTransient(new RuntimeException('anything')));
    }

    public function test_classifier_answers_only_a_boolean_and_never_exposes_the_raw_message(): void
    {
        $secret = 'ERROR: duplicate key value ... C:\\secret\\path';
        $pdo = new PDOException($secret, 0);
        $pdo->errorInfo = ['23505', 7, $secret];

        $this->assertIsBool($this->classifier()->isTransient($this->queryException($pdo)));
        $this->assertFalse($this->classifier()->isTransient($this->queryException($pdo)));
    }
}
