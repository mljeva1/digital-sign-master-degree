<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\IssuanceFailureCode;
use App\Exceptions\CertificateRequests\TransientIssuanceException;
use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\CertificateRequests\CertificateIssuanceProcessor;
use App\Services\Signing\LocalSignerCertificateIssuanceService;
use App\Services\Signing\SignerCertificateRegistrar;
use App\Services\Signing\SigningConfig;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * M14 Phase B — the certificate-issuance worker lifecycle, with REAL native
 * OpenSSL issuance against a hermetic, freshly bootstrapped local signing root.
 */
final class CertificateIssuanceWorkerTest extends IssuanceWorkerTestCase
{
    private function processor(): CertificateIssuanceProcessor
    {
        return app(CertificateIssuanceProcessor::class);
    }

    public function test_happy_path_issues_binds_and_audits(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $operatorId = (int) $request->reviewed_by_user_id;

        $this->processor()->process((int) $request->getKey(), (string) $request->issuance_attempt_id);

        $fresh = $request->fresh();
        $this->assertSame(Status::ISSUED, $fresh->status);
        $this->assertNotNull($fresh->certificate_id);
        $this->assertNotNull($fresh->issued_at);
        $this->assertNotNull($fresh->issuance_started_at);
        $this->assertNull($fresh->failure_code);

        $certificate = Certificate::query()->findOrFail($fresh->certificate_id);
        $this->assertSame(Certificate::OWNER_TYPE_USER, $certificate->owner_type);
        $this->assertSame((int) $fresh->user_id, (int) $certificate->owner_user_id);
        $this->assertTrue((bool) $certificate->is_active);
        $this->assertTrue($certificate->isCurrentlyValid());

        // The subject's DN must carry no PII (shared generic subject).
        $this->assertStringNotContainsString('@', (string) $certificate->subject_dn);

        // Exactly one started + one completed audit, both by the operator actor.
        foreach ([CertificateIssuanceProcessor::AUDIT_STARTED, CertificateIssuanceProcessor::AUDIT_COMPLETED] as $action) {
            $row = DB::table('audit_events')->where('action', $action)->first();
            $this->assertNotNull($row, "missing {$action}");
            $this->assertSame($operatorId, (int) $row->actor_user_id);
            $this->assertEquals(1, (int) $row->success);
        }
    }

    public function test_completed_audit_metadata_is_allow_listed_and_leaks_nothing(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;

        $this->processor()->process((int) $request->getKey(), $attemptId);

        $metadata = DB::table('audit_events')
            ->where('action', CertificateIssuanceProcessor::AUDIT_COMPLETED)
            ->value('metadata');

        $decoded = json_decode((string) $metadata, true);
        $allowed = [
            'operation_name', 'old_status', 'new_status', 'certificate_request_id',
            'subject_user_id', 'operator_user_id', 'certificate_id',
        ];
        $this->assertSame([], array_diff(array_keys($decoded), $allowed), 'unexpected metadata key present');

        // The attempt UUID and any path/PEM/serial/DN must never appear.
        $this->assertStringNotContainsString($attemptId, (string) $metadata);
        $this->assertStringNotContainsStringIgnoringCase('BEGIN', (string) $metadata);
        $this->assertStringNotContainsStringIgnoringCase('serial', (string) $metadata);
    }

    public function test_signing_root_unavailable_fails_closed_terminally(): void
    {
        // Deliberately NOT bootstrapped: the shared root does not exist.
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;

        $this->processor()->process((int) $request->getKey(), $attemptId);

        $fresh = $request->fresh();
        $this->assertSame(Status::FAILED, $fresh->status);
        $this->assertSame(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE, $fresh->failure_code);
        $this->assertNotNull($fresh->failed_at);
        $this->assertNotNull($fresh->issuance_started_at, 'a failure must prove issuance started');
        $this->assertNull($fresh->certificate_id);
        $this->assertSame(0, Certificate::query()->count());

        $failed = DB::table('audit_events')->where('action', CertificateIssuanceProcessor::AUDIT_FAILED)->first();
        $this->assertNotNull($failed);
        $this->assertEquals(0, (int) $failed->success, 'failed audit must be success=false');
    }

