<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\ContractSigningException;
use App\Exceptions\Signing\DetachedCmsException;
use App\Models\AuditEvent;
use App\Models\Certificate;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Signing\ContractSigningRequest;
use App\Services\Signing\ContractSigningResult;
use App\Services\Signing\ContractSigningService;
use App\Services\Signing\DetachedCmsSignatureResult;
use App\Services\Signing\DetachedCmsSigner;
use App\Services\Signing\DetachedCmsSignRequest;
use App\Services\Signing\DetachedCmsVerificationRequest;
use App\Services\Signing\DetachedCmsVerifier;
use App\Services\Signing\FinalPdfIntegrityVerifier;
use App\Services\Signing\FinalPdfVerificationBindingVerifier;
use App\Services\Signing\SigningConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * M11 — signing orchestration and persistence (Codex P1/P2 corrections).
 *
 * Real native-OpenSSL signing over a real final-PDF artefact, with deterministic
 * seam-based race/stale/failure injection (no sleep()). Extends the M10
 * SigningTestCase (ephemeral PKI, temp disk); adds contracts/signatures/
 * audit_events to the hand-built SQLite schema — application behaviour + the
 * SQLite partial unique index only, NOT a proof of PostgreSQL CHECK/FK/concurrency
 * (those live behind the opt-in SignatureSourceBindingSchemaTest gate).
 */
final class ContractSigningServiceTest extends ContractSigningTestCase
{
    // --- fixtures -----------------------------------------------------------

    private function service(): ContractSigningService
    {
        return app(ContractSigningService::class);
    }

    private function serviceWith(DetachedCmsSigner $signer): ContractSigningService
    {
        return new ContractSigningService(
            $signer,
            app(AuditLogger::class),
            app(FinalPdfIntegrityVerifier::class),
            app(AuthFactory::class),
            app(FinalPdfVerificationBindingVerifier::class),
        );
    }

    /**
     * A finalized, locked contract owned by an authenticated user who has one
     * active signer certificate, plus a valid final PDF. The owner is signed in.
     *
     * @return array{ctx: array, user: User, contract: Contract, finalPdf: StoredFile, bytes: string, sha: string}
     */
    private function signableFixture(string $marker = 'happy'): array
    {
        $ctx = $this->registerValidSigner();
        $user = $ctx['user'];
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        $bytes = $this->pdfBytes($marker);
        $finalPdf = $this->attachFinalPdf($contract, $user, $bytes);
        $this->activateVerification($contract, $finalPdf);

        return ['ctx' => $ctx, 'user' => $user, 'contract' => $contract, 'finalPdf' => $finalPdf, 'bytes' => $bytes, 'sha' => hash('sha256', $bytes)];
    }

    /**
     * Configurable seam service for deterministic race/stale/failure injection.
     *
     * @param  array<string, mixed>  $opts
     */
    private function seamService(array $opts, ?DetachedCmsSigner $signer = null, ?AuditLogger $audit = null): ContractSigningService
    {
        return new class($signer ?? app(DetachedCmsSigner::class), $audit ?? app(AuditLogger::class), app(FinalPdfIntegrityVerifier::class), app(AuthFactory::class), app(FinalPdfVerificationBindingVerifier::class), $opts) extends ContractSigningService
        {
            private int $bindingLookupCalls = 0;

            /** @param array<string, mixed> $opts */
            public function __construct(DetachedCmsSigner $signer, AuditLogger $audit, FinalPdfIntegrityVerifier $v, AuthFactory $auth, FinalPdfVerificationBindingVerifier $b, private readonly array $opts)
            {
                parent::__construct($signer, $audit, $v, $auth, $b);
            }

            protected function beforePersistence(Contract $contract, Certificate $certificate, StoredFile $finalPdf): void
            {
                if (isset($this->opts['beforePersistence'])) {
                    ($this->opts['beforePersistence'])($contract, $certificate, $finalPdf);
                }
            }

            protected function recheckExistingSignature(int $contractId, int $actorUserId, int $sourceFileId): ?Signature
            {
                if (($this->opts['recheckNull'] ?? false) === true) {
                    return null;
                }

                return parent::recheckExistingSignature($contractId, $actorUserId, $sourceFileId);
            }

            protected function resolveRaceWinner(int $contractId, int $actorUserId, int $sourceFileId): ?Signature
            {
                if (($this->opts['throwRaceWinner'] ?? false) === true) {
                    throw new RuntimeException('injected winner lookup failure');
                }
                if (isset($this->opts['raceWinner'])) {
                    return ($this->opts['raceWinner'])();
                }

                return parent::resolveRaceWinner($contractId, $actorUserId, $sourceFileId);
            }

            protected function findSignatureForReconciliation(int $contractId, int $actorUserId, int $sourceFileId): ?Signature
            {
                if (($this->opts['throwAmbiguousReconciliationLookup'] ?? false) === true) {
                    throw new RuntimeException('injected ambiguous reconciliation lookup failure');
                }

                return parent::findSignatureForReconciliation($contractId, $actorUserId, $sourceFileId);
            }

            protected function putCmsBytes(Filesystem $disk, string $path, string $der): bool
            {
                if (($this->opts['throwPut'] ?? false) === true) {
                    throw new RuntimeException('injected write failure');
                }
                if (($this->opts['failPut'] ?? false) === true) {
                    return false;
                }

                return parent::putCmsBytes($disk, $path, $der);
            }

            protected function afterCmsWritten(Filesystem $disk, string $path): void
            {
                if (($this->opts['corrupt'] ?? false) === true) {
                    $disk->put($path, 'CORRUPTED-CMS-BYTES-NOT-THE-SIGNED-DER');
                }
                if (($this->opts['removeAfterWrite'] ?? false) === true) {
                    $disk->delete($path);
                }
            }

            protected function signatureForValidation(int $signatureId): ?Signature
            {
                if (($this->opts['throwValidationStep'] ?? null) === 'signature') {
                    throw new RuntimeException('injected Signature lookup failure');
                }

                return parent::signatureForValidation($signatureId);
            }

            protected function contractForValidation(int $contractId): ?Contract
            {
                if (($this->opts['throwValidationStep'] ?? null) === 'contract') {
                    throw new RuntimeException('injected Contract lookup failure');
                }

                return parent::contractForValidation($contractId);
            }

            protected function storedFileForValidation(int $storedFileId, string $role): ?StoredFile
            {
                if (($this->opts['throwValidationStep'] ?? null) === $role) {
                    throw new RuntimeException("injected {$role} StoredFile lookup failure");
                }

                return parent::storedFileForValidation($storedFileId, $role);
            }

            protected function certificateForValidation(int $certificateId): ?Certificate
            {
                if (($this->opts['throwValidationStep'] ?? null) === 'certificate') {
                    throw new RuntimeException('injected Certificate lookup failure');
                }

                return parent::certificateForValidation($certificateId);
            }

            protected function storagePathForValidation(Filesystem $disk, string $storagePath): string
            {
                if (($this->opts['throwValidationStep'] ?? null) === 'storage_path') {
                    throw new RuntimeException('injected Storage::path failure');
                }

                return parent::storagePathForValidation($disk, $storagePath);
            }

            protected function physicalBytesForValidation(Filesystem $disk, string $storagePath): string
            {
                if (($this->opts['throwValidationStep'] ?? null) === 'physical_read') {
                    throw new RuntimeException('injected physical read failure');
                }

                return parent::physicalBytesForValidation($disk, $storagePath);
            }

            protected function physicalSizeForValidation(string $absolutePath): int|false
            {
                if (($this->opts['throwValidationStep'] ?? null) === 'physical_size') {
                    throw new RuntimeException('injected physical size failure');
                }

                return parent::physicalSizeForValidation($absolutePath);
            }

            protected function physicalShaForValidation(string $absolutePath): string|false
            {
                if (($this->opts['throwValidationStep'] ?? null) === 'physical_sha') {
                    throw new RuntimeException('injected physical SHA failure');
                }

                return parent::physicalShaForValidation($absolutePath);
            }

            protected function deleteProvisional(Filesystem $disk, string $path): bool
            {
                if (($this->opts['failCleanup'] ?? false) === true) {
                    return false;
                }

                return parent::deleteProvisional($disk, $path);
            }

            protected function recordSigningAudit(Contract $contract, int $actorUserId, array $metadata): void
            {
                if (($this->opts['failAudit'] ?? false) === true) {
                    throw new RuntimeException('injected audit failure');
                }

                parent::recordSigningAudit($contract, $actorUserId, $metadata);
            }

            protected function verificationBindingExists(Contract $contract, int $finalPdfFileId, string $finalPdfSha256, string $token): bool
            {
                $this->bindingLookupCalls++;
                if (($this->opts['throwBindingLookupOnSecondCall'] ?? false) === true
                    && $this->bindingLookupCalls === 2) {
                    throw new RuntimeException('injected exact-proof lookup failure');
                }

                return parent::verificationBindingExists($contract, $finalPdfFileId, $finalPdfSha256, $token);
            }

            protected function runInTransaction(\Closure $callback)
            {
                // LOST COMMIT ACK: the callback AND the COMMIT really succeed, then
                // the driver throws — the app cannot tell whether it committed.
                if (($this->opts['lostCommitAck'] ?? false) === true) {
                    parent::runInTransaction($callback);

                    throw new RuntimeException('injected lost commit acknowledgement');
                }

                // AMBIGUOUS BUT ROLLED BACK: the callback completes, then the
                // transaction rolls back; the app still cannot tell.
                if (($this->opts['ambiguousRollback'] ?? false) === true) {
                    try {
                        parent::runInTransaction(function () use ($callback): void {
                            $callback();

                            throw new RuntimeException('force rollback after callback completed');
                        });
                    } catch (\Throwable) {
                        // swallowed: emulate an opaque driver failure
                    }

                    throw new RuntimeException('injected ambiguous outcome');
                }

                return parent::runInTransaction($callback);
            }
        };
    }

