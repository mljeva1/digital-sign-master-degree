<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Throwable;

/**
 * Single, reusable fail-closed rule for routing PostgreSQL schema work at an
 * ISOLATED test database — never the development database.
 *
 * The security rule lives here once and is shared by both callers so it cannot
 * drift between them:
 *  - the `testing:prepare-postgres` command (preflight BEFORE any migration);
 *  - the SignatureSourceBindingSchemaTest opt-in gate.
 *
 * Every decision is driven by the LIVE database each connection actually points
 * at (resolved through {@see DatabaseNameResolver}), so a PG_TEST_URL / DSN
 * override that secretly targets the development database is caught here and
 * refused — before, not after, a write.
 */
final class PostgresTestConnectionGuard
{
    /** @var non-empty-string */
    public const TEST_CONNECTION = 'pgsql_test';

    /**
     * The development identity is the EXPLICIT, dedicated `pgsql_development`
     * connection — never the raw runtime `pgsql` connection and never
     * `config('database.default')` (which under phpunit.xml is SQLite :memory:).
     * Comparing against a non-PostgreSQL or ambiguous default could never prove
     * isolation between two real PostgreSQL databases.
     *
     * @var non-empty-string
     */
    public const DEVELOPMENT_CONNECTION = 'pgsql_development';

    /** The raw runtime PostgreSQL connection — never a valid isolated test target. */
    private const RUNTIME_CONNECTION = 'pgsql';

    public const ACTION_SKIP = 'skip';

    public const ACTION_RUN = 'run';

    public const ACTION_FAIL = 'fail';

    public function __construct(private readonly DatabaseNameResolver $resolver) {}

    /**
     * Assess whether $targetConnection is a safe, isolated PostgreSQL test
     * database distinct from $developmentConnection's live database.
     */
    public function assess(string $targetConnection, string $developmentConnection): PostgresGuardResult
    {
        if ($targetConnection === '') {
            return PostgresGuardResult::unsafe('No test connection was named.');
        }

        if ($targetConnection === $developmentConnection
            || $targetConnection === self::DEVELOPMENT_CONNECTION
            || $targetConnection === self::RUNTIME_CONNECTION) {
            return PostgresGuardResult::unsafe(
                "Target connection [{$targetConnection}] must be a dedicated test connection, never the development identity \"".self::DEVELOPMENT_CONNECTION.'" or the runtime "'.self::RUNTIME_CONNECTION.'".'
            );
        }

        $connections = config('database.connections');
        if (! is_array($connections) || ! array_key_exists($targetConnection, $connections)) {
            return PostgresGuardResult::unsafe("Connection [{$targetConnection}] is not configured.");
        }

        if (($connections[$targetConnection]['driver'] ?? null) !== 'pgsql') {
            return PostgresGuardResult::unsafe("Connection [{$targetConnection}] is not a PostgreSQL (pgsql) connection.");
        }

        try {
            $targetDatabase = $this->resolver->currentDatabase($targetConnection);
        } catch (Throwable) {
            return PostgresGuardResult::unsafe("Could not resolve the live database for [{$targetConnection}].");
        }

        if ($targetDatabase === '') {
            return PostgresGuardResult::unsafe("Resolved an empty database name for [{$targetConnection}].");
        }

        // The development side must ALSO be a real, configured PostgreSQL
        // connection. Otherwise the equality check below would compare a
        // PostgreSQL database against something that is not one (e.g. the
        // PHPUnit SQLite :memory: default) and would never prove isolation
        // between two real PostgreSQL databases.
        if ($developmentConnection === '') {
            return PostgresGuardResult::unsafe('No development connection was named.');
        }

        if (! array_key_exists($developmentConnection, $connections)) {
            return PostgresGuardResult::unsafe("Development connection [{$developmentConnection}] is not configured.");
        }

        if (($connections[$developmentConnection]['driver'] ?? null) !== 'pgsql') {
            return PostgresGuardResult::unsafe("Development connection [{$developmentConnection}] is not a PostgreSQL (pgsql) connection.");
        }

        try {
            $developmentDatabase = $this->resolver->currentDatabase($developmentConnection);
        } catch (Throwable) {
            return PostgresGuardResult::unsafe("Could not resolve the live database for the development connection [{$developmentConnection}].");
        }

        if ($developmentDatabase === '') {
            return PostgresGuardResult::unsafe("Resolved an empty database name for the development connection [{$developmentConnection}].");
        }

        // The decisive check: whatever the config or URL says, the target must
        // NOT resolve to the same live database as development.
        if ($targetDatabase === $developmentDatabase) {
            return PostgresGuardResult::unsafe('Refusing: the test connection resolves to the development database.');
        }

        // Strictly anchored: the database name must END with _test or _testing.
        // A marker anywhere else (production-test, production_testing_backup,
        // my_test_database) is refused.
        if (preg_match('/(?:_test|_testing)$/i', $targetDatabase) !== 1) {
            return PostgresGuardResult::unsafe('Refusing: the test database name must end with a clear _test or _testing marker.');
        }

        return PostgresGuardResult::safe($targetDatabase, $developmentDatabase);
    }

    /**
     * Translate opt-in state + safety into a gate action for a test suite:
     *  - opt-in OFF                    → skip (precise, expected, not a failure);
     *  - opt-in ON + safe config       → run;
     *  - opt-in ON + unsafe config     → fail (never a silent skip / false green).
     *
     * @return array{action:string, reason:?string, result:?PostgresGuardResult}
     */
    public function gateDecision(bool $optIn, string $targetConnection, string $developmentConnection): array
    {
        if (! $optIn) {
            return [
                'action' => self::ACTION_SKIP,
                'reason' => 'PostgreSQL schema proofs are opt-in: set DB_PG_TEST_ENABLED=true with an isolated, migrated pgsql_test connection.',
                'result' => null,
            ];
        }

        $result = $this->assess($targetConnection, $developmentConnection);

        return [
            'action' => $result->safe ? self::ACTION_RUN : self::ACTION_FAIL,
            'reason' => $result->reason,
            'result' => $result,
        ];
    }
}
