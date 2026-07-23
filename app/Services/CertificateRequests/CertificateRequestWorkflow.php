<?php

declare(strict_types=1);

namespace App\Services\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Domain\CertificateRequests\IssuanceQueueContract;
use App\Jobs\IssueCertificateJob;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single authoritative M14 certificate-request workflow.
 *
 * Every state change goes through here — controllers stay thin and never decide
 * a transition, never resolve an actor from the request payload and never write
 * an audit event themselves.
 *
 * LOCK ORDER (documented, consistent, and the same everywhere):
 *  - owner paths (create/cancel): User(subject) → CertificateRequest;
 *  - review paths (approve/reject): both participating users locked in
 *    ASCENDING numeric id order → CertificateRequest → the exact role_user pivot
 *    FOR UPDATE, after which operator membership is RE-PROVEN.
 *
 * Ascending-id user locking (not semantic "subject then operator") is what makes
 * two concurrent reviews deadlock-free, and locking the pivot is what serializes
 * a review against a concurrent role revoke. See CertificateOperatorAuthority.
 *
 * Every operation re-reads its rows under lockForUpdate inside the transaction:
 * a caller-supplied model is never trusted as proof of current state.
 */
class CertificateRequestWorkflow
{
    public const AUDIT_CREATED = 'certificate.request.created';

    public const AUDIT_CANCELLED = 'certificate.request.cancelled';

    public const AUDIT_APPROVED = 'certificate.request.approved';

    public const AUDIT_REJECTED = 'certificate.request.rejected';