    /** A signer stub that always throws a stable M10 CMS failure. */
    private function throwingSigner(string $code, bool $compensationIncomplete = false): DetachedCmsSigner
    {
        return new class($this->signingConfig(), $code, $compensationIncomplete) extends DetachedCmsSigner
        {
            public function __construct(SigningConfig $config, private readonly string $code, private readonly bool $incomplete)
            {
                parent::__construct($config);
            }

            public function sign(DetachedCmsSignRequest $request): DetachedCmsSignatureResult
            {
                $e = DetachedCmsException::of($this->code);
                if ($this->incomplete) {
                    $e->markCompensationIncomplete();
                }

                throw $e;
            }
        };
    }

    /** A signer that delegates to the real signer but counts invocations. */
    private function countingSigner(): DetachedCmsSigner
    {
        return new class($this->signingConfig()) extends DetachedCmsSigner
        {
            public int $calls = 0;

            public function sign(DetachedCmsSignRequest $request): DetachedCmsSignatureResult
            {
                $this->calls++;

                return parent::sign($request);
            }
        };
    }

    /** Exposes the protected unique-race classifier for a table-driven matrix. */
    private function classifier(): object
    {
        return new class(app(DetachedCmsSigner::class), app(AuditLogger::class), app(FinalPdfIntegrityVerifier::class), app(AuthFactory::class), app(FinalPdfVerificationBindingVerifier::class)) extends ContractSigningService
        {
            public function classify(QueryException $e): bool
            {
                return $this->isActiveSignatureUniqueViolation($e);
            }
        };
    }

    /**
     * @param  array<int, mixed>|null  $errorInfo
     */
    private function queryException(int $code, ?array $errorInfo): QueryException
    {
        $pdo = new PDOException('driver message', $code);
        if ($errorInfo !== null) {
            $pdo->errorInfo = $errorInfo;
        }

        return new QueryException('conn', 'insert into "signatures" ...', [], $pdo);
    }

    private function insertCompetingSignature(Contract $contract, User $user, int $sourceFileId, int $certificateId): Signature
    {
        $der = "\x30\x82".random_bytes(20);
        $path = "contracts/{$contract->id}/signatures/winner-".bin2hex(random_bytes(6)).'.p7s';
        Storage::disk(StoredFile::DISK_LOCAL)->put($path, $der);
        $cms = StoredFile::create([
            'purpose' => StoredFile::PURPOSE_CMS_SIGNATURE, 'storage_disk' => StoredFile::DISK_LOCAL, 'storage_path' => $path,
            'original_filename' => 'winner.p7s', 'mime_type' => 'application/pkcs7-signature',
            'size_bytes' => strlen($der), 'sha256' => hash('sha256', $der), 'created_by_user_id' => $user->id,
        ]);

        $sha = strtolower((string) $contract->final_pdf_sha256);

        return Signature::create([
            'contract_id' => $contract->id, 'contract_party_id' => null, 'certificate_id' => $certificateId,
            'signed_user_id' => $user->id, 'signed_customer_id' => null, 'source_file_id' => $sourceFileId,
            'signature_file_id' => $cms->id, 'type' => Signature::TYPE_DIGITAL, 'status' => Signature::STATUS_COMPLETED,
            'signed_at' => now(), 'document_hash_before' => $sha, 'document_hash_after' => $sha,
        ]);
    }

    private function assertNothingSigned(): void
    {
        $this->assertSame(0, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count(), 'no completed Signature');
        $this->assertSame(0, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count(), 'no CMS StoredFile');
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.signature_completed')->count(), 'no success audit');
        $this->assertSame(0, $this->p7sCount(), 'no leftover .p7s artefact');
    }

