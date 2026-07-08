<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\StoredFile;
use App\Services\Audit\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class FinalPdfGenerator
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PublicVerificationQrCode $qrCode
    ) {}

    public function generate(
        Contract $contract,
        int $createdByUserId,
        string $reason = 'manual'
    ): StoredFile {
        $snapshot = $this->verifiedSnapshot($contract);
        $generatedAt = now();
        $verificationUrl = filled($contract->public_verification_token)
            ? route('public.contracts.verify.show', $contract->public_verification_token)
            : null;
        $qrCodeDataUri = $verificationUrl
            ? $this->qrCode->dataUri($verificationUrl)
            : null;
        $pdfContent = Pdf::loadView('contracts.pdf.final', [
            'contract' => $contract,
            'snapshot' => $snapshot,
            'generatedAt' => $generatedAt,
            'verificationUrl' => $verificationUrl,
            'qrCodeDataUri' => $qrCodeDataUri,
        ])
            ->setPaper('a4')
            ->setOption('enable_php', true)
            ->output();

        $path = "contracts/{$contract->id}/final-contract.pdf";
        $sha256 = hash('sha256', $pdfContent);

        abort_unless(
            Storage::disk(StoredFile::DISK_LOCAL)->put($path, $pdfContent),
            500,
            'Finalni PDF nije moguće spremiti.'
        );

        return DB::transaction(function () use (
            $contract,
            $createdByUserId,
            $generatedAt,
            $path,
            $pdfContent,
            $sha256,
            $reason
        ): StoredFile {
            $storedFile = $contract->finalPdfFile ?? new StoredFile;
            $storedFile->purpose = StoredFile::PURPOSE_FINAL_PDF;
            $storedFile->storage_disk = StoredFile::DISK_LOCAL;
            $storedFile->storage_path = $path;
            $storedFile->original_filename = "contract-{$contract->id}-final.pdf";
            $storedFile->mime_type = 'application/pdf';
            $storedFile->size_bytes = strlen($pdfContent);
            $storedFile->sha256 = $sha256;
            $storedFile->created_by_user_id = $createdByUserId;
            $storedFile->save();

            $contract->final_pdf_file_id = $storedFile->id;
            $contract->final_pdf_sha256 = $sha256;
            $contract->save();

            $this->auditLogger->record('contract.final_pdf_generated', $contract, [
                'file_id' => $storedFile->id,
                'purpose' => $storedFile->purpose,
                'final_pdf_sha256' => $sha256,
                'finalized_snapshot_sha256' => $contract->finalized_snapshot_sha256,
                'generated_at' => $generatedAt->toIso8601String(),
                'status' => $contract->status,
                'generation_reason' => $reason,
            ]);

            return $storedFile;
        });
    }

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
