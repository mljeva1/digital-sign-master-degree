<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Models\CertificateRequest;
use App\Services\CertificateRequests\CertificateRequestWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * M14 Phase A — the authoritative workflow contract (state machine, blocking
 * rules, operator rules). Authorization at the HTTP edge is covered separately.
 */
final class CertificateRequestWorkflowTest extends CertificateRequestTestCase
{
    private function workflow(): CertificateRequestWorkflow
    {
        return app(CertificateRequestWorkflow::class);
    }

    // --- state machine ----------------------------------------------------

    public function test_state_machine_allows_only_the_documented_transitions(): void
    {
        $this->assertTrue(Status::canTransition(Status::PENDING, Status::APPROVED));
        $this->assertTrue(Status::canTransition(Status::PENDING, Status::REJECTED));
        $this->assertTrue(Status::canTransition(Status::PENDING, Status::CANCELLED));
        $this->assertTrue(Status::canTransition(Status::APPROVED, Status::ISSUING));
        $this->assertTrue(Status::canTransition(Status::ISSUING, Status::ISSUED));
        $this->assertTrue(Status::canTransition(Status::ISSUING, Status::FAILED));

        // Everything else is refused, including any revival of a terminal state.
        $this->assertFalse(Status::canTransition(Status::PENDING, Status::ISSUING));
        $this->assertFalse(Status::canTransition(Status::PENDING, Status::ISSUED));
        $this->assertFalse(Status::canTransition(Status::APPROVED, Status::ISSUED));
        $this->assertFalse(Status::canTransition(Status::APPROVED, Status::REJECTED));
        $this->assertFalse(Status::canTransition(Status::ISSUING, Status::APPROVED));
        $this->assertFalse(Status::canTransition(Status::FAILED, Status::APPROVED));
        $this->assertFalse(Status::canTransition(Status::FAILED, Status::ISSUING));
        $this->assertFalse(Status::canTransition(Status::ISSUED, Status::ISSUING));
        $this->assertFalse(Status::canTransition(Status::REJECTED, Status::PENDING));
        $this->assertFalse(Status::canTransition(Status::CANCELLED, Status::PENDING));
    }

    public function test_terminal_states_have_no_outgoing_transitions(): void
    {
        foreach ([Status::REJECTED, Status::CANCELLED, Status::ISSUED, Status::FAILED] as $terminal) {
            $this->assertTrue(Status::isTerminal($terminal));
            $this->assertSame([], Status::allowedFrom($terminal));
        }
    }

    public function test_active_states_mirror_the_partial_unique_predicate(): void
    {
        $this->assertSame([Status::PENDING, Status::APPROVED, Status::ISSUING], Status::ACTIVE);
    }

    // --- create -----------------------------------------------------------

    public function test_create_stores_a_pending_request_for_the_subject(): void
    {
        $user = $this->userWithRole();

        $request = $this->workflow()->create($user, '  please issue  ');

        $this->assertSame(Status::PENDING, $request->status);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame('please issue', $request->request_note);
        $this->assertNull($request->reviewed_by_user_id);
        $this->assertNull($request->issuance_attempt_id);
    }

    public function test_create_is_blocked_by_another_active_request(): void
    {
        $user = $this->userWithRole();
        $this->workflow()->create($user);

        $this->expectException(WorkflowException::class);
        $this->workflow()->create($user);
    }

