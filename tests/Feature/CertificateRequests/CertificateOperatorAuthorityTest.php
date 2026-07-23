<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\CertificateRequests\CertificateOperatorAuthority;
use App\Services\CertificateRequests\CertificateRequestWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * P2-2 — operator membership must be re-proven with the pivot row LOCKED.
 *
 * These sequential tests drive the REAL workflow caller (not the helper in
 * isolation) and revoke the role *between* the caller's entry and the locked
 * re-check, using the production seam. A snapshot-only `->exists()` check would
 * pass them; only a locked re-proof refuses.
 */
final class CertificateOperatorAuthorityTest extends CertificateRequestTestCase
{
    private function workflow(): CertificateRequestWorkflow
    {
        return app(CertificateRequestWorkflow::class);
    }

    /** Workflow that revokes the operator's role right after the lock is taken. */
    private function revokingWorkflow(int $operatorId): CertificateRequestWorkflow
    {
        return new class(app(AuditLogger::class), app(CertificateOperatorAuthority::class), $operatorId) extends CertificateRequestWorkflow
        {
            public function __construct(AuditLogger $audit, private readonly CertificateOperatorAuthority $auth, private readonly int $target)
            {
                parent::__construct($audit, $auth);
            }

            protected function afterOperatorAuthorityLocked(int $requestId, int $operatorId): void
            {
                // Simulates a revoke that lands after the caller began but before
                // it commits. The locked re-proof must therefore be re-evaluated.
                if ($operatorId === $this->target) {
                    DB::table('role_user')
                        ->where('user_id', $this->target)
                        ->where('role_id', $this->auth->operatorRoleId())
                        ->delete();

                    // Re-run the authoritative check with the row now gone.
                    $this->auth->assertLockedOperatorMembership($operatorId);
                }
            }
        };
    }

    public function test_approve_fails_when_role_is_revoked_before_the_locked_recheck(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        try {
            $this->revokingWorkflow((int) $operator->id)->approve($request, $operator);
            $this->fail('Expected a revoked role to refuse the approval.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::OPERATOR_NOT_AUTHORIZED, $e->errorCode());
        }

        $fresh = $request->fresh();
        $this->assertSame(Status::PENDING, $fresh->status, 'No state write may survive.');
        $this->assertNull($fresh->reviewed_by_user_id);
        $this->assertNull($fresh->issuance_attempt_id);
        $this->assertSame(0, DB::table('jobs')->count(), 'No job may be enqueued.');
        $this->assertSame(0, DB::table('audit_events')->where('action', CertificateRequestWorkflow::AUDIT_APPROVED)->count());
    }

    public function test_reject_fails_when_role_is_revoked_before_the_locked_recheck(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        try {
            $this->revokingWorkflow((int) $operator->id)->reject($request, $operator, 'Nedostatan zahtjev.');
            $this->fail('Expected a revoked role to refuse the rejection.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::OPERATOR_NOT_AUTHORIZED, $e->errorCode());
        }

        $fresh = $request->fresh();
        $this->assertSame(Status::PENDING, $fresh->status);
        $this->assertNull($fresh->operator_note);
        $this->assertSame(0, DB::table('audit_events')->where('action', CertificateRequestWorkflow::AUDIT_REJECTED)->count());
    }

    public function test_operator_without_membership_is_refused(): void
    {
        $user = $this->userWithRole();
        $notOperator = $this->userWithRole();
        $request = $this->workflow()->create($user);

        try {
            $this->workflow()->reject($request, $notOperator, 'no');
            $this->fail('Expected a user without membership to be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::OPERATOR_NOT_AUTHORIZED, $e->errorCode());
        }
    }

    public function test_exact_operator_membership_passes(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $rejected = $this->workflow()->reject($request, $operator, 'Dokumentiran razlog.');

        $this->assertSame(Status::REJECTED, $rejected->status);
    }

    public function test_admin_and_employee_remain_refused(): void
    {
        foreach (['admin', 'employee'] as $role) {
            $user = $this->userWithRole();
            $actor = $this->userWithRole($role);
            $request = $this->workflow()->create($user);

            try {
                $this->workflow()->reject($request, $actor, 'no');
                $this->fail("Expected {$role} to be refused.");
            } catch (WorkflowException $e) {
                $this->assertSame(WorkflowException::OPERATOR_NOT_AUTHORIZED, $e->errorCode());
            }
        }
    }

    public function test_self_review_remains_forbidden(): void
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

    // --- lock order ------------------------------------------------------

    public function test_participants_are_locked_in_ascending_id_order(): void
    {
        $authority = app(CertificateOperatorAuthority::class);
        $a = $this->userWithRole();
        $b = $this->userWithRole();

        [$low, $high] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'from "users"')) {
                $queries[] = $q->bindings;
            }
        });

        // Same pair, opposite semantic roles — both must lock low id first.
        DB::transaction(fn () => $authority->lockParticipants((int) $high->id, (int) $low->id));
        $first = $queries;

        $queries = [];
        DB::transaction(fn () => $authority->lockParticipants((int) $low->id, (int) $high->id));
        $second = $queries;

        $this->assertSame((int) $low->id, (int) $first[0][0], 'Lowest id must be locked first regardless of role.');
        $this->assertSame((int) $low->id, (int) $second[0][0], 'Lowest id must be locked first regardless of role.');
    }

    // --- grant/revoke uses the same protocol ------------------------------

    public function test_grant_and_revoke_command_use_the_authority_protocol(): void
    {
        $user = $this->userWithRole();

        $this->artisan('certificate-operator:grant', ['user' => (string) $user->id])->assertExitCode(0);
        $this->assertTrue($user->fresh()->hasRole('certificate_operator'));

        // Idempotent: no duplicate pivot row.
        $this->artisan('certificate-operator:grant', ['user' => (string) $user->id])->assertExitCode(0);
        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());

        $this->artisan('certificate-operator:grant', ['user' => (string) $user->id, '--revoke' => true])->assertExitCode(0);
        $this->assertFalse($user->fresh()->hasRole('certificate_operator'));

        // Revoking again is a safe no-op.
        $this->artisan('certificate-operator:grant', ['user' => (string) $user->id, '--revoke' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    public function test_grant_command_never_creates_a_user(): void
    {
        $before = User::query()->count();

        $this->artisan('certificate-operator:grant', ['user' => '999999'])->assertExitCode(1);

        $this->assertSame($before, User::query()->count());
    }
}
