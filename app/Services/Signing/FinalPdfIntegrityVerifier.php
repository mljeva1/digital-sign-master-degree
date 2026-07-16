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
 * Single source of truth for the final-PDF binding + physical integrity rules and
 * the "already actively signed" immutability guard, shared by FinalPdfGenerator
 * (which must refuse to regenerate an actively-signed final PDF) and
 * ContractSigningService (which must sign only the exact, intact final PDF).
 *
 * Keeping the rules here prevents the generator and the signer from drifting
 * apart. No storage path or raw filesystem message is ever exposed: every failure
 * is a neutral FinalPdfException.
 */
final class FinalPdfIntegrityVerifier
{
    /**
     * Confirm the resolved StoredFile is the exact current final-PDF artefact of
     * the contract and that its physical bytes match the recorded size + SHA-256
     * (which must equal the contract's final_pdf_sha256).
     *
     * @return array{0: string, 1: string} canonical local path, lowercase SHA-256
     */
    public function verify(Contract $contract, StoredFile $finalPdf): array
    {
        if ($contract->final_pdf_file_id === null
            || (int) $contract->final_pdf_file_id !== (int) $finalPdf->id
            || $finalPdf->purpose !== StoredFile::PURPOSE_FINAL_PDF
            || $finalPdf->storage_disk !== StoredFile::DISK_LOCAL) {
            $this->fail();
        }

        $recordedSha = strtolower((string) $finalPdf->sha256);
        $contractSha = strtolower((string) $contract->final_pdf_sha256);
        if ($recordedSha === '' || ! hash_equals($contractSha, $recordedSha)) {
            $this->fail();
        }

        $disk = Storage::disk(StoredFile::DISK_LOCAL);
        $storagePath = (string) $finalPdf->storage_path;

        try {
            if (! $disk->exists($storagePath)) {
                $this->fail();
            }
        } catch (Throwable) {
            $this->fail();
        }

        $absolutePath = $disk->path($storagePath);

        $physicalSize = @filesize($absolutePath);
        if ($physicalSize === false || (int) $physicalSize !== (int) $finalPdf->size_bytes) {
            $this->fail();
        }

        $physicalSha = @hash_file('sha256', $absolutePath);
        if (! is_string($physicalSha) || ! hash_equals($recordedSha, strtolower($physicalSha))) {
            $this->fail();
        }

        return [$absolutePath, $recordedSha];
    }

    /**
     * True when the contract's CURRENT final PDF already carries a pending or
     * completed signature. Call under the shared Contract row lock for a correct
     * decision.
     */
    public function hasActiveSignature(Contract $contract): bool
    {
        if ($contract->final_pdf_file_id === null) {
            return false;
        }

        return Signature::query()
            ->where('contract_id', $contract->id)
            ->where('source_file_id', $contract->final_pdf_file_id)
            ->whereIn('status', [Signature::STATUS_PENDING, Signature::STATUS_COMPLETED])
            ->exists();
    }

    /**
     * Refuse to mutate the final PDF while it is actively signed. Must be called
     * under the shared Contract row lock, before any filesystem write.
     */
    public function assertNotActivelySigned(Contract $contract): void
    {
        if ($this->hasActiveSignature($contract)) {
            throw FinalPdfException::of(FinalPdfException::FINAL_PDF_ACTIVELY_SIGNED);
        }
    }

    private function fail(): never
    {
        throw FinalPdfException::of(FinalPdfException::FINAL_PDF_INTEGRITY_INVALID);
    }
}
