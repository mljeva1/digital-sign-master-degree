<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use App\Support\Testing\TestSchemaMigrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Testing\SpyTestSchemaMigrator;
use Tests\TestCase;
use Throwable;

/**
 * Opt-in PostgreSQL INTEGRATION proof of the write-before-gate fix on the real
 * production path — NOT a fake resolver.
 *
 * It drives the actual testing:prepare-postgres command with the real
 * LiveDatabaseNameResolver, having pointed the pgsql_test connection's `url`
 * (the same property PG_TEST_URL feeds in config/database.php) at the SAME
 * physical database the development connection resolves to. Only the migrator is
 * a spy, so the real resolver runs a read-only SELECT current_database() over
 * the URL-overridden connection and the guard must refuse before any migration.
 *
 * Note on the harness: phpunit.xml forces DB_DATABASE=:memory:, which makes the
 * env-driven `pgsql` (development) connection unreachable during tests, while
 * `pgsql_test` stays reachable because its database is a literal _test name.
 * So this test first reaches a real local PostgreSQL through pgsql_test, then
 * points the development connection at that same reachable physical database to
 * have a genuine development target to compare against. Everything is read-only
 * (current_database()) and the migrator is a spy — nothing is ever migrated.
 *
 * Skips ONLY when the PostgreSQL opt-in is off or a safe local PostgreSQL
 * connection is not reachable.
 */
final class PrepareTestPostgresUrlOverrideIntegrationTest extends TestCase
{
    public function test_real_resolver_refuses_a_pg_test_url_that_lands_on_development(): void
    {
        if (! filter_var(env('DB_PG_TEST_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Opt-in: set DB_PG_TEST_ENABLED=true with a reachable local PostgreSQL server.');
        }

        if (config('database.connections.pgsql_test.driver') !== 'pgsql'
            || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->markTestSkipped('Both pgsql and pgsql_test must be PostgreSQL connections.');
        }

        // Reach a real local PostgreSQL through the pgsql_test base config (its
        // database is a literal _test name + real host/user/pass), read-only.
        try {
            $physicalDatabase = (string) DB::connection('pgsql_test')->selectOne('select current_database() as db')->db;
        } catch (Throwable) {
            $this->markTestSkipped('A safe local PostgreSQL connection is not available.');
        }

        if ($physicalDatabase === '') {
            $this->markTestSkipped('Could not resolve a local PostgreSQL database name.');
        }

        $base = (array) config('database.connections.pgsql_test');
        $host = (string) ($base['host'] ?? '127.0.0.1');
        $port = (string) ($base['port'] ?? '5432');
        $username = (string) ($base['username'] ?? '');
        $password = (string) ($base['password'] ?? '');

        $auth = $username !== ''
            ? ($password !== '' ? rawurlencode($username).':'.rawurlencode($password).'@' : rawurlencode($username).'@')
            : '';
        $url = "pgsql://{$auth}{$host}:{$port}/{$physicalDatabase}";

        // Snapshot the original configuration so the test is fully hermetic and
        // cannot leak an overridden connection into any later test.
        $originalPgsqlUrl = config('database.connections.pgsql.url');
        $originalPgsqlDatabase = config('database.connections.pgsql.database');
        $originalTestUrl = config('database.connections.pgsql_test.url');

        // Point the DEVELOPMENT connection at that same reachable physical
        // database (only its database name is overridden; real host/creds stay),
        // and drive the TEST connection there via its `url` property.
        config([
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.database' => $physicalDatabase,
            'database.connections.pgsql_test.url' => $url,
        ]);
        DB::purge('pgsql');
        DB::purge('pgsql_test');

        try {
            // Precondition (read-only): the REAL resolver sees both connections
            // land on the same physical database.
            $devResolved = (string) DB::connection('pgsql')->selectOne('select current_database() as db')->db;
            $targetResolved = (string) DB::connection('pgsql_test')->selectOne('select current_database() as db')->db;
            $this->assertSame($physicalDatabase, $devResolved, 'Precondition: development must resolve to the shared physical database.');
            $this->assertSame($physicalDatabase, $targetResolved, 'Precondition: the URL override must land pgsql_test on the shared physical database.');

            // Only the migrator is faked; the resolver stays real.
            $spy = new SpyTestSchemaMigrator;
            $this->app->instance(TestSchemaMigrator::class, $spy);

            $exit = Artisan::call('testing:prepare-postgres');
            $output = Artisan::output();
        } finally {
            // Fully restore: purge, put the original config back, purge again so
            // no rebuilt connection retains the test override.
            DB::purge('pgsql');
            DB::purge('pgsql_test');
            config([
                'database.connections.pgsql.url' => $originalPgsqlUrl,
                'database.connections.pgsql.database' => $originalPgsqlDatabase,
                'database.connections.pgsql_test.url' => $originalTestUrl,
            ]);
            DB::purge('pgsql');
            DB::purge('pgsql_test');
        }

        $this->assertNotSame(0, $exit, 'Command must refuse when the test URL resolves to the development database.');
        $this->assertFalse($spy->wasCalled(), 'No migration may run when the test URL lands on development.');

        // The refusal reason must be the specific, sanitized equality message.
        $this->assertStringContainsString('the test connection resolves to the development database', $output);

        // Output must not leak the URL or any credential fragment.
        $this->assertStringNotContainsString($url, $output);
        $this->assertStringNotContainsString('://', $output);
        $this->assertStringNotContainsString($host, $output);
        if ($username !== '') {
            $this->assertStringNotContainsString($username, $output);
        }
        if ($password !== '') {
            $this->assertStringNotContainsString($password, $output);
        }
    }
}
