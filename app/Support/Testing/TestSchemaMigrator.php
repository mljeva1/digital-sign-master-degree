<?php

declare(strict_types=1);

namespace App\Support\Testing;

/**
 * Seam for the ONLY migration the prepare command is allowed to run: a
 * forward-only `migrate` against the isolated test connection. Isolating it
 * behind an interface lets tests prove the migration is NEVER invoked when the
 * preflight refuses — without touching any real database.
 */
interface TestSchemaMigrator
{
    /**
     * Run forward-only migrations against $connection. Returns the process exit
     * code (0 on success). Implementations must never run migrate:fresh /
     * refresh / reset / rollback, and never target the development database.
     */
    public function migrate(string $connection): int;
}
