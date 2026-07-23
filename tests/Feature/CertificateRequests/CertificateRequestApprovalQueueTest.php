<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Domain\CertificateRequests\IssuanceQueueContract;
use App\Jobs\IssueCertificateJob;
use App\Models\CertificateRequest;
use App\Services\Audit\AuditLogger;
use App\Services\CertificateRequests\CertificateIssuanceProcessor;
use App\Services\CertificateRequests\CertificateRequestWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M14 Phase A — the atomic approval ⇄ enqueue contract.
 *
 * The approval status change, operator binding, attempt reservation, approved
 * audit event and the single jobs row must commit or roll back as ONE unit.
 */
final class CertificateRequestApprovalQueueTest extends CertificateRequestTestCase
{
    private function workflow(): CertificateRequestWorkflow
    {
        return app(CertificateRequestWorkflow::class);
    }

    public function test_successful_approval_enqueues_exactly_one_job(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $this->workflow()->approve($request, $operator);

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(Status::APPROVED, $request->fresh()->status);
    }

    public function test_job_payload_contains_only_the_request_id_and_attempt_uuid(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $approved = $this->workflow()->approve($request, $operator);

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row);
        $this->assertSame(IssuanceQueueContract::QUEUE_NAME, $row->queue);

        $payload = (string) $row->payload;
        $decoded = json_decode($payload, true);
        $command = (string) ($decoded['data']['command'] ?? '');

        // The two scalars the worker is allowed to receive.
        $this->assertStringContainsString((string) $approved->getKey(), $command);
        $this->assertStringContainsString((string) $approved->issuance_attempt_id, $command);

