<?php

declare(strict_types=1);

namespace Tests\Support\Testing;

use App\Support\Testing\DatabaseNameResolver;
use RuntimeException;
use Throwable;

/**
 * Scriptable resolver so the guard/command can be proven without a real
 * PostgreSQL server. Map a connection name to the database name it should
 * resolve to, or to a Throwable to simulate an unresolvable connection.
 */
final class FakeDatabaseNameResolver implements DatabaseNameResolver
{
    /** @var list<string> Connections this resolver was actually asked about. */
    private array $asked = [];

    /** @param array<string, string|Throwable> $map */
    public function __construct(private array $map) {}

    /**
     * Connection names this resolver was asked to resolve, in call order.
     * Lets a caller-level test prove WHICH connections the caller handed over.
     *
     * @return list<string>
     */
    public function askedConnections(): array
    {
        return $this->asked;
    }

    public function currentDatabase(string $connection): string
    {
        $this->asked[] = $connection;

        if (! array_key_exists($connection, $this->map)) {
            throw new RuntimeException("No fake database name mapped for connection [{$connection}].");
        }

        $value = $this->map[$connection];

        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value;
    }
}