    private function assertFails(string $code, callable $fn, ?bool $compensationIncomplete = null): ContractSigningException
    {
        try {
            $fn();
            $this->fail("Expected signing failure {$code}, but it succeeded.");
        } catch (ContractSigningException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertStringNotContainsString($this->tempDir, $e->getMessage());
            $this->assertStringNotContainsString('contracts/', $e->getMessage());
            $this->assertStringNotContainsString('.p7s', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('error:0', $e->getMessage());
            if ($compensationIncomplete !== null) {
                $this->assertSame($compensationIncomplete, $e->compensationIncomplete());
            }

            return $e;
        }
    }

    private function request(Contract $contract): ContractSigningRequest
    {
        return new ContractSigningRequest((int) $contract->id);
    }

    /**
     * Write a public-verification generation proof exactly as the generator does,
     * with optional field overrides to simulate a mismatched/forged record.
     *
     * @param  array<string, mixed>  $override
     */
    private function seedGenerationProof(Contract $contract, int $fileId, string $pdfSha, string $token, array $override = []): void
    {
        $binding = app(FinalPdfVerificationBindingVerifier::class);

        app(AuditLogger::class)->record('contract.final_pdf_generated', $contract, [
            'contract_id' => (int) $contract->id,
            'file_id' => $override['fileId'] ?? $fileId,
            'final_pdf_file_id' => $override['fileId'] ?? $fileId,
            'final_pdf_sha256' => $override['pdfSha'] ?? $pdfSha,
            'generation_reason' => $override['reason'] ?? FinalPdfVerificationBindingVerifier::GENERATION_REASON,
            'public_verification_token_sha256' => $override['tokenHash'] ?? $binding->tokenHash($token),
            'verification_url_sha256' => $override['urlHash'] ?? $binding->urlHash($binding->canonicalVerificationUrl($token)),
        ], null, (int) $contract->created_by_user_id);
    }

    private function activateVerification(Contract $contract, StoredFile $finalPdf): string
    {
        $token = bin2hex(random_bytes(32));
        $contract->public_verification_token = $token;
        $contract->public_verification_enabled_at = now();
        $contract->public_verification_revoked_at = null;
        $contract->save();
        $this->seedGenerationProof($contract, (int) $finalPdf->id, (string) $finalPdf->sha256, $token);

        return $token;
    }

    // --- happy path ---------------------------------------------------------

    public function test_finalized_contract_is_signed_and_persisted(): void
    {
        $f = $this->signableFixture();

        $result = $this->service()->sign($this->request($f['contract']));

        $this->assertInstanceOf(ContractSigningResult::class, $result);
        $this->assertFalse($result->idempotentExisting);
        $this->assertSame((int) $f['finalPdf']->id, $result->sourceFileId);
        $this->assertSame($f['sha'], $result->sourceSha256);
        $this->assertSame($this->fingerprint($f['ctx']['signer']['cert']), $result->signerFingerprint);
        $this->assertSame((int) $f['ctx']['certificate']->id, $result->certificateId);

        $sig = Signature::findOrFail($result->signatureId);
        $this->assertSame(Signature::STATUS_COMPLETED, $sig->status);
        $this->assertSame(Signature::TYPE_DIGITAL, $sig->type);
        // signed_user_id is the AUTHENTICATED actor.
        $this->assertSame((int) $f['user']->id, (int) $sig->signed_user_id);
        $this->assertSame((int) $f['finalPdf']->id, (int) $sig->source_file_id);
        $this->assertSame((int) $f['ctx']['certificate']->id, (int) $sig->certificate_id);
        $this->assertNotNull($sig->signature_file_id);
        $this->assertNotNull($sig->signed_at);
        $this->assertSame($f['sha'], strtolower($sig->document_hash_before));
        $this->assertSame(strtolower($sig->document_hash_before), strtolower($sig->document_hash_after));

        $cms = StoredFile::findOrFail($sig->signature_file_id);
        $this->assertSame(StoredFile::PURPOSE_CMS_SIGNATURE, $cms->purpose);
        $this->assertSame(StoredFile::DISK_LOCAL, $cms->storage_disk);
        $this->assertStringEndsWith('.p7s', $cms->storage_path);
        $this->assertSame('application/pkcs7-signature', $cms->mime_type);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($cms->storage_path));
        $der = Storage::disk(StoredFile::DISK_LOCAL)->get($cms->storage_path);
        $this->assertSame(strlen($der), (int) $cms->size_bytes);
        $this->assertSame(hash('sha256', $der), $cms->sha256);
        $this->assertSame(0x30, ord($der[0]));

        // Audit metadata is sanitized AND the audit actor is the same authenticated
        // identity as signed_user_id.
        $audit = AuditEvent::query()->where('action', 'contract.signature_completed')->latest('id')->firstOrFail();
        $this->assertSame((int) $f['user']->id, (int) $audit->actor_user_id);
        $this->assertSame((int) $sig->signed_user_id, (int) $audit->actor_user_id);
        $this->assertSame((int) $f['contract']->id, (int) $audit->metadata['contract_id']);
        $this->assertSame((int) $sig->id, (int) $audit->metadata['signature_id']);
        $this->assertSame($f['sha'], $audit->metadata['source_sha256']);
        $this->assertSame($result->signerFingerprint, $audit->metadata['signer_certificate_fingerprint']);
        foreach ($audit->metadata as $value) {
            $this->assertNotSame('[REDACTED]', $value);
        }

        $verification = app(DetachedCmsVerifier::class)->verify(new DetachedCmsVerificationRequest(
            sourcePath: Storage::disk(StoredFile::DISK_LOCAL)->path($f['finalPdf']->storage_path),
            cmsDer: $der,
            rootCaPath: (string) config('signing.root_ca_path'),
            expectedSignerFingerprint: $result->signerFingerprint,
            expectedSourceHash: $result->sourceSha256,
            certificateActive: true,
        ));
        $this->assertTrue($verification->isValid());

        $this->assertSame(1, $this->p7sCount());
    }

    // --- P1: authenticated actor boundary ----------------------------------

    public function test_guest_is_not_authorized(): void
    {
        $f = $this->signableFixture();
        app(AuthFactory::class)->guard()->logout();

        $this->assertFails(ContractSigningException::SIGNING_NOT_AUTHORIZED, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_attacker_cannot_sign_victim_contract(): void
    {
        $victim = $this->signableFixture();
        $attacker = User::factory()->create();
        $this->actingAs($attacker);

        // Even authenticated, the attacker does not own the victim's contract.
        $this->assertFails(ContractSigningException::SIGNING_NOT_AUTHORIZED, fn () => $this->service()->sign($this->request($victim['contract'])));
        $this->assertNothingSigned();
    }

    public function test_attacker_cannot_reach_the_signer_at_all(): void
    {
        $victim = $this->signableFixture();
        $this->actingAs(User::factory()->create());

        // A throw-if-called signer proves the signer is never reached.
        $exploding = new class($this->signingConfig()) extends DetachedCmsSigner
        {
            public int $calls = 0;

            public function sign(DetachedCmsSignRequest $request): DetachedCmsSignatureResult
            {
                $this->calls++;

                throw new RuntimeException('signer must never be invoked for an unauthorized actor');
            }
        };

        $this->assertFails(ContractSigningException::SIGNING_NOT_AUTHORIZED, fn () => $this->serviceWith($exploding)->sign($this->request($victim['contract'])));
        $this->assertSame(0, $exploding->calls, 'signer call count must be exactly 0');
        $this->assertNothingSigned();
    }

    public function test_signing_uses_the_injected_guard_actor_even_if_the_global_auth_context_differs(): void
    {
        // The owner is the injected-guard actor; a DIFFERENT user is left in the
        // global auth context. Signing and the audit must both follow the resolved
        // owner, never the divergent global context.
        $f = $this->signableFixture();
        $owner = $f['user'];
        $other = User::factory()->create();

        // Global auth context = a DIFFERENT user; the injected factory = the owner.
        $this->actingAs($other);

        $ownerGuard = new class($owner) implements Guard
        {
            public function __construct(private Authenticatable $u) {}

            public function check(): bool
            {
                return true;
            }

            public function guest(): bool
            {
                return false;
            }

            public function user()
            {
                return $this->u;
            }

            public function id()
            {
                return $this->u->getAuthIdentifier();
            }

            public function validate(array $credentials = []): bool
            {
                return true;
            }

            public function hasUser(): bool
            {
                return true;
            }

            public function setUser(Authenticatable $user)
            {
                $this->u = $user;
            }
        };

        $ownerFactory = new class($ownerGuard) implements AuthFactory
        {
            public function __construct(private Guard $g) {}

            public function guard($name = null)
            {
                return $this->g;
            }

            public function shouldUse($name) {}
        };

        $service = new ContractSigningService(
            app(DetachedCmsSigner::class),
            app(AuditLogger::class),
            app(FinalPdfIntegrityVerifier::class),
            $ownerFactory,
            app(FinalPdfVerificationBindingVerifier::class),
        );

        $result = $service->sign($this->request($f['contract']));

        $sig = Signature::findOrFail($result->signatureId);
        $audit = AuditEvent::query()->where('action', 'contract.signature_completed')->latest('id')->firstOrFail();
        $this->assertSame((int) $owner->id, (int) $sig->signed_user_id);
        $this->assertSame((int) $owner->id, (int) $audit->actor_user_id);
        $this->assertNotSame((int) $other->id, (int) $audit->actor_user_id);
        $this->assertSame((int) $sig->signed_user_id, (int) $audit->actor_user_id);
    }

    public function test_missing_contract_fails_closed_for_authenticated_owner(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);

        $this->assertFails(ContractSigningException::CONTRACT_NOT_FOUND, fn () => $this->service()->sign(new ContractSigningRequest(999999)));
        $this->assertNothingSigned();
    }

    // --- authorization / state ---------------------------------------------

    public function test_non_finalized_contract_is_not_signable(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);
        $contract = $this->seedContract($ctx['user'], Contract::STATUS_DRAFT);
        $this->attachFinalPdf($contract, $ctx['user'], $this->pdfBytes());

        $this->assertFails(ContractSigningException::CONTRACT_NOT_SIGNABLE, fn () => $this->service()->sign($this->request($contract)));
        $this->assertNothingSigned();
    }