    /** Kept as the public name; the locking rule lives in CertificateOperatorAuthority. */
    public const OPERATOR_ROLE = CertificateOperatorAuthority::OPERATOR_ROLE;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CertificateOperatorAuthority $authority = new CertificateOperatorAuthority,
    ) {}

    /**
     * Test seam: invoked AFTER the operator membership lock is held and BEFORE
     * any state write. Inert in production (no-op); the concurrency test uses it
     * to hold the lock while a competing revoke contends for it. It cannot
     * weaken the locking path because it runs strictly inside it.
     */
    protected function afterOperatorAuthorityLocked(int $requestId, int $operatorId): void
    {
        // no-op
    }

    /**
     * Submit a request for the authenticated subject only.
     *
     * Blocked by another ACTIVE request (pending/approved/issuing) or by an
     * active, not-yet-expired certificate. An expired or deactivated certificate
     * deliberately does NOT block a new request.
     */
    public function create(User $subject, ?string $requestNote = null): CertificateRequest
    {
        $subjectId = (int) $subject->getKey();

        return $this->transaction(function () use ($subjectId, $requestNote): CertificateRequest {
            $this->lockSubjectOrFail($subjectId);
            $this->assertNoActiveRequest($subjectId);
            $this->assertNoActiveCertificate($subjectId);

            $request = new CertificateRequest;
            $request->user_id = $subjectId;
            $request->status = Status::PENDING;
            $request->request_note = $this->normalizeNote($requestNote);
            $request->save();

            $this->recordAudit(self::AUDIT_CREATED, $request, $subjectId, [
                'operation_name' => 'create',
                'old_status' => null,
                'new_status' => Status::PENDING,
            ]);

            return $request;
        });
    }

    /** The subject cancels their own still-pending request. */
    public function cancel(CertificateRequest $request, User $actor): CertificateRequest
    {
        $requestId = (int) $request->getKey();
        $actorId = (int) $actor->getKey();

        return $this->transaction(function () use ($requestId, $actorId): CertificateRequest {
            $fresh = $this->lockRequestForSubject($requestId);

            if ((int) $fresh->user_id !== $actorId) {
                throw WorkflowException::of(WorkflowException::OPERATOR_NOT_AUTHORIZED);
            }

            $this->assertPending($fresh);
            Status::assertTransition((string) $fresh->status, Status::CANCELLED);

            $fresh->status = Status::CANCELLED;
            $fresh->cancelled_at = now();
            $fresh->save();

            $this->recordAudit(self::AUDIT_CANCELLED, $fresh, $actorId, [
                'operation_name' => 'cancel',
                'old_status' => Status::PENDING,
                'new_status' => Status::CANCELLED,
            ]);

            return $fresh;
        });
    }

    /** An operator rejects someone else's pending request; a reason is required. */
    public function reject(CertificateRequest $request, User $operator, string $operatorNote): CertificateRequest
    {
        $requestId = (int) $request->getKey();
        $operatorId = (int) $operator->getKey();
        $note = $this->normalizeNote($operatorNote);

        if ($note === null) {
            throw WorkflowException::of(WorkflowException::OPERATOR_NOTE_REQUIRED);
        }

        return $this->transaction(function () use ($requestId, $operatorId, $note): CertificateRequest {
            $fresh = $this->lockForReview($requestId, $operatorId);
            $this->assertPending($fresh);
            Status::assertTransition((string) $fresh->status, Status::REJECTED);

            $fresh->status = Status::REJECTED;
            $fresh->reviewed_by_user_id = $operatorId;
            $fresh->reviewed_at = now();
            $fresh->operator_note = $note;
            $fresh->save();

            $this->recordAudit(self::AUDIT_REJECTED, $fresh, $operatorId, [
                'operation_name' => 'reject',
                'old_status' => Status::PENDING,
                'new_status' => Status::REJECTED,
                'operator_user_id' => $operatorId,
                'operator_note_provided' => true,
            ]);

            return $fresh;
        });
    }

    /**
     * Approve a pending request AND enqueue its issuance job atomically.
     *
     * The status change, operator binding, attempt reservation, approved audit
     * event and the single jobs row all commit together or not at all. The queue
     * contract is proven BEFORE any state changes, so an unsafe queue setup can
     * never leave a request approved without a job.
     */
    public function approve(CertificateRequest $request, User $operator): CertificateRequest
    {
        $requestId = (int) $request->getKey();
        $operatorId = (int) $operator->getKey();
        $connection = $this->connectionName();

        // Fail closed before touching any row: proven atomic queue path only.
        IssuanceQueueContract::assertAtomic($connection);

        return $this->transaction(function () use ($requestId, $operatorId): CertificateRequest {
            $fresh = $this->lockForReview($requestId, $operatorId);
            $this->assertPending($fresh);
            Status::assertTransition((string) $fresh->status, Status::APPROVED);

            // Re-proven under the lock: the subject may have acquired a
            // certificate between submission and review.
            $this->assertNoActiveCertificate((int) $fresh->user_id);

            $attemptId = (string) Str::uuid();
            $now = now();

            $fresh->status = Status::APPROVED;
            $fresh->reviewed_by_user_id = $operatorId;
            $fresh->reviewed_at = $now;
            $fresh->approved_at = $now;
            $fresh->issuance_attempt_id = $attemptId;
            $fresh->save();

            $this->recordAudit(self::AUDIT_APPROVED, $fresh, $operatorId, [
                'operation_name' => 'approve',
                'old_status' => Status::PENDING,
                'new_status' => Status::APPROVED,
                'operator_user_id' => $operatorId,
            ]);

            // Still INSIDE the transaction: the jobs row joins this unit of work.
            $this->dispatchIssuanceJob((int) $fresh->getKey(), $attemptId);

            return $fresh;
        });
    }

    /**
     * Narrow seam: enqueue the issuance job. Overridden in tests to prove that a
     * failing enqueue rolls the whole approval back.
     */
    protected function dispatchIssuanceJob(int $requestId, string $attemptId): void
    {
        try {
            IssueCertificateJob::dispatch($requestId, $attemptId);
        } catch (WorkflowException $e) {
            throw $e;
        } catch (Throwable) {
            throw WorkflowException::of(WorkflowException::ENQUEUE_FAILED);
        }
    }

    /**
     * A user has a blocking certificate only when it is active AND still valid.
     * An expired or deactivated certificate allows a fresh request.
     */
    public function hasBlockingCertificate(int $subjectId): bool
    {
        return Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $subjectId)
            ->where('is_active', true)
            ->where('valid_to', '>', now())
            ->exists();
    }

    public function activeRequestFor(int $subjectId): ?CertificateRequest
    {
        return CertificateRequest::query()
            ->where('user_id', $subjectId)
            ->whereIn('status', Status::ACTIVE)
            ->first();
    }

    private function assertNoActiveRequest(int $subjectId): void
    {
        $exists = CertificateRequest::query()
            ->where('user_id', $subjectId)
            ->whereIn('status', Status::ACTIVE)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw WorkflowException::of(WorkflowException::ACTIVE_REQUEST_EXISTS);
        }
    }

    private function assertNoActiveCertificate(int $subjectId): void
    {
        if ($this->hasBlockingCertificate($subjectId)) {
            throw WorkflowException::of(WorkflowException::ACTIVE_CERTIFICATE_EXISTS);
        }
    }

    private function assertPending(CertificateRequest $request): void
    {
        if ((string) $request->status !== Status::PENDING) {
            throw WorkflowException::of(WorkflowException::REQUEST_NOT_PENDING);
        }
    }

    /**
     * The full review lock protocol, shared by approve() and reject().
     *
     * Order (see CertificateOperatorAuthority for the rationale):
     *   1. both user rows locked in ASCENDING id order (deadlock-safe);
     *   2. the request row locked;
     *   3. the exact role_user pivot locked FOR UPDATE and membership RE-PROVEN;
     *   4. only then the self-review ban and the domain checks.
     *
     * A concurrent revoke therefore either committed before step 3 (membership
     * gone → refused) or must wait behind this lock — it can never land between
     * the authorization check and this transaction's commit.
     */
    private function lockForReview(int $requestId, int $operatorId): CertificateRequest
    {
        $subjectId = CertificateRequest::query()->whereKey($requestId)->value('user_id');

        if ($subjectId === null) {
            throw WorkflowException::of(WorkflowException::REQUEST_UNAVAILABLE);
        }

        // 1. Users, ascending id order.
        $this->authority->lockParticipants((int) $subjectId, $operatorId);

        // 2. The request row.
        $request = CertificateRequest::query()->whereKey($requestId)->lockForUpdate()->first();

        if ($request === null) {
            throw WorkflowException::of(WorkflowException::REQUEST_UNAVAILABLE);
        }

        // 3. Authoritative, LOCKED membership re-proof.
        $this->authority->assertLockedOperatorMembership($operatorId);

        $this->afterOperatorAuthorityLocked($requestId, $operatorId);

        // 4. Self-review ban only after authorization is settled.
        if ((int) $request->user_id === $operatorId) {
            throw WorkflowException::of(WorkflowException::SELF_REVIEW_FORBIDDEN);
        }

        return $request;
    }

    private function lockSubjectOrFail(int $subjectId): User
    {
        return $this->authority->lockUserOrFail($subjectId, WorkflowException::SUBJECT_UNAVAILABLE);
    }

    /** Owner-only path (create/cancel): subject row, then the request row. */
    private function lockRequestForSubject(int $requestId): CertificateRequest
    {
        $subjectId = CertificateRequest::query()->whereKey($requestId)->value('user_id');

        if ($subjectId === null) {
            throw WorkflowException::of(WorkflowException::REQUEST_UNAVAILABLE);
        }

        $this->lockSubjectOrFail((int) $subjectId);

        $request = CertificateRequest::query()->whereKey($requestId)->lockForUpdate()->first();

        if ($request === null) {
            throw WorkflowException::of(WorkflowException::REQUEST_UNAVAILABLE);
        }

        return $request;
    }

    /**
     * Audit inside the workflow transaction, with an explicit trusted actor.
     * Metadata is an allow-list of stable identifiers and statuses only — never
     * note text, PII, DN/serial, the attempt UUID, a path or a raw error.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordAudit(string $event, CertificateRequest $request, int $actorUserId, array $metadata): void
    {
        $this->audit->record(
            $event,
            null,
            array_merge($metadata, [
                'certificate_request_id' => (int) $request->getKey(),
                'subject_user_id' => (int) $request->user_id,
            ]),
            $request,
            $actorUserId,
        );
    }

    private function normalizeNote(?string $note): ?string
    {
        $trimmed = trim((string) $note);

        return $trimmed === '' ? null : $trimmed;
    }

    private function connectionName(): string
    {
        return (string) (CertificateRequest::query()->getModel()->getConnectionName() ?? config('database.default'));
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function transaction(callable $callback): mixed
    {
        return DB::connection($this->connectionName())->transaction($callback);
    }
}
