<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\ContractSigningException;
use App\Exceptions\Signing\DetachedCmsException;
use App\Exceptions\Signing\FinalPdfException;
use App\Models\AuditEvent;
use App\Models\Certificate;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * M11 — signing orchestration and persistence.
 *
 * Application service that turns a finalized, locked contract into a persisted
 * detached CMS signature, on top of the reusable M10 service layer. It performs
 * NO route/controller/UI work; it is a pure application/persistence boundary.
 *
 * Actor boundary: the signing actor is NEVER a caller-provided scalar id. It is
 * resolved from the trusted authentication guard, and the service proves that
 * authenticated user owns the contract. signed_user_id and audit actor_user_id
 * are therefore always the same authenticated identity.
 *
 * Flow (freeze-bound, TOCTOU-safe):
 *   1. fresh preflight — resolve the authenticated actor, resolve + authorize the
 *      contract, confirm finalized + locked, resolve the EXACT current final-PDF
 *      StoredFile and verify its physical integrity (shared verifier), select the
 *      actor's single active signer certificate, and short-circuit an
 *      already-completed signature after re-validating its artefact (idempotency);
 *   2. detached CMS signing via M10 DetachedCmsSigner over the exact final PDF;
 *   3. write the DER CMS artefact to a private, unpredictable .p7s path and
 *      physically re-verify it (byte-equality, size, SHA-256 == M10 cmsSha256());
 *   4. a SHORT DB transaction that locks the OWNER row then the Contract row
 *      (User -> Contract -> dependent reads), re-confirms every precondition on
 *      fresh rows (final-PDF binding + hash, single active certificate + exact
 *      fingerprint), re-checks idempotency, then atomically creates the CMS
 *      StoredFile, the completed Signature, and the audit event;
 *   5. everything after the provisional CMS exists runs inside one compensation
 *      boundary: on ANY failure or lost race the provisional CMS is deleted and a
 *      stable code is raised; a confirmed-incomplete cleanup sets
 *      compensationIncomplete without leaking a path.
 *
 * Lock order (deadlock-free): the signer takes User(owner) -> Contract; the
 * SignerCertificateRegistrar takes only User(owner); FinalPdfGenerator takes only
 * Contract. No path waits on a lock another path already holds in the opposite
 * order, so no cycle can form.
 *
 * The long OpenSSL work never runs inside the DB transaction. The DB-level partial
 * unique index signatures_contract_user_source_active_unique (contract_id,
 * signed_user_id, source_file_id) WHERE status IN ('pending','completed') is the
 * last-resort concurrency guard behind the lock and recheck.
 *
 * Idempotency identity: (contract_id, signed_user_id = actor, source_file_id =
 * exact current final-PDF StoredFile id) — keyed on the EXACT source artefact, so
 * a different signer, a different owner's certificate, or an older/regenerated
 * final PDF is never treated as "the same signature".
 *
 * Known P3: Flysystem exists()+put() is not an atomic exclusive create on every
 * adapter. UUID paths make collision negligible; if write/readback cannot prove
 * ownership, cleanup retains the path and reports compensationIncomplete rather
 * than risk deleting a racing writer's artefact.
 *
 * The class is non-final to expose narrow protected seams for deterministic
 * race/stale-state failure-injection tests (no sleep()).
 */
class ContractSigningService
{
    public function __construct(
        private readonly DetachedCmsSigner $signer,
        private readonly AuditLogger $auditLogger,
        private readonly FinalPdfIntegrityVerifier $finalPdfVerifier,
        private readonly AuthFactory $auth,
        private readonly FinalPdfVerificationBindingVerifier $bindingVerifier,
    ) {}

    public function sign(ContractSigningRequest $request): ContractSigningResult
    {
        // 1. Fresh preflight on current auth + DB + filesystem state.
        $actorUserId = $this->resolveAuthenticatedActor();

        $contract = $this->loadContract($request->contractId);
        $this->assertAuthorized($contract, $actorUserId);
        $this->assertSignable($contract);

        $finalPdf = $this->resolveFinalPdf($contract);
        [$sourcePath, $sourceSha256] = $this->verifyFinalPdf($contract, $finalPdf);

        // Freeze-before-sign: public verification is mandatory and an EXACT
        // persisted generation proof must show this artefact embeds the active QR.
        $this->assertVerificationQrEmbedded($contract, $finalPdf, $sourceSha256);

        $certificate = $this->selectActiveCertificate($actorUserId);

        // Idempotency short-circuit: a valid already-completed signature for the
        // exact (contract, actor, source-file) tuple returns without re-signing.
        // A corrupt/missing existing artefact fails closed (PERSISTED_SIGNATURE_INVALID).
        $existing = $this->findExistingCompletedSignature($contract->id, $actorUserId, $finalPdf->id);
        if ($existing !== null) {
            return $this->guardedIdempotentResult($existing, $contract->id, $actorUserId, (int) $finalPdf->id);
        }

        // 2. Detached CMS signing over the EXACT current final PDF (outside any DB
        //    transaction). M10 independently re-fetches/re-validates the cert and
        //    re-hashes the source; a stable CMS failure fails closed here.
        $signature = $this->createDetachedSignature($sourcePath, $sourceSha256, $certificate, $actorUserId);

        // 3. Persist the CMS artefact to a private path and physically re-verify
        //    against the M10 DER and its cmsSha256().
        [$cmsPath, $cmsSize, $cmsSha256] = $this->storeCmsArtifact($contract->id, $signature->cmsDer(), $signature->cmsSha256());

        // 4-5. Everything below runs inside the compensation boundary.
        return $this->persistWithCompensation($actorUserId, $contract, $finalPdf, $certificate, $sourceSha256, $signature, $cmsPath, $cmsSize, $cmsSha256);
    }

