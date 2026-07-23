<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Services\CertificateRequests\CertificateRequestWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * M14 Phase A — audit actor and metadata allow-list.
 *
 * Every event is written inside the workflow transaction with an explicit,
 * trusted actor, and its metadata carries only stable identifiers and statuses.
 */
final class CertificateRequestAuditTest extends CertificateRequestTestCase
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'operation_name',
        'old_status',
        'new_status',
        'certificate_request_id',
        'subject_user_id',
        'operator_user_id',
        'operator_note_provided',
    ];

    private function workflow(): CertificateRequestWorkflow
    {
        return app(CertificateRequestWorkflow::class);
    }

    /** @return array<string, mixed> */
    private function metadataFor(string $action): array
    {
        $raw = DB::table('audit_events')->where('action', $action)->value('metadata');
        $this->assertNotNull($raw, "Missing audit event {$action}.");

        return (array) json_decode((string) $raw, true);
    }

    private function actorFor(string $action): int
    {
        return (int) DB::table('audit_events')->where('action', $action)->value('actor_user_id');
    }

    public function test_create_and_cancel_audit_the_owner_as_actor(): void
    {
        $user = $this->userWithRole();

        $request = $this->workflow()->create($user);
        $this->assertSame($user->id, $this->actorFor(CertificateRequestWorkflow::AUDIT_CREATED));

        $this->workflow()->cancel($request, $user);
        $this->assertSame($user->id, $this->actorFor(CertificateRequestWorkflow::AUDIT_CANCELLED));

        $created = $this->metadataFor(CertificateRequestWorkflow::AUDIT_CREATED);
        $this->assertSame('create', $created['operation_name']);
        $this->assertNull($created['old_status']);
        $this->assertSame('pending', $created['new_status']);
        $this->assertSame((int) $request->getKey(), $created['certificate_request_id']);
        $this->assertSame($user->id, $created['subject_user_id']);

        $cancelled = $this->metadataFor(CertificateRequestWorkflow::AUDIT_CANCELLED);
        $this->assertSame('pending', $cancelled['old_status']);
        $this->assertSame('cancelled', $cancelled['new_status']);
    }

    public function test_reject_audits_the_concrete_operator_as_actor(): void
    {
        $user = $this->userWithRole();
        $operator = $this->operator();
        $request = $this->workflow()->create($user);

        $this->workflow()->reject($request, $operator, 'SENSITIVE-REJECT-REASON');

        $this->assertSame($operator->id, $this->actorFor(CertificateRequestWorkflow::AUDIT_REJECTED));

        $metadata = $this->metadataFor(CertificateRequestWorkflow::AUDIT_REJECTED);
        $this->assertSame('reject', $metadata['operation_name']);
        $this->assertSame('pending', $metadata['old_status']);
        $this->assertSame('rejected', $metadata['new_status']);
        $this->assertSame($operator->id, $metadata['operator_user_id']);
        $this->assertTrue($metadata['operator_note_provided']);

        // The reason itself is never audited.
        $this->assertStringNotContainsString('SENSITIVE-REJECT-REASON', json_encode($metadata));
    }

    public function test_approve_audits_the_operator_and_omits_the_attempt_uuid(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();

        $approved = $this->workflow()->approve($this->workflow()->create($user), $operator);

        $this->assertSame($operator->id, $this->actorFor(CertificateRequestWorkflow::AUDIT_APPROVED));

        $metadata = $this->metadataFor(CertificateRequestWorkflow::AUDIT_APPROVED);
        $this->assertSame('approve', $metadata['operation_name']);
        $this->assertSame('pending', $metadata['old_status']);
        $this->assertSame('approved', $metadata['new_status']);
        $this->assertSame($operator->id, $metadata['operator_user_id']);
        $this->assertStringNotContainsString((string) $approved->issuance_attempt_id, json_encode($metadata));
    }

    public function test_every_audit_metadata_key_is_allow_listed(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();
        $other = $this->userWithRole();

        $this->workflow()->reject($this->workflow()->create($user), $operator, 'reason');
        $this->workflow()->approve($this->workflow()->create($other), $operator);
        $cancelUser = $this->userWithRole();
        $this->workflow()->cancel($this->workflow()->create($cancelUser), $cancelUser);

        $rows = DB::table('audit_events')->pluck('metadata');
        $this->assertGreaterThanOrEqual(4, $rows->count());

        foreach ($rows as $raw) {
            foreach (array_keys((array) json_decode((string) $raw, true)) as $key) {
                $this->assertContains($key, self::ALLOWED_KEYS, "Audit metadata key [{$key}] is not allow-listed.");
            }
        }
    }

    public function test_audit_metadata_never_contains_pii_paths_or_secrets(): void
    {
        $this->useAtomicDatabaseQueue();
        $user = $this->userWithRole();
        $operator = $this->operator();

        $this->workflow()->approve($this->workflow()->create($user, 'my private note'), $operator);

        $all = (string) DB::table('audit_events')->pluck('metadata')->implode(' ');

        foreach ([$user->email, $user->name, $operator->email, 'my private note', 'BEGIN', 'passphrase', 'storage/', '.pem', 'CN=', 'serial'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all, "Audit metadata leaked [{$forbidden}].");
        }
    }

    public function test_audit_is_written_inside_the_workflow_transaction(): void
    {
        $user = $this->userWithRole();

        DB::beginTransaction();
        $this->workflow()->create($user);
        $this->assertSame(1, DB::table('audit_events')->count());
        DB::rollBack();

        // Rolling back the surrounding transaction removes the audit too:
        // proof it was written in the same unit of work, not out-of-band.
        $this->assertSame(0, DB::table('audit_events')->count());
    }
}
