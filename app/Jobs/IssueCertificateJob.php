<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\CertificateRequests\IssuanceQueueContract;
use App\Services\CertificateRequests\CertificateIssuanceProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * M14 certificate-issuance worker job.
 *
 * PAYLOAD BOUNDARY — the serialized payload carries ONLY two scalars:
 *   - certificate_request_id
 *   - issuance_attempt_id
 *
 * Never an Eloquent model, a relation graph, the request or operator note, a
 * user/operator identity, a filesystem path, or any secret. The worker re-reads
 * everything it needs from the database under a lock, so a stale or tampered
 * payload cannot widen its own authority.
 *
 * The lifecycle itself lives in {@see CertificateIssuanceProcessor}: this class
 * is only the queue envelope. A PERMANENT failure is neutralised and persisted by
 * the processor (the job does not rethrow it), while a TRANSIENT lock/deadlock is
 * rethrown so Laravel retries the SAME attempt. Only when retries are exhausted
 * does {@see failed()} record the terminal ISSUANCE_RETRIES_EXHAUSTED state.
 */
class IssueCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A retry stays bound to the same approval via the attempt id below. */
    public int $tries = 3;

    public function __construct(
        public readonly int $certificateRequestId,
        public readonly string $issuanceAttemptId,
    ) {
        // Pin BOTH the connection and the queue explicitly: the M14 job must ride
        // the atomic `database` queue connection (the same one the approval
        // transaction writes on) regardless of the global queue.default, so a
        // stray default can never redirect issuance onto sync or a broker.
        $this->onConnection(IssuanceQueueContract::QUEUE_CONNECTION);
        $this->onQueue(IssuanceQueueContract::QUEUE_NAME);
    }

    public function handle(CertificateIssuanceProcessor $processor): void
    {
        $processor->process($this->certificateRequestId, $this->issuanceAttemptId);
    }

    /**
     * Fires only after the transient-retry budget is exhausted (a permanent
     * failure is already persisted by the processor and never rethrown). The raw
     * $exception is deliberately ignored — nothing about it is stored or logged.
     */
    public function failed(?Throwable $exception): void
    {
        app(CertificateIssuanceProcessor::class)
            ->markRetriesExhausted($this->certificateRequestId, $this->issuanceAttemptId);
    }
}
