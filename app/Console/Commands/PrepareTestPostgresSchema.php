<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Testing\PostgresTestConnectionGuard;
use App\Support\Testing\TestSchemaMigrator;
use Illuminate\Console\Command;

/**
 * The ONE official, fail-closed way to prepare the isolated PostgreSQL test
 * schema. It refuses — BEFORE running any migration — unless the target
 * resolves, over a real connection, to a database that is genuinely distinct
 * from the development database and carries a _test/_testing marker. This
 * closes the write-before-gate hole where a mis-pointed PG_TEST_DATABASE or
 * PG_TEST_URL could migrate the development database before the test-class
 * guard rejected the run.
 *
 * It never runs migrate:fresh/refresh/reset/rollback, never creates/drops/wipes
 * the development database, and never prints a password, DSN, or absolute path.
 */
final class PrepareTestPostgresSchema extends Command
{
    protected $signature = 'testing:prepare-postgres';

    protected $description = 'LOCAL/TESTING ONLY: fail-closed preflight, then forward-only migrate of the isolated pgsql_test schema.';

    public function handle(PostgresTestConnectionGuard $guard, TestSchemaMigrator $migrator): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: testing:prepare-postgres runs only in the local or testing environment.');

            return self::FAILURE;
        }

        $development = PostgresTestConnectionGuard::DEVELOPMENT_CONNECTION;
        $target = PostgresTestConnectionGuard::TEST_CONNECTION;

        // Both connections must be PostgreSQL (task: "potvrditi da su oba pgsql").
        if (config("database.connections.{$development}.driver") !== 'pgsql') {
            $this->error("Refused: the development connection [{$development}] is not a PostgreSQL connection.");

            return self::FAILURE;
        }

        // The guard resolves the LIVE database for both connections and fails
        // closed on every unsafe/unresolvable case (incl. a PG_TEST_URL that
        // secretly points at the development database).
        $result = $guard->assess($target, $development);

        if (! $result->safe) {
            $this->error('Refused: '.$result->reason);
            $this->line('No migration command was invoked.');

            return self::FAILURE;
        }

        // Safe: report only non-sensitive database NAMES (never a DSN/password/path).
        $this->info('Preflight passed — isolated PostgreSQL test schema.');
        $this->line("  development database : {$result->developmentDatabase}");
        $this->line("  test database        : {$result->targetDatabase}  (connection: {$target})");
        $this->line('  action               : forward-only migrate (never fresh/refresh/reset/rollback)');

        $exit = $migrator->migrate($target);

        if ($exit !== self::SUCCESS) {
            $this->error("Migration on [{$target}] exited with a non-zero code.");

            return self::FAILURE;
        }

        $this->info("Isolated test schema is ready on [{$target}].");

        return self::SUCCESS;
    }
}