    public function test_archived_contract_is_not_signable(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);
        $contract = $this->seedContract($ctx['user'], Contract::STATUS_ARCHIVED);
        $this->attachFinalPdf($contract, $ctx['user'], $this->pdfBytes());

        $this->assertFails(ContractSigningException::CONTRACT_NOT_SIGNABLE, fn () => $this->service()->sign($this->request($contract)));
        $this->assertNothingSigned();
    }

    public function test_unlocked_finalized_contract_is_not_signable(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);
        $contract = $this->seedContract($ctx['user'], Contract::STATUS_FINALIZED, locked: false);
        $this->attachFinalPdf($contract, $ctx['user'], $this->pdfBytes());

        $this->assertFails(ContractSigningException::CONTRACT_NOT_SIGNABLE, fn () => $this->service()->sign($this->request($contract)));
        $this->assertNothingSigned();
    }

    public function test_missing_final_pdf_fails_closed(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);
        $contract = $this->seedContract($ctx['user']);

        $this->assertFails(ContractSigningException::FINAL_PDF_MISSING, fn () => $this->service()->sign($this->request($contract)));
        $this->assertNothingSigned();
    }

    public function test_stale_final_pdf_hash_fails_closed(): void
    {
        $f = $this->signableFixture();
        $f['contract']->final_pdf_sha256 = str_repeat('a', 64);
        $f['contract']->save();

        $this->assertFails(ContractSigningException::FINAL_PDF_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_missing_signer_certificate_fails_closed(): void
    {
        $this->configureSigning($this->newKey(), self::PASSPHRASE, $this->newRootCa()['pem']);
        $user = User::factory()->create();
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes());
        $this->activateVerification($contract, $finalPdf);

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_MISSING, fn () => $this->service()->sign($this->request($contract)));
        $this->assertNothingSigned();
    }

    public function test_inactive_certificate_leaves_no_active_candidate(): void
    {
        $f = $this->signableFixture();
        $f['ctx']['certificate']->is_active = false;
        $f['ctx']['certificate']->save();

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_MISSING, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_another_users_certificate_is_never_selected(): void
    {
        $this->registerValidSigner(); // userB gets an active certificate
        $userA = User::factory()->create();
        $this->actingAs($userA);
        $contract = $this->seedContract($userA);
        $finalPdf = $this->attachFinalPdf($contract, $userA, $this->pdfBytes());
        $this->activateVerification($contract, $finalPdf);

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_MISSING, fn () => $this->service()->sign($this->request($contract)));
        $this->assertNothingSigned();
    }

    public function test_multiple_active_certificates_are_ambiguous(): void
    {
        $f = $this->signableFixture();
        $this->seedActiveCertificate($f['user'], $this->issueCertificate($f['ctx']['ca']));

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_AMBIGUOUS, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    // --- integrity / signing failure ---------------------------------------

    public function test_missing_physical_final_pdf_fails_closed(): void
    {
        $f = $this->signableFixture();
        Storage::disk(StoredFile::DISK_LOCAL)->delete($f['finalPdf']->storage_path);

        $this->assertFails(ContractSigningException::FINAL_PDF_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_final_pdf_size_mismatch_fails_closed(): void
    {
        $f = $this->signableFixture();
        $f['finalPdf']->size_bytes = (int) $f['finalPdf']->size_bytes + 10;
        $f['finalPdf']->save();

        $this->assertFails(ContractSigningException::FINAL_PDF_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_modified_physical_final_pdf_fails_closed(): void
    {
        $f = $this->signableFixture();
        Storage::disk(StoredFile::DISK_LOCAL)->put($f['finalPdf']->storage_path, str_repeat('X', strlen($f['bytes'])));

        $this->assertFails(ContractSigningException::FINAL_PDF_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_signer_failure_is_wrapped_and_persists_nothing(): void
    {
        $f = $this->signableFixture();
        $service = $this->serviceWith($this->throwingSigner(DetachedCmsException::CMS_SIGN_FAILED));

        $e = $this->assertFails(ContractSigningException::SIGNING_FAILED, fn () => $service->sign($this->request($f['contract'])));
        $this->assertSame(DetachedCmsException::CMS_SIGN_FAILED, $e->signerCode());
        $this->assertNothingSigned();
    }

    public function test_signer_compensation_incomplete_signal_is_preserved(): void
    {
        $f = $this->signableFixture();
        $service = $this->serviceWith($this->throwingSigner(DetachedCmsException::CMS_TEMP_CLEANUP_INCOMPLETE, compensationIncomplete: true));

        $e = $this->assertFails(ContractSigningException::SIGNING_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: true);
        $this->assertSame(DetachedCmsException::CMS_TEMP_CLEANUP_INCOMPLETE, $e->signerCode());
        $this->assertNothingSigned();
    }

    public function test_corrupted_stored_certificate_makes_signer_fail_closed(): void
    {
        $f = $this->signableFixture();
        $certFile = StoredFile::findOrFail($f['ctx']['certificate']->file_id);
        $this->certificateFilesystem()->put($certFile->storage_path, "-----BEGIN CERTIFICATE-----\nnot-a-real-cert\n-----END CERTIFICATE-----\n");

        $e = $this->assertFails(ContractSigningException::SIGNING_FAILED, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertSame(DetachedCmsException::CMS_CERTIFICATE_INVALID, $e->signerCode());
        $this->assertNothingSigned();
    }

    // --- P2: storage / DB compensation -------------------------------------

    public function test_storage_write_failure_persists_nothing(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['failPut' => true]);

        $this->assertFails(ContractSigningException::CMS_STORAGE_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: false);
        $this->assertNothingSigned();
    }

    public function test_storage_write_failure_without_an_artefact_needs_no_cleanup(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['failPut' => true, 'failCleanup' => true]);

        $this->assertFails(ContractSigningException::CMS_STORAGE_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: false);
        $this->assertSame(0, Signature::query()->count());
        $this->assertSame(0, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
    }

    public function test_write_exception_without_an_artefact_needs_no_cleanup(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['throwPut' => true, 'failCleanup' => true]);

        $this->assertFails(ContractSigningException::CMS_STORAGE_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: false);
        $this->assertNothingSigned();
    }

    public function test_unproven_physical_cms_mismatch_is_retained_and_flagged(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['corrupt' => true]);

        $this->assertFails(ContractSigningException::CMS_STORAGE_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: true);
        $this->assertSame(0, Signature::query()->count());
        $this->assertSame(0, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertSame(1, $this->p7sCount(), 'unowned mismatched bytes are retained, never deleted');
    }

    public function test_physical_cms_mismatch_with_cleanup_failure_flags_incomplete(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['corrupt' => true, 'failCleanup' => true]);

        $this->assertFails(ContractSigningException::CMS_STORAGE_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: true);
    }

    public function test_cms_missing_after_write_is_compensated(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['removeAfterWrite' => true]);

        $this->assertFails(ContractSigningException::CMS_STORAGE_FAILED, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_audit_failure_rolls_back_and_compensates_cms(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['failAudit' => true]);

        $this->assertFails(ContractSigningException::PERSISTENCE_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: false);
        $this->assertNothingSigned();
    }

    public function test_cleanup_failure_raises_compensation_incomplete_without_path(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['failAudit' => true, 'failCleanup' => true]);

        $e = $this->assertFails(ContractSigningException::PERSISTENCE_FAILED, fn () => $service->sign($this->request($f['contract'])), compensationIncomplete: true);
        $this->assertSame(0, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(0, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertStringNotContainsString('.p7s', $e->getMessage());
    }

    public function test_throwing_before_persistence_is_normalized_and_compensated(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['beforePersistence' => function (): void {
            throw new RuntimeException('injected pre-persistence failure');
        }]);

        $this->assertFails(ContractSigningException::PERSISTENCE_FAILED, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_winner_lookup_failure_fails_closed_and_compensates(): void
    {
        $f = $this->signableFixture();
        $winner = null;
        $service = $this->seamService([
            'recheckNull' => true,
            'throwRaceWinner' => true,
            'beforePersistence' => function (Contract $c, Certificate $cert, StoredFile $pdf) use ($f, &$winner): void {
                $winner = $this->insertCompetingSignature($c, $f['user'], (int) $pdf->id, (int) $cert->id);
            },
        ]);

        // The unique index fires, the winner lookup throws -> no false success.
        $this->assertFails(ContractSigningException::PERSISTENCE_FAILED, fn () => $service->sign($this->request($f['contract'])));
        // Only the pre-seeded winner remains; our provisional CMS was deleted.
        $this->assertSame(1, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(1, $this->p7sCount());
    }

    /** @return array<string, array{0: string}> */
    public static function winnerValidationExceptionProvider(): array
    {
        return [
            'Signature lookup' => ['signature'],
            'Contract lookup' => ['contract'],
            'source StoredFile lookup' => ['source'],
            'Certificate lookup' => ['certificate'],
            'CMS StoredFile lookup' => ['cms'],
            'Storage path' => ['storage_path'],
            'physical read' => ['physical_read'],
            'physical size' => ['physical_size'],
            'physical SHA' => ['physical_sha'],
        ];
    }

    #[DataProvider('winnerValidationExceptionProvider')]
    public function test_exception_during_winner_validation_compensates_own_cms(string $step): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService([
            'throwValidationStep' => $step,
            'beforePersistence' => function (Contract $contract, Certificate $certificate, StoredFile $pdf) use ($f): void {
                $this->insertCompetingSignature($contract, $f['user'], (int) $pdf->id, (int) $certificate->id);
            },
        ]);

        $this->assertFails(
            ContractSigningException::PERSISTED_SIGNATURE_INVALID,
            fn () => $service->sign($this->request($f['contract'])),
            compensationIncomplete: false,
        );

        // Only the pre-seeded winner remains. This operation's provisional CMS,
        // StoredFile, Signature and success audit were never retained.
        $this->assertSame(1, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertSame(1, $this->p7sCount());
    }

    // --- P2: existing idempotent result integrity --------------------------

    public function test_idempotent_call_does_not_resign(): void
    {
        $f = $this->signableFixture();
        $this->service()->sign($this->request($f['contract']));

        $counting = $this->countingSigner();
        $result = $this->serviceWith($counting)->sign($this->request($f['contract']));

        $this->assertTrue($result->idempotentExisting);
        $this->assertSame(0, $counting->calls, 'the signer must NOT be re-invoked on an idempotent call');
        $this->assertSame(1, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertSame(1, $this->p7sCount());
    }

    public function test_existing_signature_with_missing_cms_fails_closed(): void
    {
        $f = $this->signableFixture();
        $first = $this->service()->sign($this->request($f['contract']));
        $cms = StoredFile::findOrFail(Signature::findOrFail($first->signatureId)->signature_file_id);
        Storage::disk(StoredFile::DISK_LOCAL)->delete($cms->storage_path);

        $counting = $this->countingSigner();
        $this->assertFails(ContractSigningException::PERSISTED_SIGNATURE_INVALID, fn () => $this->serviceWith($counting)->sign($this->request($f['contract'])));
        $this->assertSame(0, $counting->calls, 'a corrupt existing signature must not trigger re-signing');
        $this->assertSame(1, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
    }

    public function test_existing_signature_with_cms_size_mismatch_fails_closed(): void
    {
        $f = $this->signableFixture();
        $first = $this->service()->sign($this->request($f['contract']));
        $cms = StoredFile::findOrFail(Signature::findOrFail($first->signatureId)->signature_file_id);
        $cms->size_bytes = (int) $cms->size_bytes + 5;
        $cms->save();

        $this->assertFails(ContractSigningException::PERSISTED_SIGNATURE_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
    }

    public function test_existing_signature_with_cms_hash_mismatch_fails_closed(): void
    {
        $f = $this->signableFixture();
        $first = $this->service()->sign($this->request($f['contract']));
        $cms = StoredFile::findOrFail(Signature::findOrFail($first->signatureId)->signature_file_id);
        Storage::disk(StoredFile::DISK_LOCAL)->put($cms->storage_path, "\x30\x82".str_repeat('Z', (int) $cms->size_bytes - 2));

        $this->assertFails(ContractSigningException::PERSISTED_SIGNATURE_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
    }

    public function test_existing_signature_with_wrong_cms_purpose_fails_closed(): void
    {
        $f = $this->signableFixture();
        $first = $this->service()->sign($this->request($f['contract']));
        $cms = StoredFile::findOrFail(Signature::findOrFail($first->signatureId)->signature_file_id);
        $cms->purpose = StoredFile::PURPOSE_FINAL_PDF;
        $cms->save();

        $this->assertFails(ContractSigningException::PERSISTED_SIGNATURE_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
    }

    public function test_existing_signature_with_missing_cms_file_id_fails_closed(): void
    {
        $f = $this->signableFixture();
        $first = $this->service()->sign($this->request($f['contract']));
        $sig = Signature::findOrFail($first->signatureId);
        $sig->signature_file_id = null;
        $sig->save();

        $this->assertFails(ContractSigningException::PERSISTED_SIGNATURE_INVALID, fn () => $this->service()->sign($this->request($f['contract'])));
    }

    // --- idempotency & concurrency -----------------------------------------

    public function test_repeated_call_is_idempotent(): void
    {
        $f = $this->signableFixture();

        $first = $this->service()->sign($this->request($f['contract']));
        $second = $this->service()->sign($this->request($f['contract']));

        $this->assertFalse($first->idempotentExisting);
        $this->assertTrue($second->idempotentExisting);
        $this->assertSame($first->signatureId, $second->signatureId);
        $this->assertSame($first->cmsFileId, $second->cmsFileId);
        $this->assertSame($first->sourceFileId, $second->sourceFileId);
        $this->assertSame($first->signerFingerprint, $second->signerFingerprint);
        $this->assertSame(1, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertSame(1, $this->p7sCount());
    }

    public function test_recheck_race_returns_existing_and_deletes_own_cms(): void
    {
        $f = $this->signableFixture();
        $winnerId = null;
        $service = $this->seamService([
            'beforePersistence' => function (Contract $c, Certificate $cert, StoredFile $pdf) use ($f, &$winnerId): void {
                $winnerId = $this->insertCompetingSignature($c, $f['user'], (int) $pdf->id, (int) $cert->id)->id;
            },
        ]);

        $result = $service->sign($this->request($f['contract']));

        $this->assertTrue($result->idempotentExisting);
        $this->assertSame((int) $winnerId, $result->signatureId);
        $this->assertSame(1, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(1, $this->p7sCount());
    }

    public function test_db_unique_index_race_returns_existing_and_deletes_own_cms(): void
    {
        $f = $this->signableFixture();
        $winnerId = null;
        $service = $this->seamService([
            'recheckNull' => true,
            'beforePersistence' => function (Contract $c, Certificate $cert, StoredFile $pdf) use ($f, &$winnerId): void {
                $winnerId = $this->insertCompetingSignature($c, $f['user'], (int) $pdf->id, (int) $cert->id)->id;
            },
        ]);

        $result = $service->sign($this->request($f['contract']));

        $this->assertTrue($result->idempotentExisting);
        $this->assertSame((int) $winnerId, $result->signatureId);
        $this->assertSame(1, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(1, $this->p7sCount());
    }

    public function test_certificate_deactivated_after_signing_fails_closed(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService([
            'beforePersistence' => function () use ($f): void {
                $f['ctx']['certificate']->is_active = false;
                $f['ctx']['certificate']->save();
            },
        ]);

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_INVALID, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_second_certificate_activated_after_signing_is_ambiguous(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService([
            'beforePersistence' => function () use ($f): void {
                $this->seedActiveCertificate($f['user'], $this->issueCertificate($f['ctx']['ca']));
            },
        ]);

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_AMBIGUOUS, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_changed_final_pdf_binding_after_signing_fails_closed(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService([
            'beforePersistence' => function () use ($f): void {
                $f['contract']->final_pdf_file_id = (int) $f['finalPdf']->id + 777;
                $f['contract']->save();
            },
        ]);

        $this->assertFails(ContractSigningException::CONTRACT_STATE_CHANGED, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_final_pdf_content_changed_after_signing_fails_closed(): void
    {
        // Generator "wins" the race and rewrites the physical final PDF (same
        // file_id) before the signer's final recheck -> the signer must abort.
        $f = $this->signableFixture();
        $service = $this->seamService([
            'beforePersistence' => function () use ($f): void {
                $newBytes = $this->pdfBytes('regenerated');
                Storage::disk(StoredFile::DISK_LOCAL)->put($f['finalPdf']->storage_path, $newBytes);
                $f['finalPdf']->size_bytes = strlen($newBytes);
                $f['finalPdf']->sha256 = hash('sha256', $newBytes);
                $f['finalPdf']->save();
                $f['contract']->final_pdf_sha256 = hash('sha256', $newBytes);
                $f['contract']->save();
            },
        ]);

        $this->assertFails(ContractSigningException::CONTRACT_STATE_CHANGED, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_physical_final_pdf_tampered_after_signing_is_detected_under_lock(): void
    {
        // The physical bytes are overwritten (same length) AFTER signing without
        // touching the recorded StoredFile / contract hash. The under-lock physical
        // recheck must still detect it and fail closed.
        $f = $this->signableFixture();
        $service = $this->seamService([
            'beforePersistence' => function () use ($f): void {
                Storage::disk(StoredFile::DISK_LOCAL)->put($f['finalPdf']->storage_path, str_repeat('Z', strlen($f['bytes'])));
            },
        ]);

        $this->assertFails(ContractSigningException::CONTRACT_STATE_CHANGED, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    // --- P1: ambiguous commit outcome ---------------------------------------

    public function test_lost_commit_acknowledgement_does_not_delete_the_committed_cms(): void
    {
        $f = $this->signableFixture();

        // The COMMIT really lands; only the acknowledgement is lost.
        $result = $this->seamService(['lostCommitAck' => true])->sign($this->request($f['contract']));

        // Reconciliation proved the commit: the persisted signature is returned and
        // the CMS artefact is KEPT (deleting it would orphan the DB reference).
        $sig = Signature::findOrFail($result->signatureId);
        $this->assertSame(Signature::STATUS_COMPLETED, $sig->status);
        $this->assertSame((int) $f['finalPdf']->id, (int) $sig->source_file_id);
        $cms = StoredFile::findOrFail($sig->signature_file_id);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($cms->storage_path), 'the committed CMS must NOT be deleted');
        $der = Storage::disk(StoredFile::DISK_LOCAL)->get($cms->storage_path);
        $this->assertSame(hash('sha256', $der), $cms->sha256);
        $this->assertSame(strlen($der), (int) $cms->size_bytes);
        $this->assertSame(1, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertSame(1, $this->p7sCount());
    }

    public function test_ambiguous_reconciliation_lookup_exception_keeps_committed_cms_and_flags_incomplete(): void
    {
        $f = $this->signableFixture();

        $this->assertFails(
            ContractSigningException::PERSISTENCE_FAILED,
            fn () => $this->seamService([
                'lostCommitAck' => true,
                'throwAmbiguousReconciliationLookup' => true,
            ])->sign($this->request($f['contract'])),
            compensationIncomplete: true,
        );

        $signature = Signature::query()->where('status', Signature::STATUS_COMPLETED)->firstOrFail();
        $cms = StoredFile::findOrFail($signature->signature_file_id);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($cms->storage_path));
        $this->assertSame(1, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertSame(1, $this->p7sCount());
    }

    public function test_unprovable_ambiguous_cms_outcome_keeps_the_artefact_and_flags_incomplete(): void
    {
        $f = $this->signableFixture();

        $e = $this->assertFails(
            ContractSigningException::PERSISTENCE_FAILED,
            fn () => $this->seamService(['ambiguousRollback' => true])->sign($this->request($f['contract'])),
            compensationIncomplete: true,
        );
        $this->assertTrue($e->compensationIncomplete());

        // Nothing committed, and the provisional CMS is deliberately RETAINED: an
        // orphan is safer than deleting a possibly-committed artefact.
        $this->assertSame(0, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(0, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
        $this->assertSame(1, $this->p7sCount(), 'the ambiguous CMS artefact is deliberately retained');
    }

    // --- P2: freeze-before-sign verification precondition -------------------

    public function test_final_pdf_predating_active_public_verification_blocks_signing(): void
    {
        // The token was activated AFTER the current final PDF was produced, so the
        // signed bytes could not embed its QR: signing must fail closed until a new
        // PDF is generated.
        $f = $this->signableFixture();
        $f['contract']->public_verification_token = str_repeat('t', 64);
        $f['contract']->public_verification_enabled_at = now()->addMinutes(5);
        $f['contract']->save();

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_backdated_activation_and_manipulated_created_at_cannot_bypass_the_proof(): void
    {
        // Timestamps are NOT proof: a backdated activation and a forward-dated
        // final-PDF created_at still cannot authorize signing without an exact
        // persisted generation record.
        $f = $this->signableFixture();
        $f['contract']->public_verification_token = str_repeat('t', 64);
        $f['contract']->public_verification_enabled_at = now()->subMinutes(5);
        $f['contract']->save();
        $f['finalPdf']->created_at = now()->addMinutes(60);
        $f['finalPdf']->save();

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_exact_generation_proof_allows_signing(): void
    {
        $f = $this->signableFixture();
        $token = str_repeat('t', 64);
        $f['contract']->public_verification_token = $token;
        $f['contract']->public_verification_enabled_at = now();
        $f['contract']->save();
        $this->seedGenerationProof($f['contract'], (int) $f['finalPdf']->id, $f['sha'], $token);

        $result = $this->service()->sign($this->request($f['contract']));

        $this->assertFalse($result->idempotentExisting);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function brokenProofProvider(): array
    {
        return [
            'wrong token hash' => [['tokenHash' => 'deadbeef']],
            'wrong file id' => [['fileId' => 999999]],
            'wrong pdf hash' => [['pdfSha' => str_repeat('9', 64)]],
            'wrong url hash' => [['urlHash' => 'deadbeef']],
            'wrong generation reason' => [['reason' => 'manual']],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('brokenProofProvider')]
    public function test_mismatched_generation_proof_blocks_signing(array $override): void
    {
        $f = $this->signableFixture();
        $token = str_repeat('t', 64);
        $f['contract']->public_verification_token = $token;
        $f['contract']->public_verification_enabled_at = now();
        $f['contract']->save();
        $this->seedGenerationProof($f['contract'], (int) $f['finalPdf']->id, $f['sha'], $token, $override);

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_token_changed_after_generation_blocks_signing(): void
    {
        $f = $this->signableFixture();
        $token = str_repeat('t', 64);
        $f['contract']->public_verification_token = $token;
        $f['contract']->public_verification_enabled_at = now();
        $f['contract']->save();
        $this->seedGenerationProof($f['contract'], (int) $f['finalPdf']->id, $f['sha'], $token);

        // The token is rotated after the PDF was generated: the old proof no longer
        // binds the active token.
        $f['contract']->public_verification_token = str_repeat('u', 64);
        $f['contract']->save();

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_generation_proof_persists_only_hashes_never_the_plain_token_or_url(): void
    {
        $f = $this->signableFixture();
        $token = str_repeat('t', 64);
        $f['contract']->public_verification_token = $token;
        $f['contract']->public_verification_enabled_at = now();
        $f['contract']->save();
        $this->seedGenerationProof($f['contract'], (int) $f['finalPdf']->id, $f['sha'], $token);

        $binding = app(FinalPdfVerificationBindingVerifier::class);
        $event = AuditEvent::query()->where('action', 'contract.final_pdf_generated')->latest('id')->firstOrFail();
        $json = json_encode($event->metadata, JSON_THROW_ON_ERROR);

        // Hashes survive the sanitizer, plain secrets are never persisted.
        $this->assertSame($binding->tokenHash($token), $event->metadata['public_verification_token_sha256']);
        $this->assertSame($binding->urlHash($binding->canonicalVerificationUrl($token)), $event->metadata['verification_url_sha256']);
        $this->assertNotSame('[REDACTED]', $event->metadata['public_verification_token_sha256']);
        $this->assertNotSame('[REDACTED]', $event->metadata['verification_url_sha256']);
        $this->assertStringNotContainsString($token, $json);
        $this->assertStringNotContainsString($binding->canonicalVerificationUrl($token), $json);
    }

    public function test_revoked_public_verification_blocks_signing(): void
    {
        $f = $this->signableFixture();
        $f['contract']->public_verification_token = str_repeat('t', 64);
        $f['contract']->public_verification_enabled_at = now()->addMinutes(5);
        $f['contract']->public_verification_revoked_at = now();
        $f['contract']->save();

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_missing_public_verification_token_blocks_signing(): void
    {
        $f = $this->signableFixture();
        $f['contract']->public_verification_token = null;
        $f['contract']->public_verification_enabled_at = null;
        $f['contract']->save();

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_never_enabled_public_verification_token_blocks_signing(): void
    {
        $f = $this->signableFixture();
        $f['contract']->public_verification_enabled_at = null;
        $f['contract']->save();

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_explicitly_disabled_public_verification_blocks_signing(): void
    {
        $f = $this->signableFixture();
        $f['contract']->public_verification_enabled_at = null;
        $f['contract']->public_verification_revoked_at = now();
        $f['contract']->save();

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $this->service()->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_token_revoked_after_openssl_is_rejected_and_cms_is_compensated(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['beforePersistence' => function () use ($f): void {
            $f['contract']->public_verification_revoked_at = now();
            $f['contract']->save();
        }]);

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_token_disabled_after_openssl_is_rejected_and_cms_is_compensated(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['beforePersistence' => function () use ($f): void {
            $f['contract']->public_verification_enabled_at = null;
            $f['contract']->save();
        }]);

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_token_replaced_after_openssl_is_rejected_and_cms_is_compensated(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['beforePersistence' => function () use ($f): void {
            $f['contract']->public_verification_token = bin2hex(random_bytes(32));
            $f['contract']->save();
        }]);

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_proof_deleted_after_openssl_is_rejected_and_cms_is_compensated(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['beforePersistence' => function (): void {
            AuditEvent::query()->where('action', 'contract.final_pdf_generated')->delete();
        }]);

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_proof_metadata_changed_after_openssl_is_rejected_and_cms_is_compensated(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['beforePersistence' => function (): void {
            $event = AuditEvent::query()->where('action', 'contract.final_pdf_generated')->latest('id')->firstOrFail();
            $metadata = $event->metadata;
            $metadata['verification_url_sha256'] = str_repeat('0', 64);
            DB::table('audit_events')->where('id', $event->id)->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        }]);

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_exact_proof_lookup_exception_under_lock_is_stable_and_compensates_cms(): void
    {
        $f = $this->signableFixture();
        $service = $this->seamService(['throwBindingLookupOnSecondCall' => true]);

        $this->assertFails(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_signing_refuses_nested_transaction_and_compensates_owned_cms(): void
    {
        $f = $this->signableFixture();

        $this->assertFails(
            ContractSigningException::PERSISTENCE_FAILED,
            fn () => DB::transaction(fn () => $this->service()->sign($this->request($f['contract']))),
            compensationIncomplete: false,
        );
        $this->assertNothingSigned();
    }

    // --- P2: certificate identity recheck -----------------------------------

    public function test_certificate_replaced_with_same_fingerprint_but_new_id_fails_closed(): void
    {
        $f = $this->signableFixture();
        $material = $f['ctx']['signer'];
        $originalId = (int) $f['ctx']['certificate']->id;

        $service = $this->seamService([
            'beforePersistence' => function () use ($f, $material, $originalId): void {
                // Same fingerprint, brand-new row id (the thumbprint is unique).
                Certificate::query()->whereKey($originalId)->delete();
                $replacement = $this->seedActiveCertificate($f['user'], $material);
                $this->assertNotSame($originalId, (int) $replacement->id);
            },
        ]);

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_INVALID, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    public function test_certificate_replaced_with_different_id_and_fingerprint_fails_closed(): void
    {
        $f = $this->signableFixture();
        $originalId = (int) $f['ctx']['certificate']->id;

        $service = $this->seamService([
            'beforePersistence' => function () use ($f, $originalId): void {
                // The original is removed and a genuinely different certificate
                // (new id AND new fingerprint) becomes the only active one.
                Certificate::query()->whereKey($originalId)->delete();
                $replacement = $this->seedActiveCertificate($f['user'], $this->issueCertificate($f['ctx']['ca']));
                $this->assertNotSame($originalId, (int) $replacement->id);
                $this->assertNotSame($this->fingerprint($f['ctx']['signer']['cert']), $replacement->thumbprint_sha256);
            },
        ]);

        $this->assertFails(ContractSigningException::SIGNER_CERTIFICATE_INVALID, fn () => $service->sign($this->request($f['contract'])));
        $this->assertNothingSigned();
    }

    // --- P3: create-only collision -------------------------------------------

    public function test_preexisting_file_on_the_generated_cms_path_is_never_overwritten_or_deleted(): void
    {
        $f = $this->signableFixture();
        $foreign = "%FOREIGN-FILE-DO-NOT-TOUCH\n";

        // Force a collision: the "random" path already holds someone else's file.
        $service = new class(app(DetachedCmsSigner::class), app(AuditLogger::class), app(FinalPdfIntegrityVerifier::class), app(AuthFactory::class), app(FinalPdfVerificationBindingVerifier::class), $foreign) extends ContractSigningService
        {
            public string $collidedPath = '';

            public function __construct(DetachedCmsSigner $s, AuditLogger $a, FinalPdfIntegrityVerifier $v, AuthFactory $auth, FinalPdfVerificationBindingVerifier $b, private readonly string $foreign)
            {
                parent::__construct($s, $a, $v, $auth, $b);
            }

            protected function putCmsBytes(Filesystem $disk, string $path, string $der): bool
            {
                // Simulate the UUID landing on an existing file created between the
                // exists() probe and the write (the residual TOCTOU window).
                $this->collidedPath = $path;
                $disk->put($path, $this->foreign);

                return false;
            }
        };

        $this->assertFails(
            ContractSigningException::CMS_STORAGE_FAILED,
            fn () => $service->sign($this->request($f['contract'])),
            compensationIncomplete: true,
        );

        // Fails closed; the foreign file must be neither overwritten with our DER
        // nor deleted by our cleanup (it is not ours to remove).
        $this->assertNotSame('', $service->collidedPath);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($service->collidedPath));
        $this->assertSame($foreign, Storage::disk(StoredFile::DISK_LOCAL)->get($service->collidedPath));
        $this->assertSame(0, Signature::query()->where('status', Signature::STATUS_COMPLETED)->count());
        $this->assertSame(0, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.signature_completed')->count());
    }

    // --- P2: winner tuple validation ----------------------------------------

    public function test_race_winner_from_a_different_tuple_is_rejected(): void
    {
        $f = $this->signableFixture();
        $foreign = null;
        $service = $this->seamService([
            'recheckNull' => true,
            'beforePersistence' => function (Contract $c, Certificate $cert, StoredFile $pdf) use ($f, &$foreign): void {
                // A real committed winner keeps the unique index firing...
                $this->insertCompetingSignature($c, $f['user'], (int) $pdf->id, (int) $cert->id);
                // ...but the lookup returns a signature for a DIFFERENT contract.
                $other = $this->seedContract($f['user']);
                $otherPdf = $this->attachFinalPdf($other, $f['user'], $this->pdfBytes('other'));
                $foreign = $this->insertCompetingSignature($other, $f['user'], (int) $otherPdf->id, (int) $cert->id);
            },
            'raceWinner' => function () use (&$foreign): ?Signature {
                return $foreign;
            },
        ]);

        $this->assertFails(ContractSigningException::PERSISTED_SIGNATURE_INVALID, fn () => $service->sign($this->request($f['contract'])));
    }

    // --- P2: exact unique-race classifier (table-driven) --------------------

    /**
     * @return array<string, array{0: int, 1: array<int, mixed>|null, 2: bool}>
     */
    public static function classifierProvider(): array
    {
        $pg = static fn (string $name): array => [23505, 42, 'ERROR: duplicate key value violates unique constraint "'.$name.'"'];
        $sqlite = static fn (string $msg): array => [23000, 2067, $msg];
        $target = 'signatures_contract_user_source_active_unique';
        $sqliteTuple = 'UNIQUE constraint failed: signatures.contract_id, signatures.signed_user_id, signatures.source_file_id';

        return [
            'pg exact' => [23505, $pg($target), true],
            'pg wrong sqlstate' => [23503, $pg($target), false],
            'pg name + suffix' => [23505, $pg($target.'_idx'), false],
            'pg prefix + name' => [23505, $pg('x_'.$target), false],
            'pg shadow index' => [23505, $pg($target.'2'), false],
            'pg signature_file_id unique' => [23505, $pg('signatures_signature_file_id_unique'), false],
            'pg other unique' => [23505, $pg('certificates_thumbprint_sha256_unique'), false],
            'pg fk' => [23503, [23503, 42, 'ERROR: insert violates foreign key constraint "signatures_source_file_id_foreign"'], false],
            'pg check' => [23514, [23514, 42, 'ERROR: new row violates check constraint "signatures_completed_required_fields_check"'], false],
            'pg missing errorInfo' => [23505, null, false],
            'pg wrapper-only (no quoted constraint)' => [23505, [23505, 42, 'duplicate key value violates unique constraint'], false],
            // The PRIMARY violated constraint is a different one; our target only
            // appears in the DETAIL tail. That must NOT count as our race.
            'pg detail-only mention of the target' => [23505, [23505, 42,
                'ERROR: duplicate key value violates unique constraint "signatures_signature_file_id_unique"'."\n"
                .'DETAIL: Key (contract_id, signed_user_id, source_file_id)=(1, 1, 1) already exists in constraint "signatures_contract_user_source_active_unique".',
            ], false],
            'sqlite exact' => [23000, $sqlite($sqliteTuple), true],
            'sqlite extra target' => [23000, $sqlite($sqliteTuple.', signatures.certificate_id'), false],
            'sqlite reordered' => [23000, $sqlite('UNIQUE constraint failed: signatures.signed_user_id, signatures.contract_id, signatures.source_file_id'), false],
            'sqlite signature_file_id' => [23000, $sqlite('UNIQUE constraint failed: signatures.signature_file_id'), false],
            'sqlite suffix' => [23000, $sqlite($sqliteTuple.' extra'), false],
            'sqlite other unique' => [23000, $sqlite('UNIQUE constraint failed: certificates.thumbprint_sha256'), false],
            'sqlite fk' => [23000, $sqlite('FOREIGN KEY constraint failed'), false],
            'sqlite check' => [23000, $sqlite('CHECK constraint failed: signatures'), false],
            'sqlite missing errorInfo' => [23000, null, false],
        ];
    }

    /**
     * @param  array<int, mixed>|null  $errorInfo
     */
    #[DataProvider('classifierProvider')]
    public function test_unique_race_classifier_matrix(int $code, ?array $errorInfo, bool $expected): void
    {
        $this->assertSame($expected, $this->classifier()->classify($this->queryException($code, $errorInfo)));
    }
}
