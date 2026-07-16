<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Exceptions\Signing\FinalPdfException;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\StoredFile;
use App\Services\Audit\AuditLogger;
use App\Services\Signing\FinalPdfIntegrityVerifier;
use App\Services\Signing\FinalPdfVerificationBindingVerifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Create-only, immutable final-PDF generator.
 *
 * Every successful generation writes a NEW unpredictable private path and creates
 * a NEW StoredFile row, then re-points the Contract binding. An existing final-PDF
 * artefact is NEVER overwritten and its StoredFile row is NEVER mutated, so a DB
 * rollback can always leave the previous artefact fully intact (overwriting the
 * old bytes would be unrecoverable by a DB rollback).
 *
 * Ordering (freeze-before-sign):
 *   1. open the transaction and lockForUpdate() the Contract row FIRST;
 *   2. read every render input from the LOCKED row (never the caller's stale
 *      model): snapshot, status, public-verification token, QR/URL inputs;
 *   3. refuse if the current final PDF already carries a pending/completed
 *      signature — before any render and before any filesystem write;
 *   4. render, then write to a NEW create-only path;
 *   5. physically read back and confirm byte-equality, size and SHA-256;
 *   6. create the NEW StoredFile, re-point the Contract, audit, commit;
 *   7. refresh the caller's model only after a successful commit.
 *
 * Compensation: if anything fails after exact readback proved ownership, ONLY that
 * new create-only artefact is deleted (confirmed with exists()). An unowned or
 * unverified collision is retained. The previous binding, StoredFile and bytes are
 * untouched; incomplete cleanup preserves the primary code and exposes no path.
 *
 * Known P3: a hard process/host crash between the create-only write and the DB
 * commit can leave an orphan file. It is harmless by construction — it has no
 * StoredFile row, is not the active binding, and never replaces the previous valid
 * artefact; it is identifiable by comparing storage against files.storage_path.
 *
 * Known P3: the generic Flysystem exists()+put() pair is not an atomic exclusive
 * create. UUID paths make a collision negligible, but adapters without an
 * exclusive-create primitive retain a TOCTOU window. A failed/unverified write is
 * never deleted unless exact readback first proved this operation owned the bytes.
 *
 * The class is intentionally non-final to expose narrow protected seams for
 * deterministic storage/persistence failure-injection tests.
 */
