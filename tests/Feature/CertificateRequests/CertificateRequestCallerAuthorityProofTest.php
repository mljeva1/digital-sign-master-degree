<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Services\Audit\AuditLogger;
use App\Services\CertificateRequests\CertificateOperatorAuthority;
use App\Services\CertificateRequests\CertificateRequestWorkflow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Unique marker that no workflow failure can produce. */
final class LockedAuthorityProofSentinel extends RuntimeException
{
    public const MARKER = 'CALLER_LOCKED_AUTHORITY_PROOF_REACHED';
}

/**
 * Records the locked membership proof and aborts before any state write.
 *
 * It never performs the authoritative check itself — that is the whole point.
 * The only way `assertLockedOperatorMembership()` runs is if the PRODUCTION
 * code in CertificateRequestWorkflow::lockForReview() calls it.
 */
final class SpyOperatorAuthority extends CertificateOperatorAuthority
{
    public int $membershipProofCalls = 0;

    /** @var list<int> */
    public array $membershipProofOperatorIds = [];

    public int $lockParticipantsCalls = 0;

    public function lockParticipants(int $subjectId, int $operatorId): array
    {
        $this->lockParticipantsCalls++;

        // Real locking behaviour is preserved so the caller reaches the
        // membership proof through the genuine path.
        return parent::lockParticipants($subjectId, $operatorId);
    }

    public function assertLockedOperatorMembership(int $operatorId): void
    {
        $this->membershipProofCalls++;
        $this->membershipProofOperatorIds[] = $operatorId;

        // Abort HERE: before the self-review check, before any status write,
        // audit event or job insert.
        throw new LockedAuthorityProofSentinel(LockedAuthorityProofSentinel::MARKER);
    }
}

/**
 * MUTATION-SENSITIVE proof that the real approve()/reject() flows call the
 * LOCKED operator-membership check.
 *
 * Why this test exists: the pre-existing revoke tests drive the seam
 * `afterOperatorAuthorityLocked()`, which itself re-invokes the membership
 * helper — so they would stay green even if the production call inside
 * lockForReview() were deleted. These tests close that gap.
 *
 * How they are mutation-sensitive: the spy NEVER calls the helper on its own.
 * It only records and aborts when the production code calls it. Delete, skip or
 * downgrade the production call (e.g. to an unlocked `exists()` snapshot) and:
 *   - the sentinel is never thrown,
 *   - membershipProofCalls stays 0,
 *   - the workflow proceeds to a state write,
 * so both tests fail immediately.
 *
 * They deliberately call only the PUBLIC workflow API — never lockForReview(),
 * never the authority helper directly, and never the production seam.
 */
final class CertificateRequestCallerAuthorityProofTest extends CertificateRequestTestCase
{
    private function workflowWithSpy(SpyOperatorAuthority $spy): CertificateRequestWorkflow
    {
        // Same constructor DI path production uses; no production hack needed
        // because CertificateOperatorAuthority is a plain injectable class.
        return new CertificateRequestWorkflow(app(AuditLogger::class), $spy);
    }

    public function test_approve_calls_the_locked_membership_proof(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = app(CertificateRequestWorkflow::class)->create($user);

        $spy = new SpyOperatorAuthority;

        $reached = false;
        try {
            $this->workflowWithSpy($spy)->approve($request, $operator);
        } catch (LockedAuthorityProofSentinel $e) {
            $reached = true;
            $this->assertSame(LockedAuthorityProofSentinel::MARKER, $e->getMessage());
        }

        $this->assertTrue($reached, 'approve() must reach the LOCKED membership proof.');
        $this->assertSame(1, $spy->membershipProofCalls, 'The locked proof must be called exactly once.');
        $this->assertSame([(int) $operator->id], $spy->membershipProofOperatorIds, 'Called for the reviewing operator.');
        $this->assertSame(1, $spy->lockParticipantsCalls, 'Participants must be locked before the proof.');

        $this->assertNoStateWrite($request->getKey());
        $this->assertSame(0, DB::table('jobs')->count(), 'approve() must not enqueue after a refused proof.');
    }

    public function test_reject_calls_the_locked_membership_proof(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = app(CertificateRequestWorkflow::class)->create($user);

        $spy = new SpyOperatorAuthority;

        $reached = false;
        try {
            $this->workflowWithSpy($spy)->reject($request, $operator, 'Dokumentiran razlog.');
        } catch (LockedAuthorityProofSentinel $e) {
            $reached = true;
            $this->assertSame(LockedAuthorityProofSentinel::MARKER, $e->getMessage());
        }

        $this->assertTrue($reached, 'reject() must reach the LOCKED membership proof.');
        $this->assertSame(1, $spy->membershipProofCalls);
        $this->assertSame([(int) $operator->id], $spy->membershipProofOperatorIds);
        $this->assertSame(1, $spy->lockParticipantsCalls);

        $this->assertNoStateWrite($request->getKey());
        $this->assertNull(DB::table('certificate_requests')->where('id', $request->getKey())->value('operator_note'));
    }

    /** The sentinel must abort strictly before any persisted change. */
    private function assertNoStateWrite(int $requestId): void
    {
        $row = DB::table('certificate_requests')->where('id', $requestId)->first();

        $this->assertSame(Status::PENDING, $row->status, 'Request must remain pending.');
        $this->assertNull($row->reviewed_by_user_id);
        $this->assertNull($row->reviewed_at);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->issuance_attempt_id);
        $this->assertNull($row->certificate_id);
        $this->assertNull($row->failure_code);

        foreach ([CertificateRequestWorkflow::AUDIT_APPROVED, CertificateRequestWorkflow::AUDIT_REJECTED] as $action) {
            $this->assertSame(0, DB::table('audit_events')->where('action', $action)->count(), "No {$action} audit may be written.");
        }
    }

    /** The spy must not be able to satisfy the proof by itself. */
    public function test_spy_never_performs_the_authoritative_check_itself(): void
    {
        $spy = new SpyOperatorAuthority;

        $this->assertSame(0, $spy->membershipProofCalls);

        // Calling it directly only records + aborts; it performs no DB proof.
        try {
            $spy->assertLockedOperatorMembership(1);
            $this->fail('The spy must abort rather than validate.');
        } catch (LockedAuthorityProofSentinel) {
            // expected
        }

        $this->assertSame(1, $spy->membershipProofCalls);
    }
}