    // --- preflight ----------------------------------------------------------

    /**
     * The signing actor is the authenticated user, never a caller-supplied id.
     */
    private function resolveAuthenticatedActor(): int
    {
        $actor = $this->auth->guard()->user();
        if ($actor === null) {
            throw ContractSigningException::of(ContractSigningException::SIGNING_NOT_AUTHORIZED);
        }

        $id = (int) $actor->getAuthIdentifier();
        if ($id <= 0) {
            throw ContractSigningException::of(ContractSigningException::SIGNING_NOT_AUTHORIZED);
        }

        return $id;
    }

    private function loadContract(int $contractId): Contract
    {
        $contract = Contract::query()->whereKey($contractId)->first();

        if ($contract === null) {
            throw ContractSigningException::of(ContractSigningException::CONTRACT_NOT_FOUND);
        }

        return $contract;
    }

    private function assertAuthorized(Contract $contract, int $actorUserId): void
    {
        if ((int) $contract->created_by_user_id !== $actorUserId) {
            throw ContractSigningException::of(ContractSigningException::SIGNING_NOT_AUTHORIZED);
        }
    }

    private function assertSignable(Contract $contract): void
    {
        // The only signable lifecycle state is an explicitly finalized AND locked
        // contract. Draft, cancelled, expired, and archived states fail closed.
        if (! $contract->isFinalized() || ! $contract->isLocked()) {
            throw ContractSigningException::of(ContractSigningException::CONTRACT_NOT_SIGNABLE);
        }
    }

    private function resolveFinalPdf(Contract $contract): StoredFile
    {
        if ($contract->final_pdf_file_id === null || blank($contract->final_pdf_sha256)) {
            throw ContractSigningException::of(ContractSigningException::FINAL_PDF_MISSING);
        }

        $finalPdf = StoredFile::query()->whereKey($contract->final_pdf_file_id)->first();

        if ($finalPdf === null) {
            throw ContractSigningException::of(ContractSigningException::FINAL_PDF_INVALID);
        }

        return $finalPdf;
    }

    /**
     * Delegate the physical final-PDF integrity to the shared verifier (same rule
     * the generator enforces). A neutral FinalPdfException is mapped to the
     * signing-scoped FINAL_PDF_INVALID.
     *
     * @return array{0: string, 1: string} canonical local path, lowercase SHA-256
     */
    private function verifyFinalPdf(Contract $contract, StoredFile $finalPdf): array
    {
        try {
            return $this->finalPdfVerifier->verify($contract, $finalPdf);
        } catch (FinalPdfException) {
            throw ContractSigningException::of(ContractSigningException::FINAL_PDF_INVALID);
        }
    }

    /**
     * Freeze-before-sign precondition: public verification must be active and the
     * exact final PDF about to be signed must be PROVEN to embed its current QR.
     *
     * The proof is the append-only generation audit written in the same transaction
     * as the StoredFile + binding + token activation, pinning the exact file id, PDF
     * SHA-256, generation reason, and the SHA-256 of the token and canonical URL. A
     * timestamp comparison is deliberately NOT used as proof (same-second values and
     * mutable created_at cannot prove QR content).
     */
    private function assertVerificationQrEmbedded(Contract $contract, StoredFile $finalPdf, string $sourceSha256): void
    {
        $token = (string) ($contract->public_verification_token ?? '');

        if ($token === ''
            || $contract->public_verification_enabled_at === null
            || $contract->public_verification_revoked_at !== null) {
            throw ContractSigningException::of(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY);
        }

        try {
            $proven = $this->verificationBindingExists($contract, (int) $finalPdf->id, $sourceSha256, $token);
        } catch (Throwable) {
            throw ContractSigningException::of(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY);
        }

        if (! $proven) {
            throw ContractSigningException::of(ContractSigningException::PUBLIC_VERIFICATION_NOT_READY);
        }
    }

    /** Narrow seam around the production exact-proof lookup. */
    protected function verificationBindingExists(
        Contract $contract,
        int $finalPdfFileId,
        string $finalPdfSha256,
        string $token,
    ): bool {
        return $this->bindingVerifier->hasGenerationProof(
            $contract,
            $finalPdfFileId,
            $finalPdfSha256,
            $token,
        );
    }

    /**
     * Select the actor's SINGLE active signer certificate. The database does not
     * (in this phase) guarantee at most one active certificate per user, so this
     * fails closed on zero (MISSING) or more than one (AMBIGUOUS) rather than
     * arbitrarily picking a candidate.
     */
    private function selectActiveCertificate(int $actorUserId): Certificate
    {
        $active = $this->activeCertificates($actorUserId);

        if ($active->isEmpty()) {
            throw ContractSigningException::of(ContractSigningException::SIGNER_CERTIFICATE_MISSING);
        }

        if ($active->count() > 1) {
            throw ContractSigningException::of(ContractSigningException::SIGNER_CERTIFICATE_AMBIGUOUS);
        }

        return $active->first();
    }

