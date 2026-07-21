<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use App\Support\Testing\PostgresTestConnectionGuard;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SignatureSourceBindingSchemaTest;
use Tests\Support\Testing\FakeDatabaseNameResolver;
use Tests\TestCase;

/**
 * Caller-level regression proof for the Codex P2.
 *
 * The defect was NOT in the guard — it was in the CALLER. The schema suite used
 * to hand the guard `config('database.default')` as the "development" identity.
 * Under phpunit.xml that default is SQLite :memory:, so the guard compared a
 * PostgreSQL database against a SQLite file and never proved isolation between
 * two REAL PostgreSQL databases.
 *
 * These tests therefore exercise the schema suite's own caller contract
 * (SignatureSourceBindingSchemaTest::postgresConnectionPair /
 * postgresGateDecisionUsing) with a scripted resolver. They deliberately do NOT
 * re-test guard internals (already covered by PostgresTestConnectionGuardTest),
 * and they never touch a real database.
 */
final class SchemaSuiteGateCallerTest extends TestCase
{
    private function guardWith(FakeDatabaseNameResolver $resolver): PostgresTestConnectionGuard
    {
        return new PostgresTestConnectionGuard($resolver);
    }

    public function test_caller_code_no_longer_reads_the_default_connection(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(SignatureSourceBindingSchemaTest::class))->getFileName()
        );

        // Compare EXECUTABLE code only: the docblocks deliberately mention
        // config('database.default') to explain why it must not be read, so a
        // raw string scan would match its own explanation.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        // The exact regression: the development identity must never be READ
        // from the (SQLite, under PHPUnit) default connection.
        $this->assertStringNotContainsString(
            "config('database.default')",
            $code,
            'The gate must not derive the development identity from database.default.'
        );

        // Routing the suite at the isolated test DB is a WRITE of the default
        // and stays allowed; it is textually distinct from the read above.
        $this->assertStringContainsString("config(['database.default' =>", $code);
        $this->assertStringContainsString("public const DEVELOPMENT_CONNECTION = 'pgsql_development'", $code);
    }

    public function test_caller_hands_the_guard_an_explicit_postgres_connection_pair(): void
    {
        $pair = SignatureSourceBindingSchemaTest::postgresConnectionPair();

        $this->assertSame('pgsql_development', $pair['development']);
        $this->assertNotSame(config('database.default'), $pair['development']);

        // Both sides must be configured PostgreSQL connections.
        $this->assertSame('pgsql', config('database.connections.pgsql_development.driver'));
        $this->assertSame('pgsql', config('database.connections.pgsql_test.driver'));

        // The development database name must not be derived from DB_DATABASE
        // (phpunit forces that to :memory:).
        $this->assertNotSame(':memory:', config('database.connections.pgsql_development.database'));
    }

    public function test_both_sides_are_resolved_through_the_resolver_and_never_the_default_connection(): void
    {
        $resolver = new FakeDatabaseNameResolver([
            'pgsql_test' => 'digital_sign_master_degree_test',
            'pgsql_development' => 'digital_sign_master_degree',
        ]);

        putenv('DB_PG_TEST_ENABLED=true');
        putenv('DB_PG_TEST_CONNECTION=pgsql_test');

        try {
            $decision = SignatureSourceBindingSchemaTest::postgresGateDecisionUsing($this->guardWith($resolver));
        } finally {
            putenv('DB_PG_TEST_ENABLED');
            putenv('DB_PG_TEST_CONNECTION');
        }

        $this->assertSame(PostgresTestConnectionGuard::ACTION_RUN, $decision['action']);

        // Both real PostgreSQL connections went through the resolver...
        $this->assertContains('pgsql_test', $resolver->askedConnections());
        $this->assertContains('pgsql_development', $resolver->askedConnections());

        // ...and the SQLite default never did.
        $this->assertNotContains('sqlite', $resolver->askedConnections());
        $this->assertNotContains(config('database.default'), $resolver->askedConnections());
    }

    public function test_equal_physical_databases_fail_the_gate_instead_of_skipping(): void
    {
        // Both connections resolve to the SAME live database — e.g. a
        // PG_TEST_DATABASE/PG_TEST_URL mistakenly pointed at development.
        $resolver = new FakeDatabaseNameResolver([
            'pgsql_test' => 'digital_sign_master_degree',
            'pgsql_development' => 'digital_sign_master_degree',
        ]);

        putenv('DB_PG_TEST_ENABLED=true');
        putenv('DB_PG_TEST_CONNECTION=pgsql_test');

        try {
            $decision = SignatureSourceBindingSchemaTest::postgresGateDecisionUsing($this->guardWith($resolver));
        } finally {
            putenv('DB_PG_TEST_ENABLED');
            putenv('DB_PG_TEST_CONNECTION');
        }

        // FAIL, never SKIP — an unsafe opt-in must not look like a green run.
        $this->assertSame(PostgresTestConnectionGuard::ACTION_FAIL, $decision['action']);
        $this->assertNotSame(PostgresTestConnectionGuard::ACTION_SKIP, $decision['action']);
        $this->assertStringContainsString('resolves to the development database', (string) $decision['reason']);

        // The refusal happened during resolution — before any transaction or
        // fixture write could be started by the suite's setUp().
        $this->assertSame(0, DB::transactionLevel(), 'The gate must refuse before any transaction is opened.');
    }

    public function test_unresolvable_development_identity_fails_the_gate(): void
    {
        $resolver = new FakeDatabaseNameResolver([
            'pgsql_test' => 'digital_sign_master_degree_test',
            'pgsql_development' => new \RuntimeException('development connection is unreachable'),
        ]);

        putenv('DB_PG_TEST_ENABLED=true');
        putenv('DB_PG_TEST_CONNECTION=pgsql_test');

        try {
            $decision = SignatureSourceBindingSchemaTest::postgresGateDecisionUsing($this->guardWith($resolver));
        } finally {
            putenv('DB_PG_TEST_ENABLED');
            putenv('DB_PG_TEST_CONNECTION');
        }

        $this->assertSame(PostgresTestConnectionGuard::ACTION_FAIL, $decision['action']);
        $this->assertStringContainsString('development connection', (string) $decision['reason']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_opt_in_disabled_still_skips_precisely(): void
    {
        $resolver = new FakeDatabaseNameResolver([]);

        putenv('DB_PG_TEST_ENABLED');
        putenv('DB_PG_TEST_CONNECTION');

        $decision = SignatureSourceBindingSchemaTest::postgresGateDecisionUsing($this->guardWith($resolver));

        $this->assertSame(PostgresTestConnectionGuard::ACTION_SKIP, $decision['action']);
        // Nothing was resolved: a disabled opt-in must not touch any connection.
        $this->assertSame([], $resolver->askedConnections());
        $this->assertSame(0, DB::transactionLevel());
    }
}