    public function test_create_is_blocked_by_an_active_non_expired_certificate(): void
    {
        $user = $this->userWithRole();
        $this->activeCertificateFor($user);

        try {
            $this->workflow()->create($user);
            $this->fail('Expected an active certificate to block a new request.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::ACTIVE_CERTIFICATE_EXISTS, $e->errorCode());
        }
    }

    public function test_expired_certificate_does_not_block_a_new_request(): void
    {
        $user = $this->userWithRole();
        $this->activeCertificateFor($user, now()->subDay()->toDateTimeString());

        $request = $this->workflow()->create($user);

        $this->assertSame(Status::PENDING, $request->status);
    }

    public function test_inactive_certificate_does_not_block_a_new_request(): void
    {
        $user = $this->userWithRole();
        $this->activeCertificateFor($user, null, false);

        $request = $this->workflow()->create($user);

        $this->assertSame(Status::PENDING, $request->status);
    }

    public function test_new_request_is_allowed_after_a_terminal_failed_request(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();

        CertificateRequest::factory()->failed($operator)->create(['user_id' => $user->id]);

        $request = $this->workflow()->create($user);

        $this->assertSame(Status::PENDING, $request->status);
    }

    public function test_new_request_is_allowed_after_cancelled_and_rejected_requests(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();

        CertificateRequest::factory()->cancelled()->create(['user_id' => $user->id]);
        CertificateRequest::factory()->rejected($operator)->create(['user_id' => $user->id]);

        $this->assertSame(Status::PENDING, $this->workflow()->create($user)->status);
    }

    // --- cancel -----------------------------------------------------------

    public function test_owner_cancels_their_pending_request(): void
    {
        $user = $this->userWithRole();
        $request = $this->workflow()->create($user);

        $cancelled = $this->workflow()->cancel($request, $user);

        $this->assertSame(Status::CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($cancelled->reviewed_by_user_id);
    }

    public function test_cancel_is_refused_for_a_non_pending_request(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = CertificateRequest::factory()->approved($operator)->create(['user_id' => $user->id]);

        try {
            $this->workflow()->cancel($request, $user);
            $this->fail('Expected a non-pending request to refuse cancellation.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::REQUEST_NOT_PENDING, $e->errorCode());
        }
    }

    public function test_cancel_is_refused_for_a_foreign_user(): void
    {
        $owner = $this->userWithRole();
        $stranger = $this->userWithRole();
        $request = $this->workflow()->create($owner);

        $this->expectException(WorkflowException::class);
        $this->workflow()->cancel($request, $stranger);
    }

    // --- reject -----------------------------------------------------------

    public function test_operator_rejects_with_a_reason(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $rejected = $this->workflow()->reject($request, $operator, 'Nedostaje obrazloženje.');

        $this->assertSame(Status::REJECTED, $rejected->status);
        $this->assertSame($operator->id, $rejected->reviewed_by_user_id);
        $this->assertNotNull($rejected->reviewed_at);
        $this->assertSame('Nedostaje obrazloženje.', $rejected->operator_note);
    }

    public function test_reject_requires_a_non_empty_reason(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        try {
            $this->workflow()->reject($request, $operator, '   ');
            $this->fail('Expected a blank reason to be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::OPERATOR_NOTE_REQUIRED, $e->errorCode());
        }

        $this->assertSame(Status::PENDING, $request->fresh()->status);
    }

    public function test_operator_cannot_review_their_own_request(): void
    {
        $operator = $this->operator();
        $request = $this->workflow()->create($operator);

        try {
            $this->workflow()->reject($request, $operator, 'self');
            $this->fail('Expected self-review to be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::SELF_REVIEW_FORBIDDEN, $e->errorCode());
        }
    }

    public function test_non_operator_cannot_review(): void
    {
        $user = $this->userWithRole();
        $employee = $this->userWithRole('employee');
        $request = $this->workflow()->create($user);

        try {
            $this->workflow()->reject($request, $employee, 'no');
            $this->fail('Expected a non-operator to be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::OPERATOR_NOT_AUTHORIZED, $e->errorCode());
        }
    }

    public function test_admin_without_operator_role_cannot_review(): void
    {
        $user = $this->userWithRole();
        $admin = $this->userWithRole('admin');
        $request = $this->workflow()->create($user);

        try {
            $this->workflow()->reject($request, $admin, 'no');
            $this->fail('Expected admin without certificate_operator to be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::OPERATOR_NOT_AUTHORIZED, $e->errorCode());
        }
    }

    // --- approve ----------------------------------------------------------

    public function test_approve_sets_the_full_approved_shape(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $approved = $this->workflow()->approve($request, $operator);

        $this->assertSame(Status::APPROVED, $approved->status);
        $this->assertSame($operator->id, $approved->reviewed_by_user_id);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertNotNull($approved->approved_at);
        $this->assertNotNull($approved->issuance_attempt_id);
        $this->assertNull($approved->certificate_id);
        $this->assertNull($approved->failure_code);
    }

    public function test_approve_is_refused_when_the_subject_gained_an_active_certificate(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        // Certificate appears between submission and review.
        $this->activeCertificateFor($user);

        try {
            $this->workflow()->approve($request, $operator);
            $this->fail('Expected approval to re-check the active certificate.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::ACTIVE_CERTIFICATE_EXISTS, $e->errorCode());
        }

        $this->assertSame(Status::PENDING, $request->fresh()->status);
    }

    public function test_double_approval_is_refused_and_leaves_one_job(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $this->workflow()->approve($request, $operator);

        try {
            $this->workflow()->approve($request->fresh(), $operator);
            $this->fail('Expected the second approval to be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::REQUEST_NOT_PENDING, $e->errorCode());
        }

        $this->assertSame(1, DB::table('jobs')->count(), 'A refused second approval must not enqueue another job.');
    }
}
