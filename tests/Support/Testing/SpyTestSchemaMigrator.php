<?php

declare(strict_types=1);

namespace Tests\Support\Testing;

use App\Support\Testing\TestSchemaMigrator;

/**
 * Records every migrate() call so a test can prove the migration was — or, for
 * a refused preflight, was NOT — invoked. Never touches a real database.
 */
final class SpyTestSchemaMigrator implements TestSchemaMigrator
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(public int $exitCode = 0) {}

    public function migrate(string $connection): int
    {
        $this->calls[] = $connection;

        return $this->exitCode;
    }

    public function wasCalled(): bool
    {
        return $this->calls !== [];
    }
}
