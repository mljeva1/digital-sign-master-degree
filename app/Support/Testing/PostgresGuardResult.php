<?php

declare(strict_types=1);

namespace App\Support\Testing;

/**
 * Outcome of assessing whether a target connection is a safe, isolated
 * PostgreSQL test database. Carries only non-sensitive database NAMES (never a
 * DSN, password, host, or path).
 */
final readonly class PostgresGuardResult
{
    private function __construct(
        public bool $safe,
        public ?string $reason,
        public ?string $targetDatabase,
        public ?string $developmentDatabase,
    ) {}

    public static function safe(string $targetDatabase, string $developmentDatabase): self
    {
        return new self(true, null, $targetDatabase, $developmentDatabase);
    }

    public static function unsafe(string $reason): self
    {
        return new self(false, $reason, null, null);
    }
}