    /**
     * @return Collection<int, Certificate>
     */
    private function activeCertificates(int $actorUserId)
    {
        return Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $actorUserId)
            ->where('is_active', true)
            ->get();
    }

    private function findExistingCompletedSignature(int $contractId, int $actorUserId, int $sourceFileId): ?Signature
    {
        return Signature::query()
            ->where('contract_id', $contractId)
            ->where('signed_user_id', $actorUserId)
            ->where('source_file_id', $sourceFileId)
            ->where('status', Signature::STATUS_COMPLETED)
            ->first();
    }

    // --- signing ------------------------------------------------------------

    private function createDetachedSignature(
        string $sourcePath,
        string $sourceSha256,
        Certificate $certificate,
        int $actorUserId,
    ): DetachedCmsSignatureResult {
        try {
            return $this->signer->sign(new DetachedCmsSignRequest(
                $sourcePath,
                $sourceSha256,
                $certificate,
                $actorUserId,
            ));
        } catch (DetachedCmsException $e) {
            // Preserve the neutral, stable M10 code (for diagnosis) AND the M10
            // compensation-incomplete signal; no raw provider error/path/byte.
            $wrapped = ContractSigningException::fromSignerCode($e->errorCode());
            if ($e->compensationIncomplete()) {
                $wrapped->markCompensationIncomplete();
            }

            throw $wrapped;
        }
    }

    // --- CMS storage --------------------------------------------------------

    /**
     * Write the DER CMS bytes to a private, unpredictable .p7s path, then read
     * them back and require byte-for-byte equality with the M10 DER, matching
     * size, and a SHA-256 (computed from the readback) equal to M10 cmsSha256() —
     * never trusting the DTO hash alone. Any failure cleans up and fails closed.
     *
     * @return array{0: string, 1: int, 2: string} path, size, lowercase sha256
     */
    private function storeCmsArtifact(int $contractId, string $der, string $expectedM10Sha256): array
    {
        $disk = Storage::disk(StoredFile::DISK_LOCAL);
        $path = 'contracts/'.$contractId.'/signatures/sig-'.Str::uuid()->toString().'.p7s';
        $writeAttempted = false;
        $ownershipProven = false;

        try {
            // A pre-existing path is an unrelated artefact (astronomically unlikely
            // UUID collision) — never ours to delete.
            if ($disk->exists($path)) {
                throw ContractSigningException::of(ContractSigningException::CMS_STORAGE_FAILED);
            }

            $writeAttempted = true;
            if (! $this->putCmsBytes($disk, $path, $der)) {
                $this->failCmsStorage($disk, $path, $writeAttempted, $ownershipProven);
            }

            // Test seam: corrupt the just-written artefact to exercise the
            // physical readback-mismatch compensation path.
            $this->afterCmsWritten($disk, $path);

            $readBack = $disk->get($path);
        } catch (ContractSigningException $e) {
            throw $e;
        } catch (Throwable) {
            $this->failCmsStorage($disk, $path, $writeAttempted, $ownershipProven);
        }

        if (! is_string($readBack) || strlen($readBack) !== strlen($der) || ! hash_equals($der, $readBack)) {
            $this->failCmsStorage($disk, $path, $writeAttempted, $ownershipProven);
        }

        $ownershipProven = true;

        $readbackSha = hash('sha256', $readBack);
        if (! hash_equals(strtolower($expectedM10Sha256), $readbackSha)) {
            $this->failCmsStorage($disk, $path, $writeAttempted, $ownershipProven);
        }

        return [$path, strlen($readBack), $readbackSha];
    }

    /**
     * Clean up a partially-written provisional CMS artefact and fail closed with
     * CMS_STORAGE_FAILED. If the cleanup cannot be confirmed, the
     * compensation-incomplete signal is set; no path is ever exposed.
     */
    private function failCmsStorage(
        Filesystem $disk,
        string $path,
        bool $writeAttempted,
        bool $ownershipProven,
    ): never {
        $e = ContractSigningException::of(ContractSigningException::CMS_STORAGE_FAILED);

        $cleanupConfirmed = true;
        if ($writeAttempted) {
            if ($ownershipProven) {
                $cleanupConfirmed = $this->deleteProvisional($disk, $path);
            } else {
                // The path may belong to a racing writer. Never delete unless exact
                // readback has already proven that this operation owns the bytes.
                try {
                    $cleanupConfirmed = ! $disk->exists($path);
                } catch (Throwable) {
                    $cleanupConfirmed = false;
                }
            }
        }

        if (! $cleanupConfirmed) {
            $e->markCompensationIncomplete();
        }

        throw $e;
    }

    // --- persistence + compensation -----------------------------------------

    /**
     * Everything after a provisional CMS artefact exists on disk. beforePersistence,
     * the transaction, the lost-race winner lookup, and the idempotent-result
     * validation all run inside this single compensation boundary; no raw DB/
     * filesystem/OpenSSL exception may escape, and the provisional CMS is always
     * cleaned up (or flagged compensationIncomplete) on any failure.
     */
    private function persistWithCompensation(
        int $actorUserId,
        Contract $contract,
        StoredFile $finalPdf,
        Certificate $certificate,
        string $sourceSha256,
        DetachedCmsSignatureResult $signature,
        string $cmsPath,
        int $cmsSize,
        string $cmsSha256,
    ): ContractSigningResult {
        if (DB::transactionLevel() !== 0) {
            // A nested savepoint cannot provide a durable-commit boundary for CMS
            // reconciliation. Refuse it while the owned provisional CMS is still
            // inside this method's compensation boundary.
            $this->compensate($cmsPath, ContractSigningException::of(ContractSigningException::PERSISTENCE_FAILED));
        }

        // Set ONLY after every DB operation in the callback has completed. If the
        // callback finished but the transaction still threw (e.g. the COMMIT
        // succeeded and only the acknowledgement was lost), the outcome is AMBIGUOUS
        // and the provisional CMS must never be blindly deleted.
        $callbackCompleted = false;

        try {
            // Seam: mutate cert/contract/final-PDF or commit a competing signature
            // between signing/storage and persistence (now inside compensation).
            $this->beforePersistence($contract, $certificate, $finalPdf);

            $outcome = $this->runInTransaction(function () use (
                $actorUserId, $contract, $finalPdf, $sourceSha256, $certificate, $signature, $cmsPath, $cmsSize, $cmsSha256, &$callbackCompleted
            ): array {
                $result = $this->persistWithinTransaction(
                    $actorUserId,
                    $contract->id,
                    $finalPdf->id,
                    $sourceSha256,
                    (int) $certificate->getKey(),
                    $signature->signerFingerprint(),
                    $cmsPath,
                    $cmsSize,
                    $cmsSha256,
                );
                $callbackCompleted = true;

                return $result;
            });
        } catch (ContractSigningException $e) {
            if ($callbackCompleted) {
                return $this->reconcileAmbiguousCommit($contract->id, $actorUserId, (int) $finalPdf->id, $cmsPath);
            }
            $this->compensate($cmsPath, $e);
        } catch (QueryException $e) {
            if ($callbackCompleted) {
                return $this->reconcileAmbiguousCommit($contract->id, $actorUserId, (int) $finalPdf->id, $cmsPath);
            }

            return $this->resolveLostRace($e, $contract->id, $actorUserId, (int) $finalPdf->id, $cmsPath);
        } catch (Throwable) {
            if ($callbackCompleted) {
                return $this->reconcileAmbiguousCommit($contract->id, $actorUserId, (int) $finalPdf->id, $cmsPath);
            }
            $this->compensate($cmsPath, ContractSigningException::of(ContractSigningException::PERSISTENCE_FAILED));
        }

        // A concurrent winner was detected inside the transaction (no rows written):
        // validate it, drop our provisional CMS, and return the existing signature.
        if ($outcome['status'] === 'existing') {
            return $this->returnExistingWinner($outcome['signature'], $contract->id, $actorUserId, (int) $finalPdf->id, $cmsPath);
        }

        return $this->createdResult($outcome['signature'], $finalPdf->id, $cmsSize, $sourceSha256, $signature->signerFingerprint());
    }

    /**
     * Lock the owner row, then the Contract row, re-confirm every precondition on
     * fresh rows, re-resolve the single active certificate, re-check idempotency,
     * and atomically create the CMS StoredFile, completed Signature, and audit.
     *
     * @return array{status: string, signature: Signature}
     */
    private function persistWithinTransaction(
        int $actorUserId,
        int $contractId,
        int $sourceFileId,
        string $sourceSha256,
        int $originalCertificateId,
        string $signerFingerprint,
        string $cmsPath,
        int $cmsSize,
        string $cmsSha256,
    ): array {
        // Lock order: User(owner) -> Contract -> dependent reads. The registrar
        // locks only the owner row and the generator only the Contract row, so
        // this ordering cannot deadlock with either.
        $owner = User::query()->whereKey($actorUserId)->lockForUpdate()->first();
        if ($owner === null) {
            throw ContractSigningException::of(ContractSigningException::SIGNING_NOT_AUTHORIZED);
        }

        $contract = Contract::query()->whereKey($contractId)->lockForUpdate()->first();
        if ($contract === null
            || (int) $contract->created_by_user_id !== $actorUserId
            || ! $contract->isFinalized()
            || ! $contract->isLocked()
            || (int) $contract->final_pdf_file_id !== $sourceFileId
            || ! hash_equals($sourceSha256, strtolower((string) $contract->final_pdf_sha256))) {
            throw ContractSigningException::of(ContractSigningException::CONTRACT_STATE_CHANGED);
        }

        // Re-fetch the EXACT StoredFile and re-verify the final PDF's PHYSICAL
        // bytes under the lock (shared verifier). This closes the window where the
        // physical file is tampered after signing without the recorded hash
        // changing — the just-signed bytes must still be on disk, byte-for-byte.
        $sourceFile = StoredFile::query()->whereKey($sourceFileId)->first();
        if ($sourceFile === null) {
            throw ContractSigningException::of(ContractSigningException::CONTRACT_STATE_CHANGED);
        }
        try {
            [, $physicalSha] = $this->finalPdfVerifier->verify($contract, $sourceFile);
        } catch (FinalPdfException) {
            throw ContractSigningException::of(ContractSigningException::CONTRACT_STATE_CHANGED);
        }
        if (! hash_equals($sourceSha256, $physicalSha)) {
            throw ContractSigningException::of(ContractSigningException::CONTRACT_STATE_CHANGED);
        }

        // Recheck the mandatory active token + exact proof on the freshly locked
        // Contract after the long OpenSSL operation. Any lookup failure remains
        // inside the provisional-CMS compensation boundary.
        $this->assertVerificationQrEmbedded($contract, $sourceFile, $physicalSha);

        // Re-resolve the active certificate set under the owner lock: exactly one,
        // and it must be the SAME certificate ID *and* the same fingerprint that
        // M10 actually signed with. A replacement carrying the same fingerprint but
        // a different ID is rejected too.
        $active = $this->activeCertificates($actorUserId);
        if ($active->count() > 1) {
            throw ContractSigningException::of(ContractSigningException::SIGNER_CERTIFICATE_AMBIGUOUS);
        }
        if ($active->count() !== 1
            || (int) $active->first()->getKey() !== $originalCertificateId
            || ! hash_equals($signerFingerprint, strtolower((string) $active->first()->thumbprint_sha256))) {
            throw ContractSigningException::of(ContractSigningException::SIGNER_CERTIFICATE_INVALID);
        }
        $certificateId = $originalCertificateId;

        // Idempotency recheck under the lock (seam-overridable for race tests).
        $winner = $this->recheckExistingSignature($contractId, $actorUserId, $sourceFileId);
        if ($winner !== null) {
            return ['status' => 'existing', 'signature' => $winner];
        }

        $cmsFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_CMS_SIGNATURE,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => $cmsPath,
            'original_filename' => 'contract-'.$contractId.'-signature.p7s',
            'mime_type' => 'application/pkcs7-signature',
            'size_bytes' => $cmsSize,
            'sha256' => $cmsSha256,
            'created_by_user_id' => $actorUserId,
        ]);

        $signature = Signature::query()->create([
            'contract_id' => $contractId,
            'contract_party_id' => null,
            'certificate_id' => $certificateId,
            'signed_user_id' => $actorUserId,
            'signed_customer_id' => null,
            'source_file_id' => $sourceFileId,
            'signature_file_id' => $cmsFile->id,
            'type' => Signature::TYPE_DIGITAL,
            'status' => Signature::STATUS_COMPLETED,
            'signed_at' => now(),
            'document_hash_before' => $sourceSha256,
            'document_hash_after' => $sourceSha256,
        ]);

        $this->recordSigningAudit($contract, $actorUserId, [
            'contract_id' => $contractId,
            'signature_id' => $signature->id,
            'source_file_id' => $sourceFileId,
            'cms_file_id' => $cmsFile->id,
            'certificate_id' => $certificateId,
            'signed_user_id' => $actorUserId,
            'source_sha256' => $sourceSha256,
            'cms_sha256' => $cmsSha256,
            'signer_certificate_fingerprint' => $signerFingerprint,
        ]);

        return ['status' => 'created', 'signature' => $signature];
    }

    /**
     * The commit outcome is unknown: the transaction callback completed, so the
     * COMMIT may already be durable even though the transaction threw.
     *
     * Deleting the provisional CMS here could leave a committed Signature pointing at
     * a file we just destroyed — strictly worse than an orphan. So the artefact is
     * KEPT and success is only claimed when fresh state fully proves the commit
     * landed (validated tuple + source + certificate + physical CMS + audit).
     */
    private function reconcileAmbiguousCommit(int $contractId, int $actorUserId, int $sourceFileId, string $cmsPath): ContractSigningResult
    {
        try {
            $persisted = $this->findSignatureForReconciliation($contractId, $actorUserId, $sourceFileId);
            if ($persisted !== null
                && (string) StoredFile::query()->whereKey($persisted->signature_file_id)->value('storage_path') === $cmsPath
                && $this->signingAuditExists($contractId, $persisted)) {
                // Fully re-validated below; a corrupt persisted state throws.
                return $this->validatedIdempotentResult($persisted, $contractId, $actorUserId, $sourceFileId);
            }
        } catch (Throwable) {
            // DB unreachable / validation failed: fall through — never a false success.
        }

        // Not provable (or DB unavailable): KEEP the private provisional CMS and
        // report an incomplete compensation. An orphan is safer than a committed DB
        // reference to a deleted artefact. No path is exposed.
        throw ContractSigningException::of(ContractSigningException::PERSISTENCE_FAILED)
            ->markCompensationIncomplete();
    }

    /** Narrow seam for a reconciliation-only DB lookup failure. */
    protected function findSignatureForReconciliation(int $contractId, int $actorUserId, int $sourceFileId): ?Signature
    {
        return $this->findExistingCompletedSignature($contractId, $actorUserId, $sourceFileId);
    }

    private function signingAuditExists(int $contractId, Signature $signature): bool
    {
        $cmsSha = (string) StoredFile::query()->whereKey($signature->signature_file_id)->value('sha256');
        if ($cmsSha === '') {
            return false;
        }

        foreach (AuditEvent::query()
            ->where('action', 'contract.signature_completed')
            ->where('entity_type', class_basename(Contract::class))
            ->where('entity_id', $contractId)
            ->orderByDesc('id')
            ->get() as $event) {
            $meta = $event->metadata;
            if (is_array($meta)
                && (int) ($meta['contract_id'] ?? 0) === $contractId
                && (int) ($meta['signature_id'] ?? 0) === (int) $signature->id
                && (int) ($meta['source_file_id'] ?? 0) === (int) $signature->source_file_id
                && (int) ($meta['cms_file_id'] ?? 0) === (int) $signature->signature_file_id
                && (int) ($meta['certificate_id'] ?? 0) === (int) $signature->certificate_id
                && (int) ($meta['signed_user_id'] ?? 0) === (int) $signature->signed_user_id
                && is_string($meta['source_sha256'] ?? null)
                && is_string($meta['cms_sha256'] ?? null)
                && hash_equals(strtolower((string) $signature->document_hash_after), strtolower($meta['source_sha256']))
                && hash_equals(strtolower($cmsSha), strtolower($meta['cms_sha256']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Narrow seam: the transactional execution boundary. Overridable so a test can
     * deterministically simulate a LOST COMMIT ACKNOWLEDGEMENT (callback + commit
     * succeed, then the driver throws).
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function runInTransaction(\Closure $callback)
    {
        return DB::transaction($callback, 1);
    }

    /**
     * Resolve a lost race after a QueryException, entirely inside the compensation
     * boundary. Only a genuine active-signature unique violation with a validated
     * winner returns idempotently; anything else deletes the provisional CMS and
     * fails closed as PERSISTENCE_FAILED (never a false success).
     */
    private function resolveLostRace(QueryException $e, int $contractId, int $actorUserId, int $sourceFileId, string $cmsPath): ContractSigningResult
    {
        if ($this->isActiveSignatureUniqueViolation($e)) {
            try {
                $winner = $this->resolveRaceWinner($contractId, $actorUserId, $sourceFileId);
                if ($winner !== null) {
                    return $this->returnExistingWinner($winner, $contractId, $actorUserId, $sourceFileId, $cmsPath);
                }
            } catch (ContractSigningException $inner) {
                $this->compensate($cmsPath, $inner);
            } catch (Throwable) {
                // A failed winner lookup must never become a false success:
                // fall through to a stable persistence failure below.
            }
        }

        $this->compensate($cmsPath, ContractSigningException::of(ContractSigningException::PERSISTENCE_FAILED));
    }

    /**
     * Validate the existing winner's artefact, delete our own provisional CMS, and
     * return the idempotent result. A corrupt winner fails closed (and still
     * compensates our provisional CMS) rather than returning a false success.
     */
    private function returnExistingWinner(Signature $winner, int $expectedContractId, int $expectedActorUserId, int $expectedSourceFileId, string $cmsPath): ContractSigningResult
    {
        try {
            $result = $this->validatedIdempotentResult($winner, $expectedContractId, $expectedActorUserId, $expectedSourceFileId);
        } catch (ContractSigningException $e) {
            $this->compensate($cmsPath, $e);
        } catch (Throwable) {
            // Any unexpected lookup/filesystem failure inside the validation stays
            // inside the compensation boundary and never escapes raw.
            $this->compensate($cmsPath, ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID));
        }

        $this->deleteProvisionalOrThrow($cmsPath);

        return $result;
    }

    /**
     * The preflight idempotency short-circuit, where no provisional CMS exists yet.
     * Unexpected failures are normalized so no raw DB/filesystem exception escapes.
     */
    private function guardedIdempotentResult(Signature $existing, int $expectedContractId, int $expectedActorUserId, int $expectedSourceFileId): ContractSigningResult
    {
        try {
            return $this->validatedIdempotentResult($existing, $expectedContractId, $expectedActorUserId, $expectedSourceFileId);
        } catch (ContractSigningException $e) {
            throw $e;
        } catch (Throwable) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }
    }

    // --- results ------------------------------------------------------------

    private function createdResult(Signature $signature, int $sourceFileId, int $cmsSize, string $sourceSha256, string $signerFingerprint): ContractSigningResult
    {
        return new ContractSigningResult(
            signatureId: (int) $signature->id,
            contractId: (int) $signature->contract_id,
            sourceFileId: $sourceFileId,
            cmsFileId: (int) $signature->signature_file_id,
            certificateId: (int) $signature->certificate_id,
            sourceSha256: $sourceSha256,
            signerFingerprint: $signerFingerprint,
            signedAt: $signature->signed_at?->toIso8601String() ?? now()->toIso8601String(),
            idempotentExisting: false,
        );
    }

    /**
     * Build an idempotent result ONLY after fresh-loading and fully re-proving the
     * persisted state against the EXPECTED tuple: the Signature belongs to this
     * contract/actor/source, the source artefact is still the contract's intact
     * final PDF, the certificate still belongs to the actor with a real fingerprint,
     * and the CMS artefact is physically intact. Any deviation fails closed with
     * PERSISTED_SIGNATURE_INVALID — never a success, and never a silent re-sign
     * (the partial unique invariant still holds the corrupt active tuple).
     */
    private function validatedIdempotentResult(Signature $signature, int $expectedContractId, int $expectedActorUserId, int $expectedSourceFileId): ContractSigningResult
    {
        $fresh = $this->signatureForValidation((int) $signature->getKey());

        if ($fresh === null
            || $fresh->status !== Signature::STATUS_COMPLETED
            || (int) $fresh->contract_id !== $expectedContractId
            || (int) $fresh->signed_user_id !== $expectedActorUserId
            || (int) $fresh->source_file_id !== $expectedSourceFileId
            || $fresh->certificate_id === null
            || $fresh->signature_file_id === null
            || blank($fresh->signed_at)
            || blank($fresh->document_hash_before)
            || blank($fresh->document_hash_after)
            || ! hash_equals(strtolower((string) $fresh->document_hash_before), strtolower((string) $fresh->document_hash_after))) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }

        // The contract must still point at that exact source, and the source must
        // still be the intact final PDF whose hash the signature attests to.
        $contract = $this->contractForValidation($expectedContractId);
        $sourceFile = $this->storedFileForValidation($expectedSourceFileId, 'source');
        if ($contract === null
            || $sourceFile === null
            || (int) $contract->final_pdf_file_id !== $expectedSourceFileId) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }
        try {
            [, $sourceSha] = $this->finalPdfVerifier->verify($contract, $sourceFile);
        } catch (FinalPdfException) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }
        if (! hash_equals(strtolower((string) $fresh->document_hash_after), $sourceSha)) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }

        // The certificate must still exist, belong to the expected actor, and carry
        // a real fingerprint — the persisted fingerprint is what we return.
        $certificate = $this->certificateForValidation((int) $fresh->certificate_id);
        $fingerprint = strtolower((string) ($certificate->thumbprint_sha256 ?? ''));
        if ($certificate === null
            || $certificate->owner_type !== Certificate::OWNER_TYPE_USER
            || (int) $certificate->owner_user_id !== $expectedActorUserId
            || $fingerprint === '') {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }

        $cms = $this->storedFileForValidation((int) $fresh->signature_file_id, 'cms');
        if ($cms === null
            || $cms->purpose !== StoredFile::PURPOSE_CMS_SIGNATURE
            || $cms->storage_disk !== StoredFile::DISK_LOCAL) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }

        $disk = Storage::disk(StoredFile::DISK_LOCAL);
        $storagePath = (string) $cms->storage_path;

        try {
            if (! $disk->exists($storagePath)) {
                throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
            }
        } catch (ContractSigningException $e) {
            throw $e;
        } catch (Throwable) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }

        try {
            $absolutePath = $this->storagePathForValidation($disk, $storagePath);
            $physicalBytes = $this->physicalBytesForValidation($disk, $storagePath);
            $physicalSize = $this->physicalSizeForValidation($absolutePath);
        } catch (Throwable) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }
        if ($physicalSize === false || (int) $physicalSize !== (int) $cms->size_bytes) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }

        try {
            $physicalSha = $this->physicalShaForValidation($absolutePath);
        } catch (Throwable) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }
        if (! is_string($physicalBytes)
            || strlen($physicalBytes) !== (int) $cms->size_bytes
            || ! is_string($physicalSha)
            || ! hash_equals(strtolower((string) $cms->sha256), strtolower($physicalSha))
            || ! hash_equals(strtolower((string) $cms->sha256), hash('sha256', $physicalBytes))) {
            throw ContractSigningException::of(ContractSigningException::PERSISTED_SIGNATURE_INVALID);
        }

        return new ContractSigningResult(
            signatureId: (int) $fresh->id,
            contractId: (int) $fresh->contract_id,
            sourceFileId: (int) $fresh->source_file_id,
            cmsFileId: (int) $fresh->signature_file_id,
            certificateId: (int) $fresh->certificate_id,
            sourceSha256: strtolower((string) $fresh->document_hash_after),
            signerFingerprint: $fingerprint,
            signedAt: $fresh->signed_at?->toIso8601String() ?? '',
            idempotentExisting: true,
        );
    }

    // --- compensation -------------------------------------------------------

    /**
     * Remove the provisional CMS artefact and re-throw the stable domain failure.
     * If cleanup cannot be confirmed, the compensation-incomplete signal is set on
     * the exception (no path is ever exposed).
     */
    private function compensate(string $cmsPath, ContractSigningException $e): never
    {
        if (! $this->deleteProvisional(Storage::disk(StoredFile::DISK_LOCAL), $cmsPath)) {
            $e->markCompensationIncomplete();
        }

        throw $e;
    }

    /**
     * Delete the provisional CMS artefact after a resolved race; a confirmed-
     * incomplete cleanup is surfaced as a stable persistence failure with the
     * compensation signal set.
     */
    private function deleteProvisionalOrThrow(string $cmsPath): void
    {
        if (! $this->deleteProvisional(Storage::disk(StoredFile::DISK_LOCAL), $cmsPath)) {
            throw ContractSigningException::of(ContractSigningException::PERSISTENCE_FAILED)
                ->markCompensationIncomplete();
        }
    }

    /**
     * Best-effort deletion confirmed by a follow-up existence check. Returns true
     * only when the artefact is provably gone. Protected so a test can force a
     * cleanup failure and prove the compensation-incomplete signal.
     */
    protected function deleteProvisional(Filesystem $disk, string $path): bool
    {
        try {
            $disk->delete($path);
        } catch (Throwable) {
            // fall through to the existence check
        }

        try {
            return ! $disk->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    // --- unique-violation classifier ---------------------------------------

    /**
     * Only a genuine violation of the active-signature partial unique index counts
     * as a lost race; any other DB error (other unique, FK, CHECK, wrapper-only
     * message) is a plain persistence failure. Protected for a table-driven test.
     */
    protected function isActiveSignatureUniqueViolation(QueryException $e): bool
    {
        $target = 'signatures_contract_user_source_active_unique';

        // PostgreSQL: exact SQLSTATE 23505 AND the quoted constraint/index name,
        // parsed from the RAW driver message and matched in full (no prefix/suffix/
        // shadow name, no accidental substring, no Laravel wrapper text).
        if ((string) $e->getCode() === '23505') {
            $raw = $e->errorInfo[2] ?? null;
            if (! is_string($raw) || preg_match('/\bconstraint\s+"([^"]+)"/i', $raw, $m) !== 1) {
                return false;
            }

            return $m[1] === $target;
        }

        // SQLite: only the RAW driver message (errorInfo[2]), whitespace-normalized,
        // matched in FULL against the exact (contract_id, signed_user_id,
        // source_file_id) tuple — no extra/reordered column, no other unique/FK/CHECK.
        $raw = $e->errorInfo[2] ?? null;
        if (! is_string($raw)) {
            return false;
        }
        $normalized = preg_replace('/\s+/', ' ', trim($raw));

        return $normalized === 'UNIQUE constraint failed: signatures.contract_id, signatures.signed_user_id, signatures.source_file_id';
    }

    // --- test seams ---------------------------------------------------------

    /**
     * Runs after signing + CMS storage and immediately before the persistence
     * transaction, INSIDE the compensation boundary. Overridden in tests to mutate
     * contract/certificate/final-PDF state or commit a competing signature.
     */
    protected function beforePersistence(Contract $contract, Certificate $certificate, StoredFile $finalPdf): void {}

    /**
     * The idempotency recheck under the persistence lock. Overridable so a test can
     * force the insert to reach the DB unique-index guard (proving the last-resort
     * DB race guard, not only the application recheck).
     */
    protected function recheckExistingSignature(int $contractId, int $actorUserId, int $sourceFileId): ?Signature
    {
        return $this->findExistingCompletedSignature($contractId, $actorUserId, $sourceFileId);
    }

    /**
     * The winner lookup performed inside the compensation boundary after a DB
     * unique-index race. Overridable so a test can prove that a THROWING lookup
     * still cleans up the provisional CMS and fails closed (never a false success).
     */
    protected function resolveRaceWinner(int $contractId, int $actorUserId, int $sourceFileId): ?Signature
    {
        return $this->findExistingCompletedSignature($contractId, $actorUserId, $sourceFileId);
    }

    /**
     * The sole CMS artefact writer seam. Overridable so a test can simulate a
     * storage write failure without touching real disk behaviour.
     */
    protected function putCmsBytes(Filesystem $disk, string $path, string $der): bool
    {
        return $disk->put($path, $der) === true;
    }

    /**
     * Runs immediately after the CMS artefact is written and before it is read back
     * and verified. Overridden in tests to corrupt the artefact and prove the
     * physical readback-mismatch compensation.
     */
    protected function afterCmsWritten(Filesystem $disk, string $path): void {}

    /** Validation lookup/I/O seams used by both idempotency and race-winner paths. */
    protected function signatureForValidation(int $signatureId): ?Signature
    {
        return Signature::query()->whereKey($signatureId)->first();
    }

    protected function contractForValidation(int $contractId): ?Contract
    {
        return Contract::query()->whereKey($contractId)->first();
    }

    protected function storedFileForValidation(int $storedFileId, string $role): ?StoredFile
    {
        return StoredFile::query()->whereKey($storedFileId)->first();
    }

    protected function certificateForValidation(int $certificateId): ?Certificate
    {
        return Certificate::query()->whereKey($certificateId)->first();
    }

    protected function storagePathForValidation(Filesystem $disk, string $storagePath): string
    {
        return $disk->path($storagePath);
    }

    protected function physicalBytesForValidation(Filesystem $disk, string $storagePath): string
    {
        return $disk->get($storagePath);
    }

    protected function physicalSizeForValidation(string $absolutePath): int|false
    {
        return @filesize($absolutePath);
    }

    protected function physicalShaForValidation(string $absolutePath): string|false
    {
        return @hash_file('sha256', $absolutePath);
    }

    /**
     * The signing audit event, written inside the same persistence transaction as
     * the CMS StoredFile and completed Signature. A seam so a test can force an
     * audit-insert failure and prove the whole transaction rolls back and the CMS
     * artefact is compensated.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function recordSigningAudit(Contract $contract, int $actorUserId, array $metadata): void
    {
        // The SAME resolved authenticated actor that authorized the signature and
        // is written to Signature.signed_user_id is passed explicitly, so the audit
        // actor can never diverge from the domain actor.
        $this->auditLogger->record('contract.signature_completed', $contract, $metadata, null, $actorUserId);
    }
}