class FinalPdfGenerator
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PublicVerificationQrCode $qrCode,
        private readonly FinalPdfIntegrityVerifier $integrityVerifier,
        private readonly FinalPdfVerificationBindingVerifier $bindingVerifier,
    ) {}

    public function generate(
        Contract $contract,
        int $createdByUserId,
    ): StoredFile {
        return $this->generateOwnedTransaction($contract, $createdByUserId, 'manual');
    }

    /** Public-verification workflow with one non-configurable transaction owner. */
    public function generateForPublicVerification(
        Contract $contract,
        int $createdByUserId,
    ): StoredFile {
        $tokenCreated = false;

        return $this->generateOwnedTransaction(
            $contract,
            $createdByUserId,
            FinalPdfVerificationBindingVerifier::GENERATION_REASON,
            function (Contract $locked) use (&$tokenCreated): void {
                $tokenCreated = blank($locked->public_verification_token);
                if ($tokenCreated) {
                    do {
                        $token = Str::random(64);
                    } while (Contract::query()
                        ->where('public_verification_token', $token)
                        ->exists());

                    $locked->public_verification_token = $token;
                }

                $locked->public_verification_enabled_at ??= now();
                $locked->public_verification_revoked_at = null;
                $locked->save();
            },
            function (Contract $locked, StoredFile $storedFile) use ($createdByUserId, &$tokenCreated): void {
                $this->recordPublicVerificationAudit($locked, $storedFile, $createdByUserId, $tokenCreated);
            },
        );
    }

    /**
     * @param  (\Closure(Contract): void)|null  $beforeGeneration
     * @param  (\Closure(Contract, StoredFile): void)|null  $afterGeneration
     */
    private function generateOwnedTransaction(
        Contract $contract,
        int $createdByUserId,
        string $reason,
        ?\Closure $beforeGeneration = null,
        ?\Closure $afterGeneration = null,
    ): StoredFile {
        if (DB::transactionLevel() !== 0) {
            // A savepoint is not a durable commit. Refuse implicit nesting so
            // reconciliation can only run outside a real top-level boundary.
            throw FinalPdfException::of(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED);
        }

        $newPath = null;
        $writeAttempted = false;
        $ownershipProven = false;
        // Set ONLY after every DB operation in the callback has completed. If the
        // callback finished but DB::transaction still threw (e.g. the COMMIT
        // succeeded and only the acknowledgement was lost), the commit outcome is
        // AMBIGUOUS and the new artefact must never be blindly deleted.
        $callbackCompleted = false;
        $expected = ['fileId' => null, 'sha' => null, 'size' => null, 'token' => null, 'reason' => $reason];

        try {
            $storedFile = $this->runInTransaction(function () use (
                $contract,
                $createdByUserId,
                $reason,
                $beforeGeneration,
                $afterGeneration,
                &$newPath,
                &$writeAttempted,
                &$ownershipProven,
                &$callbackCompleted,
                &$expected
            ): StoredFile {
                // 1. Lock the Contract row BEFORE reading render inputs or writing.
                $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->first();
                abort_if($locked === null, 404);

                if ($beforeGeneration !== null) {
                    $beforeGeneration($locked);
                }

                // 2. Every render input comes from the freshly locked row.
                $snapshot = $this->verifiedSnapshot($locked);

                // 3. Immutability guard: refuse before render and before any write.
                $this->integrityVerifier->assertNotActivelySigned($locked);

                $generatedAt = now();
                // The canonical URL is derived ONCE from the locked row's token and
                // is both rendered into the QR and hashed into the generation proof.
                $token = filled($locked->public_verification_token)
                    ? (string) $locked->public_verification_token
                    : null;
                $verificationUrl = $token !== null
                    ? $this->bindingVerifier->canonicalVerificationUrl($token)
                    : null;
                $pdfContent = $this->renderPdf($locked, $snapshot, $generatedAt, $verificationUrl);

                // 4. Create-only: a new unpredictable, private, PII-free path per
                //    attempt. The previous artefact is never touched.
                $newPath = 'contracts/'.$locked->id.'/final-pdfs/final-'.Str::uuid()->toString().'.pdf';
                $disk = Storage::disk(StoredFile::DISK_LOCAL);

                if ($disk->exists($newPath)) {
                    throw FinalPdfException::of(FinalPdfException::FINAL_PDF_STORAGE_FAILED);
                }

                // 5. Write + physical readback proof. Any failure in this region —
                //    including an unexpected filesystem exception — is a storage
                //    failure, never a raw driver message.
                try {
                    $writeAttempted = true;
                    if (! $this->putPdf($disk, $newPath, $pdfContent)) {
                        throw FinalPdfException::of(FinalPdfException::FINAL_PDF_STORAGE_FAILED);
                    }

                    // Test seam: corrupt/remove the just-written artefact.
                    $this->afterPdfWritten($disk, $newPath);

                    // Size and SHA-256 come from the bytes actually on disk, never
                    // from the in-memory render alone.
                    $readBack = $disk->get($newPath);
                } catch (FinalPdfException $e) {
                    throw $e;
                } catch (Throwable) {
                    throw FinalPdfException::of(FinalPdfException::FINAL_PDF_STORAGE_FAILED);
                }

                if (! is_string($readBack)
                    || strlen($readBack) !== strlen($pdfContent)
                    || ! hash_equals($pdfContent, $readBack)) {
                    throw FinalPdfException::of(FinalPdfException::FINAL_PDF_STORAGE_FAILED);
                }

                $ownershipProven = true;

                $physicalSize = strlen($readBack);
                $physicalSha = hash('sha256', $readBack);
                if (! hash_equals(hash('sha256', $pdfContent), $physicalSha)) {
                    throw FinalPdfException::of(FinalPdfException::FINAL_PDF_STORAGE_FAILED);
                }

                // 6. NEW StoredFile row (never reuse/mutate the previous one).
                $storedFile = $this->createStoredFile(
                    $locked,
                    $newPath,
                    $physicalSize,
                    $physicalSha,
                    $createdByUserId,
                );

                $this->rebindContract($locked, (int) $storedFile->id, $physicalSha);

                $metadata = [
                    'file_id' => $storedFile->id,
                    'purpose' => $storedFile->purpose,
                    'final_pdf_sha256' => $physicalSha,
                    'finalized_snapshot_sha256' => $locked->finalized_snapshot_sha256,
                    'generated_at' => $generatedAt->toIso8601String(),
                    'status' => $locked->status,
                    'generation_reason' => $reason,
                ];

                // EXACT token->PDF/QR proof, written in this same transaction as the
                // StoredFile + binding (and, for the enable flow, the token
                // activation). Only SHA-256 digests are persisted — never the plain
                // token or URL. Every hash key ends in `_sha256`, which the audit
                // sanitizer treats as explicitly safe.
                if ($token !== null) {
                    $metadata += $this->bindingVerifier->proofMetadata(
                        (int) $locked->id,
                        (int) $storedFile->id,
                        $physicalSha,
                        $token,
                    );
                    $metadata['generation_reason'] = $reason;
                }

                $this->recordGenerationAudit($locked, $createdByUserId, $metadata);

                if ($afterGeneration !== null) {
                    $afterGeneration($locked, $storedFile);
                }

                $expected = [
                    'fileId' => (int) $storedFile->id,
                    'sha' => $physicalSha,
                    'size' => $physicalSize,
                    'token' => $token,
                    'reason' => $reason,
                ];
                $callbackCompleted = true;

                return $storedFile;
            });
        } catch (Throwable $e) {
            // Ambiguous commit outcome: the callback finished, so the COMMIT may
            // already have succeeded. Never blindly delete the artefact the DB may
            // now reference — reconcile against fresh state instead.
            if ($callbackCompleted) {
                return $this->reconcileAmbiguousCommit($contract, $newPath, $expected);
            }

            $this->compensate($newPath, $writeAttempted, $ownershipProven, $e);
        }

        // 7. Refresh the caller's instance only after a successful commit.
        $contract->final_pdf_file_id = $storedFile->id;
        $contract->final_pdf_sha256 = $storedFile->sha256;
        $contract->setRelation('finalPdfFile', $storedFile);

        return $storedFile;
    }

    /**
     * The commit outcome is unknown: the transaction callback completed, so the
     * COMMIT may already be durable even though DB::transaction threw.
     *
     * Deleting the new PDF here could leave a committed Contract binding pointing at
     * a file we just destroyed — strictly worse than an orphan. So the artefact is
     * KEPT and success is only claimed when fresh state proves the commit landed.
     *
     * @param  array{fileId: int|null, sha: string|null, size: int|null, token: string|null, reason: string}  $expected
     */
    private function reconcileAmbiguousCommit(Contract $contract, ?string $newPath, array $expected): StoredFile
    {
        try {
            $fresh = $this->findContractForReconciliation((int) $contract->id);
            $stored = $expected['fileId'] !== null
                ? $this->findStoredFileForReconciliation((int) $expected['fileId'])
                : null;

            $committed = $fresh !== null
                && $stored !== null
                && (int) $fresh->final_pdf_file_id === (int) $expected['fileId']
                && is_string($expected['sha'])
                && hash_equals(strtolower((string) $fresh->final_pdf_sha256), $expected['sha'])
                && $stored->purpose === StoredFile::PURPOSE_FINAL_PDF
                && $stored->storage_disk === StoredFile::DISK_LOCAL
                && (string) $stored->storage_path === (string) $newPath
                && (int) $stored->size_bytes === (int) $expected['size']
                && hash_equals(strtolower((string) $stored->sha256), $expected['sha'])
                && $this->generationAuditExists(
                    (int) $contract->id,
                    (int) $expected['fileId'],
                    $expected['sha'],
                    $expected['reason'],
                )
                && $this->physicalArtefactMatches((string) $newPath, (int) $expected['size'], $expected['sha'])
                && $this->publicVerificationCommitMatches($fresh, $stored, $expected);

            if ($committed) {
                // Proven durable: keep the artefact and report success.
                $contract->final_pdf_file_id = $stored->id;
                $contract->final_pdf_sha256 = $stored->sha256;
                $contract->setRelation('finalPdfFile', $stored);

                return $stored;
            }
        } catch (Throwable) {
            // DB unreachable / lookup failed: fall through — never a false success.
        }

        // Not provable (or DB unavailable): KEEP the private create-only artefact and
        // report an incomplete compensation. An orphan is safer than a committed DB
        // reference to a deleted file. No path is exposed.
        throw FinalPdfException::of(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED)
            ->markCompensationIncomplete();
    }

    /** Narrow seams for deterministic reconciliation lookup failures. */
    protected function findContractForReconciliation(int $contractId): ?Contract
    {
        return Contract::query()->whereKey($contractId)->first();
    }

    protected function findStoredFileForReconciliation(int $storedFileId): ?StoredFile
    {
        return StoredFile::query()->whereKey($storedFileId)->first();
    }

    private function generationAuditExists(int $contractId, int $finalPdfFileId, ?string $sha256, string $reason): bool
    {
        if ($sha256 === null) {
            return false;
        }

        foreach (AuditEvent::query()
            ->where('action', 'contract.final_pdf_generated')
            ->where('entity_type', class_basename(Contract::class))
            ->where('entity_id', $contractId)
            ->orderByDesc('id')
            ->get() as $event) {
            $meta = $event->metadata;
            if (is_array($meta)
                && (int) ($meta['file_id'] ?? 0) === $finalPdfFileId
                && ($meta['generation_reason'] ?? null) === $reason
                && is_string($meta['final_pdf_sha256'] ?? null)
                && hash_equals(strtolower($sha256), strtolower($meta['final_pdf_sha256']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A public-verification reconciliation proves the entire workflow, not merely
     * the PDF row: active token state, exact token/PDF proof and enable audit must
     * all be durable. Manual PDF generation has no additional workflow predicate.
     *
     * @param  array{fileId: int|null, sha: string|null, size: int|null, token: string|null, reason: string}  $expected
     */
    private function publicVerificationCommitMatches(Contract $fresh, StoredFile $stored, array $expected): bool
    {
        if ($expected['reason'] !== FinalPdfVerificationBindingVerifier::GENERATION_REASON) {
            return true;
        }

        $token = $expected['token'];
        if (! is_string($token)
            || $token === ''
            || $fresh->public_verification_enabled_at === null
            || $fresh->public_verification_revoked_at !== null
            || ! $this->bindingVerifier->hasGenerationProof(
                $fresh,
                (int) $stored->id,
                (string) $stored->sha256,
                $token,
            )) {
            return false;
        }

        foreach (AuditEvent::query()
            ->where('action', 'contract.public_verification_enabled')
            ->where('entity_type', class_basename(Contract::class))
            ->where('entity_id', $fresh->id)
            ->orderByDesc('id')
            ->get() as $event) {
            $meta = $event->metadata;
            if (is_array($meta)
                && ($meta['final_pdf_regenerated'] ?? null) === true
                && (int) ($meta['final_pdf_file_id'] ?? 0) === (int) $stored->id
                && is_string($meta['final_pdf_sha256'] ?? null)
                && is_string($meta['public_verification_token_sha256'] ?? null)
                && hash_equals(strtolower((string) $stored->sha256), strtolower($meta['final_pdf_sha256']))
                && hash_equals($this->bindingVerifier->tokenHash($token), strtolower($meta['public_verification_token_sha256']))) {
                return true;
            }
        }

        return false;
    }

    private function physicalArtefactMatches(string $path, int $size, ?string $sha): bool
    {
        if ($sha === null) {
            return false;
        }

        try {
            $disk = Storage::disk(StoredFile::DISK_LOCAL);
            if (! $disk->exists($path)) {
                return false;
            }
            $bytes = $disk->get($path);
        } catch (Throwable) {
            return false;
        }

        return is_string($bytes)
            && strlen($bytes) === $size
            && hash_equals($sha, hash('sha256', $bytes));
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
     * Delete ONLY the new create-only artefact and re-raise a neutral failure. The
     * previous binding/StoredFile/bytes are never touched here.
     */
    private function compensate(
        ?string $newPath,
        bool $writeAttempted,
        bool $ownershipProven,
        Throwable $e,
    ): never {
        $cleanupConfirmed = true;
        if ($writeAttempted && $newPath !== null) {
            $disk = Storage::disk(StoredFile::DISK_LOCAL);
            if ($ownershipProven) {
                $cleanupConfirmed = $this->deleteProvisional($disk, $newPath);
            } else {
                // A racing writer may own this path. Never delete without exact
                // readback proof that this operation wrote its own bytes.
                try {
                    $cleanupConfirmed = ! $disk->exists($newPath);
                } catch (Throwable) {
                    $cleanupConfirmed = false;
                }
            }
        }

        // abort() based lifecycle failures keep their HTTP semantics.
        if ($e instanceof HttpExceptionInterface) {
            throw $e;
        }

        if ($e instanceof FinalPdfException) {
            if (! $cleanupConfirmed) {
                $e->markCompensationIncomplete();
            }

            throw $e;
        }

        // Any DB/filesystem/other failure is normalized: no SQL, driver text or path.
        $normalized = FinalPdfException::of(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED);
        if (! $cleanupConfirmed) {
            $normalized->markCompensationIncomplete();
        }

        throw $normalized;
    }

    /**
     * Best-effort deletion confirmed by a follow-up existence check. Protected so a
     * test can force a cleanup failure and prove compensationIncomplete.
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

    /**
     * Render from the LOCKED contract only. The verification URL/QR are derived
     * from the locked row's current token, so the artefact always embeds the token
     * that is active at write time.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function renderPdf(Contract $locked, array $snapshot, Carbon $generatedAt, ?string $verificationUrl): string
    {
        return Pdf::loadView('contracts.pdf.final', [
            'contract' => $locked,
            'snapshot' => $snapshot,
            'generatedAt' => $generatedAt,
            'verificationUrl' => $verificationUrl,
            'qrCodeDataUri' => $verificationUrl ? $this->qrCode->dataUri($verificationUrl) : null,
        ])
            ->setPaper('a4')
            ->setOption('enable_php', true)
            ->output();
    }

    /** Narrow seam: the sole final-PDF writer. */
    protected function putPdf(Filesystem $disk, string $path, string $contents): bool
    {
        return $disk->put($path, $contents) === true;
    }

    /** Narrow seam: runs after the write, before the physical readback. */
    protected function afterPdfWritten(Filesystem $disk, string $path): void {}

    /** Narrow seam: the NEW StoredFile insert (create-only). */
    protected function createStoredFile(
        Contract $locked,
        string $path,
        int $size,
        string $sha256,
        int $createdByUserId,
    ): StoredFile {
        return StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_FINAL_PDF,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => $path,
            'original_filename' => 'contract-'.$locked->id.'-final.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $size,
            'sha256' => $sha256,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /** Narrow seam: re-point the Contract binding to the new artefact. */
    protected function rebindContract(Contract $locked, int $storedFileId, string $sha256): void
    {
        $locked->final_pdf_file_id = $storedFileId;
        $locked->final_pdf_sha256 = $sha256;
        $locked->save();
    }

    /**
     * Narrow seam: the generation audit event, written inside the same transaction
     * as the new StoredFile and the re-binding. The explicit trusted actor id keeps
     * the audit actor aligned with the caller's resolved actor.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function recordGenerationAudit(Contract $locked, int $actorUserId, array $metadata): void
    {
        $this->auditLogger->record('contract.final_pdf_generated', $locked, $metadata, null, $actorUserId);
    }

    /** Public-enable audit is part of the same top-level transaction as its proof. */
    protected function recordPublicVerificationAudit(
        Contract $locked,
        StoredFile $storedFile,
        int $actorUserId,
        bool $tokenCreated,
    ): void {
        $token = (string) $locked->public_verification_token;
        $this->auditLogger->record('contract.public_verification_enabled', $locked, [
            'public_verification_token_created' => $tokenCreated,
            'enabled_at' => $locked->public_verification_enabled_at?->toIso8601String(),
            'route_name' => 'contracts.public-verification.enable',
            'final_pdf_regenerated' => true,
            'final_pdf_file_id' => (int) $storedFile->id,
            'final_pdf_sha256' => (string) $storedFile->sha256,
            'public_verification_token_sha256' => $this->bindingVerifier->tokenHash($token),
        ], null, $actorUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedSnapshot(Contract $contract): array
    {
        abort_unless($contract->isFinalized(), 403);
        abort_unless($contract->isLocked(), 403);
        abort_if($contract->finalized_at === null, 422, 'Nedostaje vrijeme finalizacije ugovora.');
        abort_if(empty($contract->filled_data_snapshot), 422, 'Finalizirani ugovor nema snapshot.');
        abort_if(
            blank($contract->finalized_snapshot_sha256),
            422,
            'Nedostaje hash finaliziranog snapshota.'
        );

        $snapshot = $contract->filled_data_snapshot;
        $actualSnapshotSha256 = hash(
            'sha256',
            json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );

        abort_unless(
            hash_equals(strtolower($contract->finalized_snapshot_sha256), $actualSnapshotSha256),
            422,
            'Finalizirani snapshot nije prošao provjeru integriteta.'
        );

        return $snapshot;
    }
}