    public function test_duplicate_delivery_after_issued_is_a_noop(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;

        $this->processor()->process((int) $request->getKey(), $attemptId);
        $certId = (int) $request->fresh()->certificate_id;

        // Second delivery of the SAME attempt must change nothing and create no
        // second certificate.
        $this->processor()->process((int) $request->getKey(), $attemptId);

        $this->assertSame($certId, (int) $request->fresh()->certificate_id);
        $this->assertSame(1, Certificate::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', CertificateIssuanceProcessor::AUDIT_COMPLETED)->count());
    }

    public function test_terminal_failed_request_is_never_reprocessed(): void
    {
        // First run without a root → terminal failed.
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;
        $this->processor()->process((int) $request->getKey(), $attemptId);
        $this->assertSame(Status::FAILED, $request->fresh()->status);

        // Even with a now-valid root, a terminal request is a pure no-op.
        $this->bootstrapSigningRoot();
        $this->processor()->process((int) $request->getKey(), $attemptId);

        $fresh = $request->fresh();
        $this->assertSame(Status::FAILED, $fresh->status);
        $this->assertNull($fresh->certificate_id);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_active_certificate_appearing_after_approval_blocks_issuance(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;

        // A still-valid active certificate appears for the subject post-approval.
        $this->activeCertificateFor($request->user);

        $this->processor()->process((int) $request->getKey(), $attemptId);

        $fresh = $request->fresh();
        $this->assertSame(Status::FAILED, $fresh->status);
        $this->assertSame(IssuanceFailureCode::ACTIVE_CERTIFICATE_EXISTS, $fresh->failure_code);
        $this->assertNull($fresh->certificate_id);
    }

    public function test_two_users_receive_distinct_certificates_from_one_signer_key(): void
    {
        $this->bootstrapSigningRoot();

        $first = $this->approvedRequest();
        $this->processor()->process((int) $first->getKey(), (string) $first->issuance_attempt_id);

        $second = $this->approvedRequest();
        $this->processor()->process((int) $second->getKey(), (string) $second->issuance_attempt_id);

        $certA = Certificate::query()->findOrFail($first->fresh()->certificate_id);
        $certB = Certificate::query()->findOrFail($second->fresh()->certificate_id);

        $this->assertNotSame($certA->thumbprint_sha256, $certB->thumbprint_sha256, 'distinct fingerprints');
        $this->assertNotSame($certA->serial_number, $certB->serial_number, 'distinct serials');
        $this->assertNotSame((int) $certA->owner_user_id, (int) $certB->owner_user_id);
        $this->assertSame(2, Certificate::query()->count());
    }

    public function test_transient_registrar_failure_keeps_request_issuing_and_stores_no_raw_message(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;

        // A registrar that fails with a WRAPPED deadlock (transient).
        $transientRegistrar = new class(app(SigningConfig::class)) extends SignerCertificateRegistrar
        {
            public function register(User $owner, string $certificateInputPath, ?callable $onPersisted = null): Certificate
            {
                $pdo = new PDOException('SQLSTATE[40P01]: deadlock detected SECRET-RAW-TEXT', 0);
                $pdo->errorInfo = ['40P01', 7, 'deadlock detected SECRET-RAW-TEXT'];

                throw RegistrationException::of(
                    RegistrationException::CERTIFICATE_PERSISTENCE_FAILED,
                    new QueryException('pgsql', 'insert ...', [], $pdo),
                );
            }
        };

        $processor = new CertificateIssuanceProcessor(
            app(AuditLogger::class),
            app(LocalSignerCertificateIssuanceService::class),
            $transientRegistrar,
        );

        $threw = false;
        try {
            $processor->process((int) $request->getKey(), $attemptId);
        } catch (TransientIssuanceException) {
            $threw = true; // rethrown so the queue retries the same attempt
        }
        $this->assertTrue($threw, 'a wrapped deadlock must surface as a transient retry');

        $fresh = $request->fresh();
        $this->assertSame(Status::ISSUING, $fresh->status, 'a transient failure never leaves issuing');
        $this->assertNull($fresh->failure_code);
        $this->assertNull($fresh->failed_at);
        $this->assertNull($fresh->certificate_id);
        $this->assertSame(0, DB::table('audit_events')->where('action', CertificateIssuanceProcessor::AUDIT_FAILED)->count());

        // The raw driver text is never persisted anywhere.
        $this->assertSame(0, DB::table('audit_events')->where('metadata', 'like', '%SECRET-RAW-TEXT%')->count());
        $this->assertStringNotContainsString('SECRET-RAW-TEXT', (string) DB::table('certificate_requests')->where('id', $request->getKey())->value('failure_code'));

        // The retry with the real registrar completes the SAME attempt.
        app(CertificateIssuanceProcessor::class)->process((int) $request->getKey(), $attemptId);
        $this->assertSame(Status::ISSUED, $request->fresh()->status);
    }

    public function test_config_divergence_pgsql_lock_wording_is_terminal_never_a_retry(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;

        // Resolve (and cache) a REAL pgsql connection object, then mutate config to
        // falsely claim it is sqlite. This is the exact divergence Codex proved.
        config(['database.connections.worker_divergence' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
            'database' => 'unused', 'username' => 'unused', 'password' => 'unused',
        ]]);
        $this->assertSame('pgsql', DB::connection('worker_divergence')->getDriverName());
        config(['database.connections.worker_divergence.driver' => 'sqlite']);

        // The registrar raises a genuine PostgreSQL failure whose driver text merely
        // contains the sqlite lock wording (SQLSTATE XX000 is permanent).
        $registrar = new class(app(SigningConfig::class)) extends SignerCertificateRegistrar
        {
            public function register(User $owner, string $certificateInputPath, ?callable $onPersisted = null): Certificate
            {
                $pdo = new PDOException('SQLSTATE[XX000]: Internal error: database is locked RAW-LEAK', 0);
                $pdo->errorInfo = ['XX000', 7, 'database is locked RAW-LEAK'];

                throw new QueryException('worker_divergence', 'insert ...', [], $pdo);
            }
        };
        $processor = new CertificateIssuanceProcessor(
            app(AuditLogger::class),
            app(LocalSignerCertificateIssuanceService::class),
            $registrar,
        );

        // Must NOT rethrow as transient: a config lie can never turn a permanent
        // PostgreSQL failure into a retry that burns the budget as RETRIES_EXHAUSTED.
        $processor->process((int) $request->getKey(), $attemptId);

        $fresh = $request->fresh();
        $this->assertSame(Status::FAILED, $fresh->status, 'a permanent failure never stays issuing for retry');
        $this->assertNotSame(IssuanceFailureCode::RETRIES_EXHAUSTED, $fresh->failure_code, 'must not substitute a real permanent failure with exhausted retries');
        $this->assertSame(IssuanceFailureCode::FAILED, $fresh->failure_code);
        $this->assertNull($fresh->certificate_id);
        $this->assertSame(0, Certificate::query()->count());

        // No raw driver/connection detail leaks into the failure_code or the audit.
        $this->assertStringNotContainsString('RAW-LEAK', (string) $fresh->failure_code);
        $this->assertStringNotContainsString('database is locked', (string) $fresh->failure_code);
        $this->assertSame(0, DB::table('audit_events')->where('metadata', 'like', '%RAW-LEAK%')->count());
        $this->assertSame(0, DB::table('audit_events')->where('metadata', 'like', '%database is locked%')->count());
    }

    public function test_permanent_failure_on_a_loser_never_deletes_winner_or_creates_a_second_certificate(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;
        $requestId = (int) $request->getKey();

        // A SEPARATE winner invocation writes the attempt artefact first.
        $winner = app(LocalSignerCertificateIssuanceService::class)->issueAttemptCertificate($requestId, $attemptId);
        $artefact = $this->signingRoot.DIRECTORY_SEPARATOR.'issuance'.DIRECTORY_SEPARATOR.'req-'.$requestId.'-att-'.$attemptId.'.pem';
        $this->assertTrue($winner->createdByCurrentInvocation);
        $this->assertFileExists($artefact);

        // The processor's own issuance is therefore a LOSER (created=false); its
        // registrar then fails PERMANENTLY (key mismatch, not a transient DB lock).
        $permanentRegistrar = new class(app(SigningConfig::class)) extends SignerCertificateRegistrar
        {
            public function register(User $owner, string $certificateInputPath, ?callable $onPersisted = null): Certificate
            {
                throw RegistrationException::of(RegistrationException::CERTIFICATE_KEY_MISMATCH);
            }
        };
        $processor = new CertificateIssuanceProcessor(
            app(AuditLogger::class),
            app(LocalSignerCertificateIssuanceService::class),
            $permanentRegistrar,
        );

        $processor->process($requestId, $attemptId);

        $fresh = $request->fresh();
        $this->assertSame(Status::FAILED, $fresh->status);
        $this->assertSame(IssuanceFailureCode::CERTIFICATE_INVALID, $fresh->failure_code);
        $this->assertNull($fresh->certificate_id, 'no fabricated issued binding');
        $this->assertSame(0, Certificate::query()->count(), 'no second Certificate');
        $this->assertFileExists($artefact, 'a loser permanent failure must never delete the winner artefact');
    }

    public function test_retries_exhausted_marks_terminal_failed(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;

        // Claim it into `issuing` first (a transient-style path leaves it here).
        DB::table('certificate_requests')->where('id', $request->getKey())->update([
            'status' => Status::ISSUING,
            'issuance_started_at' => now(),
        ]);

        $this->processor()->markRetriesExhausted((int) $request->getKey(), $attemptId);

        $fresh = $request->fresh();
        $this->assertSame(Status::FAILED, $fresh->status);
        $this->assertSame(IssuanceFailureCode::RETRIES_EXHAUSTED, $fresh->failure_code);
        $this->assertNull($fresh->certificate_id);
    }

    public function test_retries_exhausted_never_touches_an_issued_request(): void
    {
        $this->bootstrapSigningRoot();
        $request = $this->approvedRequest();
        $attemptId = (string) $request->issuance_attempt_id;
        $this->processor()->process((int) $request->getKey(), $attemptId);
        $this->assertSame(Status::ISSUED, $request->fresh()->status);

        // A late exhausted-retry callback must not revive/overwrite a success.
        $this->processor()->markRetriesExhausted((int) $request->getKey(), $attemptId);

        $this->assertSame(Status::ISSUED, $request->fresh()->status);
        $this->assertNull($request->fresh()->failure_code);
    }
}
