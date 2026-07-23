<?php

declare(strict_types=1);

namespace App\Domain\CertificateRequests;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fail-closed proof that approval and its issuance job commit or roll back as ONE
 * atomic unit.
 *
 * The approval transaction writes the request status, the operator binding and
 * the approved audit event. The issuance job row must join that same unit of
 * work, so a rolled-back approval can never leave an orphan job and a committed
 * approval can never be left without one.
 *
 * That only holds when ALL of the following are true, so each is checked
 * explicitly rather than inferred from a driver name or a config string:
 *
 *   1. the queue connection uses the `database` driver (a real jobs table);
 *   2. its underlying DB connection is the SAME Laravel connection the domain
 *      transaction writes on — the same connection means the same PDO handle and
 *      therefore the same open transaction;
 *   3. both resolve to the same physical database;
 *   4. `after_commit` is NOT enabled for this path — after_commit would defer the
 *      insert until AFTER the business commit, which is exactly the atomicity
 *      break this guard exists to prevent.
 *
 * A worker never sees the job early: it polls on its own separate DB session and
 * cannot read uncommitted rows.
 */
final class IssuanceQueueContract
{
    public const QUEUE_NAME = 'certificate-issuance';

    /**
     * The exact queue connection the M14 job is pinned to. The contract inspects
     * THIS connection specifically — never `queue.default` — so it proves the
     * connection the job actually rides, and a database-driver `queue.default`
     * pointing elsewhere can neither satisfy nor redirect it.
     */
    public const QUEUE_CONNECTION = 'database';

    /**
     * @return array{queue_connection:string, database_connection:string, queue:string}
     *
     * @throws CertificateRequestWorkflowException when the atomic contract cannot be proven
     */
    public static function assertAtomic(string $domainConnection): array
    {
        // The job pins onConnection(QUEUE_CONNECTION); assert THAT connection is
        // atomic — not whatever queue.default happens to be.
        $queueConnection = self::QUEUE_CONNECTION;

        $queueConfig = config('queue.connections.'.$queueConnection);
        if (! is_array($queueConfig)) {
            self::refuse();
        }

        // 1. Must be exactly the real database queue — never sync, never a broker.
        if (($queueConfig['driver'] ?? null) !== 'database') {
            self::refuse();
        }

        // 4. No post-commit deferral on this path.
        if (! empty($queueConfig['after_commit'])) {
            self::refuse();
        }

        // 2. Same Laravel connection as the domain write. A null `connection`
        //    means "the default connection"; it is resolved explicitly here so
        //    the contract never depends on implicit inheritance.
        $queueDatabaseConnection = $queueConfig['connection'] ?? config('database.default');
        if (! is_string($queueDatabaseConnection) || $queueDatabaseConnection === '') {
            self::refuse();
        }

        if ($queueDatabaseConnection !== $domainConnection) {
            self::refuse();
        }

        // 3. Same physical database (defence in depth: a same-named connection
        //    that could not be resolved is refused rather than assumed).
        try {
            $queueDatabase = DB::connection($queueDatabaseConnection)->getDatabaseName();
            $domainDatabase = DB::connection($domainConnection)->getDatabaseName();
        } catch (Throwable) {
            self::refuse();
        }

        if ($queueDatabase === '' || $queueDatabase !== $domainDatabase) {
            self::refuse();
        }

        return [
            'queue_connection' => $queueConnection,
            'database_connection' => $queueDatabaseConnection,
            'queue' => self::QUEUE_NAME,
        ];
    }

    private static function refuse(): never
    {
        throw CertificateRequestWorkflowException::of(
            CertificateRequestWorkflowException::QUEUE_CONTRACT_UNSAFE
        );
    }
}
