<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Support\Facades\DB;

/**
 * Production resolver: asks the real connection what database it is on.
 *
 * For PostgreSQL this issues `SELECT current_database()` over the actual link,
 * so a connection whose `url` (PG_TEST_URL) or host/database was pointed at the
 * development database resolves to the development database name here — the
 * guard then refuses. For non-PostgreSQL connections it falls back to the
 * configured database name (enough for the guard to reject a non-pgsql target).
 */
final class LiveDatabaseNameResolver implements DatabaseNameResolver
{
    public function currentDatabase(string $connection): string
    {
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'pgsql') {
            $row = DB::connection($connection)->selectOne('select current_database() as db');

            return (string) ($row->db ?? '');
        }

        return (string) DB::connection($connection)->getDatabaseName();
    }
}
