<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use App\Support\Testing\PostgresTestConnectionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Testing\FakeDatabaseNameResolver;
use Tests\TestCase;

/**
 * Proves the shared fail-closed rule that both the testing:prepare-postgres
 * command and the SignatureSourceBindingSchemaTest gate rely on. Fully
 * hermetic: a scripted resolver stands in for real current_database() lookups,
 * so no PostgreSQL server is needed and no database is ever migrated.
 */
final class PostgresTestConnectionGuardTest extends TestCase
{
    private const DEV_DB = 'digital_sign_master_degree';

    private const TEST_DB = 'digital_sign_master_degree_test';

    private function guard(array $map): PostgresTestConnectionGuard
    {
        return new PostgresTestConnectionGuard(new FakeDatabaseNameResolver($map));
    }

    public function test_assess_accepts_a_distinct_marked_isolated_test_database(): void
    {
        $result = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => self::TEST_DB,
        ])->assess('pgsql_test', 'pgsql');

        $this->assertTrue($result->safe);
        $this->assertSame(self::TEST_DB, $result->targetDatabase);
        $this->assertSame(self::DEV_DB, $result->developmentDatabase);
        $this->assertNull($result->reason);
    }

    public function test_assess_refuses_when_target_resolves_to_the_development_database(): void
    {
        // Models a PG_TEST_DATABASE / PG_TEST_URL that secretly points at dev:
        // the live current_database() of the test connection equals dev's.
        $result = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => self::DEV_DB,
        ])->assess('pgsql_test', 'pgsql');

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('resolves to the development database', (string) $result->reason);
    }

    public function test_assess_refuses_a_target_without_a_test_marker(): void
    {
        $result = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => 'some_production_like_db',
        ])->assess('pgsql_test', 'pgsql');

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('_test or _testing marker', (string) $result->reason);
    }

    /**
     * The marker must be strictly anchored to the END of the database name and
     * use an underscore: only *_test or *_testing pass.
     *
     * @return array<string, array{0:string, 1:bool}>
     */
    public static function markerCases(): array
    {
        return [
            'ends with _test' => ['digital_sign_master_degree_test', true],
            'ends with _testing' => ['digital_sign_master_degree_testing', true],
            'hyphen -test not accepted' => ['production-test', false],
            'testing not at end' => ['production_testing_backup', false],
            'marker in the middle' => ['my_test_database', false],
            'no marker at all' => ['production_database', false],
        ];
    }

    #[DataProvider('markerCases')]
    public function test_assess_only_accepts_a_name_ending_in_underscore_test_or_testing(string $targetDatabase, bool $expectedSafe): void
    {
        $result = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => $targetDatabase,
        ])->assess('pgsql_test', 'pgsql');

        $this->assertSame($expectedSafe, $result->safe, "Marker decision for [{$targetDatabase}] was wrong.");

        if (! $expectedSafe) {
            $this->assertStringContainsString('_test or _testing marker', (string) $result->reason);
        }
    }

    public function test_assess_refuses_a_non_postgres_target(): void
    {
        config(['database.connections.pgsql_test.driver' => 'mysql']);

        $result = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => self::TEST_DB,
        ])->assess('pgsql_test', 'pgsql');

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('not a PostgreSQL', (string) $result->reason);
    }

    public function test_assess_refuses_when_the_target_connection_is_not_configured(): void
    {
        $result = $this->guard([
            'pgsql' => self::DEV_DB,
        ])->assess('pgsql_absent', 'pgsql');

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('is not configured', (string) $result->reason);
    }

    public function test_assess_refuses_the_development_or_shared_pgsql_connection_as_target(): void
    {
        $guard = $this->guard(['pgsql' => self::DEV_DB, 'pgsql_test' => self::TEST_DB]);

        $this->assertFalse($guard->assess('pgsql', 'pgsql')->safe);
        $this->assertFalse($guard->assess('pgsql', 'sqlite')->safe);
    }

    public function test_assess_refuses_when_the_target_cannot_be_resolved(): void
    {
        $result = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => new \RuntimeException('connection refused'),
        ])->assess('pgsql_test', 'pgsql');

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('Could not resolve', (string) $result->reason);
    }

    public function test_assess_refuses_when_development_cannot_be_resolved(): void
    {
        $result = $this->guard([
            'pgsql' => new \RuntimeException('connection refused'),
            'pgsql_test' => self::TEST_DB,
        ])->assess('pgsql_test', 'pgsql');

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('development connection', (string) $result->reason);
    }

    public function test_assess_never_leaks_a_password_dsn_or_path_in_its_reason(): void
    {
        $result = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => self::DEV_DB,
        ])->assess('pgsql_test', 'pgsql');

        $reason = (string) $result->reason;
        $this->assertStringNotContainsStringIgnoringCase('password', $reason);
        $this->assertStringNotContainsString('://', $reason);
        $this->assertStringNotContainsString('C:\\', $reason);
        $this->assertStringNotContainsString('/home/', $reason);
    }

    // --- gateDecision: the three distinct outcomes ------------------------

    public function test_gate_decision_skips_when_opt_in_is_disabled(): void
    {
        $decision = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => self::TEST_DB,
        ])->gateDecision(false, 'pgsql_test', 'pgsql');

        $this->assertSame(PostgresTestConnectionGuard::ACTION_SKIP, $decision['action']);
    }

    public function test_gate_decision_runs_when_opt_in_and_config_is_safe(): void
    {
        $decision = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => self::TEST_DB,
        ])->gateDecision(true, 'pgsql_test', 'pgsql');

        $this->assertSame(PostgresTestConnectionGuard::ACTION_RUN, $decision['action']);
    }

    public function test_gate_decision_fails_when_opt_in_but_config_is_unsafe(): void
    {
        // opt-in ON but the test connection resolves to dev → must FAIL, never skip.
        $decision = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_test' => self::DEV_DB,
        ])->gateDecision(true, 'pgsql_test', 'pgsql');

        $this->assertSame(PostgresTestConnectionGuard::ACTION_FAIL, $decision['action']);
        $this->assertNotNull($decision['reason']);
    }

    // --- P2-5: the development identity is the explicit pgsql_development ------

    /**
     * MUTATION-SENSITIVE: fails if the development identity ever reverts to the
     * raw runtime `pgsql` connection or to `database.default`.
     */
    public function test_development_identity_is_the_explicit_pgsql_development_connection(): void
    {
        $this->assertSame('pgsql_development', PostgresTestConnectionGuard::DEVELOPMENT_CONNECTION);
        $this->assertNotSame('pgsql', PostgresTestConnectionGuard::DEVELOPMENT_CONNECTION);
        $this->assertNotSame(config('database.default'), PostgresTestConnectionGuard::DEVELOPMENT_CONNECTION);
    }

    public function test_target_may_never_be_the_dev_identity_or_the_runtime_connection(): void
    {
        $guard = $this->guard([
            'pgsql' => self::DEV_DB,
            'pgsql_development' => self::DEV_DB,
            'pgsql_test' => self::TEST_DB,
        ]);

        // The dedicated development identity can never be the isolated target.
        $this->assertFalse($guard->assess('pgsql_development', 'pgsql_development')->safe);
        // The raw runtime connection can never be the isolated target either.
        $this->assertFalse($guard->assess('pgsql', 'pgsql_development')->safe);
    }

    public function test_assess_isolates_against_the_explicit_development_identity(): void
    {
        // With pgsql_development as the dev identity, a distinct marked target runs.
        $result = $this->guard([
            'pgsql_development' => self::DEV_DB,
            'pgsql_test' => self::TEST_DB,
        ])->assess('pgsql_test', PostgresTestConnectionGuard::DEVELOPMENT_CONNECTION);

        $this->assertTrue($result->safe);
        $this->assertSame(self::DEV_DB, $result->developmentDatabase);
    }
}
