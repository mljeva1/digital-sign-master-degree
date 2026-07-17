<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Models\Certificate;
use App\Models\StoredFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only preflight lookup of a user's signer certificate for the owner UI.
 *
 * It mutates nothing and decides nothing: ContractSigningService still resolves
 * and re-validates the certificate under a lock at signing time. This exists
 * only so the owner learns BEFORE clicking that no usable certificate is
 * registered, instead of discovering it from a failure message.
 *
 * The subject/issuer CN are read from the stored public certificate. Any read or
 * parse problem degrades to "no CN" rather than surfacing an error — a missing
 * label must never be mistaken for a validity claim, and no path or OpenSSL
 * message may escape.
 */
final class SignerCertificateStatusService
{
    /**
     * Mirrors ContractSigningService::selectActiveCertificate:
     *  - 2+ candidates  => ambiguous (the server would fail closed);
     *  - exactly 1      => that certificate's own state;
     *  - 0 candidates   => the most recent record, so a deactivated/expired
     *                      certificate is reported honestly rather than as
     *                      "not registered"; still unusable either way.
     */
    public function forUser(int $userId): SignerCertificateStatus
    {
        try {
            $candidates = $this->candidates($userId);

            if ($candidates->count() > 1) {
                return SignerCertificateStatus::ambiguous();
            }

            $certificate = $candidates->first() ?? $this->mostRecent($userId);
        } catch (Throwable) {
            return SignerCertificateStatus::missing();
        }

        if ($certificate === null) {
            return SignerCertificateStatus::missing();
        }

        [$subject, $issuer] = $this->commonNames($certificate);

        return SignerCertificateStatus::fromCertificate($certificate, $subject, $issuer);
    }

    /**
     * EXACTLY the candidate query ContractSigningService::activeCertificates()
     * runs — owner type + owner id + is_active, with NO validity filter, because
     * the real selector applies none. An expired certificate that is still
     * is_active IS a candidate there, so it must be one here too; inventing a
     * stricter rule would let the UI offer a button the server then refuses.
     *
     * Two candidates are all the proof ambiguity needs, so the query stops there.
     *
     * @return Collection<int, Certificate>
     */
    private function candidates(int $userId)
    {
        return Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $userId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->limit(2)
            ->get();
    }

    private function mostRecent(int $userId): ?Certificate
    {
        return Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $userId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function commonNames(Certificate $certificate): array
    {
        try {
            $file = $certificate->file;
            if ($file === null
                || $file->purpose !== StoredFile::PURPOSE_CERTIFICATE
                || blank($file->storage_path)) {
                return [null, null];
            }

            $disk = Storage::disk((string) $file->storage_disk);
            if (! $disk->exists((string) $file->storage_path)) {
                return [null, null];
            }

            $x509 = @openssl_x509_read((string) $disk->get((string) $file->storage_path));
            if ($x509 === false) {
                $this->clearOpenSslErrors();

                return [null, null];
            }

            $parsed = @openssl_x509_parse($x509);
            $this->clearOpenSslErrors();

            if (! is_array($parsed)) {
                return [null, null];
            }

            $subject = $parsed['subject']['CN'] ?? null;
            $issuer = $parsed['issuer']['CN'] ?? null;

            return [
                is_string($subject) ? $subject : null,
                is_string($issuer) ? $issuer : null,
            ];
        } catch (Throwable) {
            $this->clearOpenSslErrors();

            return [null, null];
        }
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never surfaced.
        }
    }
}
