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
 * physical database the DEVELOPMENT IDENTITY (`pgsql_development`) resolves to.
 * Only the migrator is a spy, so the real resolver runs a read-only SELECT
 * current_database() over the URL-overridden connection and the guard must refuse
 * before any migration.
 *
 * Note on the harness: the development identity is the dedicated `pgsql_development`
 * connection (P2-5), whose database is a literal name (not the phpunit-forced
 * :memory:), so it is reachable read-only during tests. This test points the TEST
 * connection's `url` at that development database to model a malicious PG_TEST_URL,
 * and the guard must catch the equality before any write. Everything is read-only
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
            || config('database.connections.pgsql_development.driver') !== 'pgsql') {
            $this->markTestSkipped('Both pgsql_test and pgsql_development must be PostgreSQL connections.');
        }

        // Read the DEVELOPMENT database name from the real development identity
        // (pgsql_development), read-only.
        try {
            $developmentDatabase = (string) DB::connection('pgsql_development')->selectOne('select current_database() as db')->db;
        } catch (Throwable) {
            $this->markTestSkipped('A safe local PostgreSQL connection is not available.');
        }

        if ($developmentDatabase === '') {
            $this->markTestSkipped('Could not resolve a local PostgreSQL database name.');
        }

        // Build a malicious PG_TEST_URL that lands the TEST connection on the
        // DEVELOPMENT database, using the development connection's real host/creds.
        $dev = (array) config('database.connections.pgsql_development');
        $host = (string) ($dev['host'] ?? '127.0.0.1');
        $port = (string) ($dev['port'] ?? '5432');
        $username = (string) ($dev['username'] ?? '');
        $password = (string) ($dev['password'] ?? '');

        $auth = $username !== ''
            ? ($password !== '' ? rawurlencode($username).':'.rawurlencode($password).'@' : rawurlencode($username).'@')
            : '';
        $url = "pgsql://{$auth}{$host}:{$port}/{$developmentDatabase}";

        // Snapshot the original configuration so the test is fully hermetic and
        // cannot leak an overridden connection into any later test.
        $originalTestUrl = config('database.connections.pgsql_test.url');

        // Drive the TEST connection at the development database via its `url`.
        config(['database.connections.pgsql_test.url' => $url]);
        DB::purge('pgsql_test');

        try {
            // Precondition (read-only): the REAL resolver sees the target land on
            // the same physical database the development identity resolves to.
            $devResolved = (string) DB::connection('pgsql_development')->selectOne('select current_database() as db')->db;
            $targetResolved = (string) DB::connection('pgsql_test')->selectOne('select current_database() as db')->db;
            $this->assertSame($developmentDatabase, $devResolved, 'Precondition: the development identity resolves to the development database.');
            $this->assertSame($developmentDatabase, $targetResolved, 'Precondition: the URL override must land pgsql_test on the development database.');

            // Only the migrator is faked; the resolver stays real.
            $spy = new SpyTestSchemaMigrator;
            $this->app->instance(TestSchemaMigrator::class, $spy);

            $exit = Artisan::call('testing:prepare-postgres');
            $output = Artisan::output();
        } finally {
            // Fully restore: purge, put the original config back, purge again so
            // no rebuilt connection retains the test override.
            DB::purge('pgsql_test');
            config(['database.connections.pgsql_test.url' => $originalTestUrl]);
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
