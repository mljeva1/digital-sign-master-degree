<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use App\Support\Testing\DatabaseNameResolver;
use App\Support\Testing\TestSchemaMigrator;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Testing\FakeDatabaseNameResolver;
use Tests\Support\Testing\SpyTestSchemaMigrator;
use Tests\TestCase;

/**
 * Proves the fail-closed preflight of testing:prepare-postgres. Every case runs
 * against a scripted resolver and a spy migrator, so no real PostgreSQL is
 * touched and — crucially — the spy proves the migration is NOT invoked
 * whenever the preflight refuses (there is no write-before-gate window).
 */
final class PrepareTestPostgresSchemaCommandTest extends TestCase
{
    private const DEV_DB = 'digital_sign_master_degree';

    private const TEST_DB = 'digital_sign_master_degree_test';

    /** @param array<string, mixed> $resolverMap */
    private function bind(array $resolverMap, SpyTestSchemaMigrator $spy): void
    {
        $this->app->instance(DatabaseNameResolver::class, new FakeDatabaseNameResolver($resolverMap));
        $this->app->instance(TestSchemaMigrator::class, $spy);
    }

    public function test_safe_configuration_passes_preflight_and_migrates_the_test_connection(): void
    {
        $spy = new SpyTestSchemaMigrator;
        $this->bind(['pgsql_development' => self::DEV_DB, 'pgsql_test' => self::TEST_DB], $spy);

        $exit = Artisan::call('testing:prepare-postgres');

        $this->assertSame(0, $exit);
        $this->assertSame(['pgsql_test'], $spy->calls);
    }

    public function test_target_equal_to_development_database_fails_before_migration(): void
    {
        $spy = new SpyTestSchemaMigrator;
        $this->bind(['pgsql_development' => self::DEV_DB, 'pgsql_test' => self::DEV_DB], $spy);

        $exit = Artisan::call('testing:prepare-postgres');
        $output = Artisan::output();

        $this->assertNotSame(0, $exit);
        $this->assertFalse($spy->wasCalled(), 'Migration must not run when target equals development.');
        // Precise, honest failure wording: it states what actually happened
        // (no migration command was invoked), not an unprovable claim about the
        // development database being untouched.
        $this->assertStringContainsString('No migration command was invoked.', $output);
        $this->assertStringNotContainsString('development database was not touched', $output);
    }

    public function test_target_without_test_marker_fails_before_migration(): void
    {
        $spy = new SpyTestSchemaMigrator;
        $this->bind(['pgsql_development' => self::DEV_DB, 'pgsql_test' => 'production_like_db'], $spy);

        $exit = Artisan::call('testing:prepare-postgres');

        $this->assertNotSame(0, $exit);
        $this->assertFalse($spy->wasCalled());
    }

    public function test_pg_test_url_that_resolves_to_development_fails_before_migration(): void
    {
        // A PG_TEST_URL / DSN override pointing at dev is caught because the LIVE
        // current_database() of the test connection comes back as the dev name.
        $spy = new SpyTestSchemaMigrator;
        $this->bind(['pgsql_development' => self::DEV_DB, 'pgsql_test' => self::DEV_DB], $spy);

        $exit = Artisan::call('testing:prepare-postgres');

        $this->assertNotSame(0, $exit);
        $this->assertFalse($spy->wasCalled());
    }

    public function test_non_postgres_target_fails_before_migration(): void
    {
        config(['database.connections.pgsql_test.driver' => 'mysql']);

        $spy = new SpyTestSchemaMigrator;
        $this->bind(['pgsql_development' => self::DEV_DB, 'pgsql_test' => self::TEST_DB], $spy);

        $exit = Artisan::call('testing:prepare-postgres');

        $this->assertNotSame(0, $exit);
        $this->assertFalse($spy->wasCalled());
    }

    public function test_unresolvable_target_fails_before_migration(): void
    {
        $spy = new SpyTestSchemaMigrator;
        $this->bind([
            'pgsql_development' => self::DEV_DB,
            'pgsql_test' => new \RuntimeException('could not connect'),
        ], $spy);

        $exit = Artisan::call('testing:prepare-postgres');

        $this->assertNotSame(0, $exit);
        $this->assertFalse($spy->wasCalled());
    }

    public function test_production_environment_is_refused_before_migration(): void
    {
        $this->app['env'] = 'production';

        $spy = new SpyTestSchemaMigrator;
        $this->bind(['pgsql_development' => self::DEV_DB, 'pgsql_test' => self::TEST_DB], $spy);

        $exit = Artisan::call('testing:prepare-postgres');

        $this->assertNotSame(0, $exit);
        $this->assertFalse($spy->wasCalled());

        $this->app['env'] = 'testing';
    }

    public function test_output_does_not_leak_password_dsn_username_or_absolute_path(): void
    {
        config([
            'database.connections.pgsql_test.password' => 'super-secret-pw',
            'database.connections.pgsql_test.username' => 'local_pg_user',
            'database.connections.pgsql_development.password' => 'super-secret-pw',
        ]);

        $spy = new SpyTestSchemaMigrator;
        $this->bind(['pgsql_development' => self::DEV_DB, 'pgsql_test' => self::TEST_DB], $spy);

        Artisan::call('testing:prepare-postgres');
        $output = Artisan::output();

        $this->assertStringNotContainsString('super-secret-pw', $output);
        $this->assertStringNotContainsString('local_pg_user', $output);
        $this->assertStringNotContainsStringIgnoringCase('password', $output);
        $this->assertStringNotContainsString('://', $output);
        $this->assertStringNotContainsString('C:\\', $output);
        $this->assertStringNotContainsString('/home/', $output);
        // It SHOULD name the safe database identifiers.
        $this->assertStringContainsString(self::TEST_DB, $output);
    }
}