        // Nothing else: no models, notes, identities, paths or secrets.
        $this->assertStringNotContainsString('App\\\\Models\\\\User', $command);
        $this->assertStringNotContainsString('App\\Models\\User', $command);
        $this->assertStringNotContainsString('CertificateRequest";', $command);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString($user->name, $payload);
        $this->assertStringNotContainsString('operator_note', $payload);
        $this->assertStringNotContainsString('request_note', $payload);
        $this->assertStringNotContainsString('BEGIN', $payload);
        $this->assertStringNotContainsString('passphrase', $payload);
        $this->assertStringNotContainsString('storage/', $payload);
        $this->assertStringNotContainsString('.pem', $payload);
    }

    public function test_note_text_never_reaches_the_job_payload(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user, 'SECRET-NOTE-MARKER');

        $this->workflow()->approve($request, $operator);

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('SECRET-NOTE-MARKER', $payload);
    }

    public function test_enqueue_failure_rolls_back_request_audit_and_job(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        // Workflow whose enqueue seam always fails.
        $failing = new class(app(AuditLogger::class)) extends CertificateRequestWorkflow
        {
            protected function dispatchIssuanceJob(int $requestId, string $attemptId): void
            {
                throw WorkflowException::of(WorkflowException::ENQUEUE_FAILED);
            }
        };

        try {
            $failing->approve($request, $operator);
            $this->fail('Expected the enqueue failure to propagate.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::ENQUEUE_FAILED, $e->errorCode());
        }

        $fresh = $request->fresh();
        $this->assertSame(Status::PENDING, $fresh->status, 'Request must stay pending.');
        $this->assertNull($fresh->reviewed_by_user_id);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->issuance_attempt_id);

        $this->assertSame(0, DB::table('jobs')->count(), 'No job row may survive.');
        $this->assertSame(
            0,
            DB::table('audit_events')->where('action', CertificateRequestWorkflow::AUDIT_APPROVED)->count(),
            'No approved audit event may survive.'
        );
    }

    public function test_outer_transaction_rollback_removes_approval_and_job(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        DB::beginTransaction();
        $this->workflow()->approve($request, $operator);
        $this->assertSame(1, DB::table('jobs')->count());
        DB::rollBack();

        $this->assertSame(Status::PENDING, $request->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(
            0,
            DB::table('audit_events')->where('action', CertificateRequestWorkflow::AUDIT_APPROVED)->count()
        );
    }

    // --- fail-closed queue configuration ----------------------------------

    public function test_sync_queue_default_never_redirects_the_pinned_job(): void
    {
        // P2-6: the job is pinned onConnection('database'), so a sync (or any
        // other) queue.default must NOT redirect issuance. The atomic `database`
        // connection is configured, so approval succeeds and enqueues there.
        $this->useAtomicDatabaseQueue();
        config(['queue.default' => 'sync']);

        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $this->workflow()->approve($request, $operator);

        $this->assertSame(Status::APPROVED, $request->fresh()->status);
        $this->assertSame(1, DB::table('jobs')->count(), 'the pinned database connection still receives the job');
        $this->assertSame('certificate-issuance', DB::table('jobs')->value('queue'));
    }

    public function test_a_foreign_database_default_never_redirects_the_pinned_job(): void
    {
        // Another database-driver connection as the default must not capture the
        // job — it stays on the explicit `database` connection.
        $this->useAtomicDatabaseQueue();
        config([
            'queue.connections.other_db' => array_merge(
                (array) config('queue.connections.database'),
                ['queue' => 'somewhere-else'],
            ),
            'queue.default' => 'other_db',
        ]);

        $user = $this->userWithRole();
        $operator = $this->operator();
        $this->workflow()->approve($this->workflow()->create($user), $operator);

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('certificate-issuance', DB::table('jobs')->value('queue'), 'the job stays on the pinned queue');
    }

    public function test_a_non_database_driver_on_the_pinned_connection_is_refused(): void
    {
        // If the pinned `database` connection is not actually a database driver,
        // approval fails before any state change (no reliance on queue.default).
        $this->useAtomicDatabaseQueue();
        config(['queue.connections.database.driver' => 'redis', 'queue.default' => 'sync']);

        $this->assertQueueRefused();
    }

    public function test_after_commit_queue_is_refused(): void
    {
        $this->useAtomicDatabaseQueue();
        config(['queue.connections.database.after_commit' => true]);

        $this->assertQueueRefused();
    }

    public function test_foreign_database_connection_is_refused(): void
    {
        $this->useAtomicDatabaseQueue();
        config(['queue.connections.database.connection' => 'some_other_connection']);

        $this->assertQueueRefused();
    }

    public function test_non_database_driver_is_refused(): void
    {
        $this->useAtomicDatabaseQueue();
        config(['queue.connections.database.driver' => 'redis']);

        $this->assertQueueRefused();
    }

    private function assertQueueRefused(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        try {
            $this->workflow()->approve($request, $operator);
            $this->fail('Expected the unsafe queue configuration to be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame(WorkflowException::QUEUE_CONTRACT_UNSAFE, $e->errorCode());
        }

        $this->assertSame(Status::PENDING, $request->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_approved_request_keeps_its_attempt_uuid_out_of_the_audit_trail(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $approved = $this->workflow()->approve($request, $operator);

        $metadata = DB::table('audit_events')
            ->where('action', CertificateRequestWorkflow::AUDIT_APPROVED)
            ->value('metadata');

        $this->assertNotNull($approved->issuance_attempt_id);
        $this->assertStringNotContainsString((string) $approved->issuance_attempt_id, (string) $metadata);
    }

    public function test_job_class_is_a_real_queued_job_on_the_issuance_queue(): void
    {
        $job = new IssueCertificateJob(7, '00000000-0000-4000-8000-000000000000');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(IssuanceQueueContract::QUEUE_NAME, $job->queue);
        // P2-6: the job explicitly pins both the queue AND the connection.
        $this->assertSame('certificate-issuance', $job->queue);
        $this->assertSame(IssuanceQueueContract::QUEUE_CONNECTION, $job->connection);
        $this->assertSame('database', $job->connection);
        $this->assertSame(7, $job->certificateRequestId);
        $this->assertSame('00000000-0000-4000-8000-000000000000', $job->issuanceAttemptId);
    }

    public function test_worker_ignores_a_stale_attempt_delivery_without_touching_the_request(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $approved = $this->workflow()->approve($this->workflow()->create($user), $operator);

        // A delivery carrying the WRONG attempt id must be a pure no-op: it
        // reaches neither the claim write nor any crypto/root resolution.
        app(CertificateIssuanceProcessor::class)
            ->process((int) $approved->getKey(), (string) Str::uuid());

        $fresh = $approved->fresh();
        $this->assertSame(Status::APPROVED, $fresh->status);
        $this->assertNull($fresh->issuance_started_at);
        $this->assertNull($fresh->failure_code);
        $this->assertNull($fresh->failed_at);
        $this->assertNull($fresh->certificate_id);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', CertificateIssuanceProcessor::AUDIT_STARTED)
            ->count());
    }

    public function test_double_approval_creates_only_one_job_row(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $this->workflow()->approve($request, $operator);

        try {
            $this->workflow()->approve(CertificateRequest::query()->findOrFail($request->getKey()), $operator);
        } catch (WorkflowException) {
            // expected
        }

        $this->assertSame(1, DB::table('jobs')->count());
    }
}
