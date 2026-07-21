<?php

declare(strict_types=1);

namespace App\Support\Testing;

/**
 * Resolves the LIVE database a connection actually points at.
 *
 * This is a seam: the production implementation asks the real connection (for
 * PostgreSQL, `SELECT current_database()`), which is exactly what defeats a
 * PG_TEST_URL / DSN override — the URL is followed and the true database name
 * comes back. Tests inject a fake so the guard's decision logic can be proven
 * without connecting to any real PostgreSQL server.
 */
interface DatabaseNameResolver
{
    /**
     * The live database name for $connection.
     *
     * @throws \Throwable when the connection cannot be resolved.
     */
    public function currentDatabase(string $connection): string;
}
