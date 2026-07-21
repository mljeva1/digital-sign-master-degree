<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * Production migrator: forward-only `migrate --force` against the given
 * connection, via the console kernel. Never fresh/refresh/reset/rollback.
 */
final class ArtisanTestSchemaMigrator implements TestSchemaMigrator
{
    public function __construct(private readonly ConsoleKernel $kernel) {}

    public function migrate(string $connection): int
    {
        return $this->kernel->call('migrate', [
            '--database' => $connection,
            '--force' => true,
        ]);
    }
}
