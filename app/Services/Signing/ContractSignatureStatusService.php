<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\FinalPdfException;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only, fail-closed presenter of the persisted signature state of a
 * contract's CURRENT final PDF, built entirely on the existing M10/M11
 * production verifiers — it re-implements no rule of its own.
 *
 * It NEVER mutates anything: no DB write, no Signature/token change, no file
 * write, no regeneration. Every operational failure (missing trust anchor,
 * unreadable artefact, unexpected exception) collapses into a fail-closed
 * status that either reports the exact failing integrity signal or an
 * "unavailable" state — never a false success and never a leaked path,
 * driver message, or OpenSSL detail.
 *
 * The signature identity is the EXACT current binding: a completed Signature
 * whose source_file_id equals the contract's final_pdf_file_id. A signature
 * bound to an older, replaced final PDF is deliberately NOT presented as the
 * current document's signature.
 */
final class ContractSignatureStatusService
{
    public function __construct(
        private readonly FinalPdfIntegrityVerifier $finalPdfVerifier,
        private readonly DetachedCmsVerifier $cmsVerifier,
        private readonly SigningConfig $config,
    ) {}

    public function status(Contract $contract): ContractSignatureStatus
    {
        try {
            $signature = $this->completedSignatureForCurrentPdf($contract);
        } catch (Throwable) {
            // Presence itself could not be established: fail closed without
            // claiming a signature exists.
            return ContractSignatureStatus::absent();
        }

        if ($signature === null) {
            return ContractSignatureStatus::absent();
        }

        $signedAtIso = $signature->signed_at?->toIso8601String();

        try {
            return $this->verify($contract, $signature, $signedAtIso);
        } catch (Throwable) {
            return ContractSignatureStatus::unavailable($signedAtIso);
        }
    }

    private function completedSignatureForCurrentPdf(Contract $contract): ?Signature
    {
        if ($contract->final_pdf_file_id === null) {
            return null;
        }

        return Signature::query()
            ->where('contract_id', $contract->id)
            ->where('status', Signature::STATUS_COMPLETED)
            ->where('source_file_id', (int) $contract->final_pdf_file_id)
            ->whereNotNull('signature_file_id')
            ->orderByDesc('id')
            ->first();
    }

    private function verify(Contract $contract, Signature $signature, ?string $signedAtIso): ContractSignatureStatus
    {
        // 1. Physical final-PDF integrity through the shared production verifier.
        $sourceFile = StoredFile::query()->whereKey($signature->source_file_id)->first();
        if ($sourceFile === null) {
            return ContractSignatureStatus::pdfIntegrityFailed($signedAtIso);
        }

        try {
            [$absolutePdfPath] = $this->finalPdfVerifier->verify($contract, $sourceFile);
        } catch (FinalPdfException) {
            return ContractSignatureStatus::pdfIntegrityFailed($signedAtIso);
        }

        // 2. Physical CMS artefact integrity against its own StoredFile record.
        $cmsBytes = $this->verifiedCmsBytes($signature);
        if ($cmsBytes === null) {
            return ContractSignatureStatus::cmsIntegrityFailed($signedAtIso);
        }

        // 3. Detached CMS verification via the M10 verifier: independent
        //    crypto/trust/time/active/fingerprint/source-hash signals. The
        //    expected source hash is what the SIGNATURE attests to, and the
        //    expected fingerprint is the persisted signer certificate thumbprint.
        $certificate = $signature->certificate;
        $fingerprint = strtolower((string) ($certificate?->thumbprint_sha256 ?? ''));
        $certificateActive = $certificate !== null && (bool) $certificate->is_active;

        $result = $this->cmsVerifier->verify(new DetachedCmsVerificationRequest(
            $absolutePdfPath,
            $cmsBytes,
            $this->config->rootCaPath(),
            $fingerprint,
            strtolower((string) $signature->document_hash_after),
            $certificateActive,
        ));

        return ContractSignatureStatus::verified(
            $result,
            $signedAtIso,
            $fingerprint !== '' ? $fingerprint : null,
        );
    }

    /**
     * Read the .p7s bytes exactly once and require byte length + SHA-256 to
     * match the CMS StoredFile record. Any mismatch or read failure yields null.
     */
    private function verifiedCmsBytes(Signature $signature): ?string
    {
        $cms = $signature->signatureFile;

        if ($cms === null
            || $cms->purpose !== StoredFile::PURPOSE_CMS_SIGNATURE
            || $cms->storage_disk !== StoredFile::DISK_LOCAL
            || blank($cms->storage_path)) {
            return null;
        }

        $disk = Storage::disk(StoredFile::DISK_LOCAL);
        $path = (string) $cms->storage_path;

        try {
            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($bytes)
            || strlen($bytes) !== (int) $cms->size_bytes
            || ! hash_equals(strtolower((string) $cms->sha256), hash('sha256', $bytes))) {
            return null;
        }

        return $bytes;
    }
}
