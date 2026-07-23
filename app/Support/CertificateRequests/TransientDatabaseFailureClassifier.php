<?php

declare(strict_types=1);

namespace App\Support\CertificateRequests;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * The single closed classifier for "is this a safely retryable DATABASE failure?"
 * (M14 Phase B, P2-3, hardened).
 *
 * A transient lock/deadlock/serialization failure must never burn the terminal
 * `failed` state — it has to bubble up as a retry. But such a failure can be
 * WRAPPED: the registrar normalises its internal errors into a neutral
 * `CertificateRegistrationException` while keeping the original in the `previous`
 * chain. So classification walks the WHOLE chain (current → every getPrevious()).
 *
 * CLOSED, FAIL-CLOSED CONTRACT:
 *  - a SQLSTATE is only recognised from REAL DB exception context — a
 *    `QueryException` or `PDOException` (or one preserved in the chain). A generic
 *    throwable whose integer/string `code` merely LOOKS like a SQLSTATE is NEVER
 *    transient, e.g. `new RuntimeException('generic storage failure', 40001)`.
 *  - the SQLite "database is locked" wording is transient ONLY for a real
 *    `QueryException` whose driver is `sqlite` as reported by the ACTUAL resolved
 *    Laravel connection object (`ConnectionResolverInterface::connection()
 *    ->getDriverName()`) — never by reading `database.connections.*.driver` from
 *    config, which can DIVERGE from the connection that actually raised the error
 *    (a pgsql connection already resolved as pgsql stays pgsql even if config is
 *    later mutated to sqlite). The same message on a PostgreSQL (or any
 *    non-sqlite, unknown, or unresolvable) connection is NOT transient.
 *
 * Allow-list ONLY (everything else — generic DB, storage, OpenSSL, filesystem —
 * is permanent):
 *   40001  serialization_failure
 *   40P01  deadlock_detected
 *   55P03  lock_not_available
 *   + SQLite "database is locked" / "database table is locked" (sqlite driver only).
 *
 * A raw driver message is never returned or exposed; this only answers a boolean.
 */
final class TransientDatabaseFailureClassifier
{
    /** @var list<string> */
    private const RETRYABLE_SQLSTATES = ['40001', '40P01', '55P03'];

    /**
     * The REAL framework connection resolver (the DatabaseManager). Optional so the
     * default `new TransientDatabaseFailureClassifier` still works; when absent it
     * is lazily resolved from the container the single time a driver is needed.
     */
    private ?ConnectionResolverInterface $connections;

    public function __construct(?ConnectionResolverInterface $connections = null)
    {
        $this->connections = $connections;
    }

    public function isTransient(Throwable $failure): bool
    {
        for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
            if ($this->nodeIsTransient($current)) {
                return true;
            }
        }

        return false;
    }

    private function nodeIsTransient(Throwable $node): bool
    {
        // A SQLSTATE is meaningful ONLY inside a real DB exception. A generic
        // throwable is never transient no matter what its code looks like.
        if (! $node instanceof QueryException && ! $node instanceof PDOException) {
            return false;
        }

        $sqlState = $this->sqlState($node);
        if ($sqlState !== null && in_array($sqlState, self::RETRYABLE_SQLSTATES, true)) {
            return true;
        }

        // The SQLite-locked wording is trustworthy only from a real QueryException,
        // whose connection identity can be resolved to a concrete driver.
        return $node instanceof QueryException && $this->isSqliteLocked($node);
    }

    private function sqlState(QueryException|PDOException $e): ?string
    {
        $info = $e->errorInfo ?? null;
        if (is_array($info) && isset($info[0]) && is_string($info[0]) && $info[0] !== '') {
            return $info[0];
        }

        // Only a real DB exception reaches here, so its own code is a legitimate
        // SQLSTATE source (e.g. PDOException::getCode() returns the SQLSTATE).
        $code = (string) $e->getCode();

        return $code === '' ? null : $code;
    }

    /**
     * SQLite "database is locked" is transient ONLY for a real QueryException whose
     * driver is sqlite as reported by the ACTUAL resolved connection object. A
     * PDOException alone carries no trustworthy driver identity, and the same
     * wording on a non-sqlite connection is a genuine, permanent failure.
     *
     * The driver is resolved lazily and ONLY when the wording actually matches, so
     * an unrelated permanent QueryException (e.g. 23505) never triggers a needless
     * connection lookup.
     */
    private function isSqliteLocked(QueryException $e): bool
    {
        $info = $e->errorInfo ?? null;
        $driverMessage = is_array($info) && isset($info[2]) && is_string($info[2])
            ? $info[2]
            : $e->getMessage();

        $driverMessage = strtolower($driverMessage);

        if (! str_contains($driverMessage, 'database is locked')
            && ! str_contains($driverMessage, 'database table is locked')) {
            return false;
        }

        // Only NOW, once the wording matches, ask the REAL connection object for its
        // driver. Config is never the authority.
        return $this->driverFor($e->getConnectionName()) === 'sqlite';
    }

    /**
     * The driver of the ACTUAL resolved Laravel connection object — never config.
     * Fails closed (null) on a null/empty/unknown/unresolvable connection or any
     * resolver error; no resolver exception is ever surfaced.
     */
    private function driverFor(?string $connectionName): ?string
    {
        if ($connectionName === null || $connectionName === '') {
            return null;
        }

        try {
            return $this->connections()->connection($connectionName)->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    private function connections(): ConnectionResolverInterface
    {
        return $this->connections ??= app('db');
    }
}
