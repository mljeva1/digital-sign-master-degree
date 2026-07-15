<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\StoredCertificateIntegrityException;
use App\Models\Certificate;
use App\Models\StoredFile;
use Throwable;

/**
 * Single source of truth for the physical integrity of the stored public
 * certificate behind a Certificate record. Used by BOTH signer-certificate
 * registration (idempotent resolution) and CMS signing (a signing precondition)
 * so the rule is never duplicated in two inconsistent places.
 *
 * Verifies, then returns the EXACT physical PEM bytes:
 *  - the linked StoredFile exists, has purpose=certificate and the expected
 *    private disk;
 *  - the physical file exists on that disk;
 *  - its byte length and SHA-256 match the catalog (read exactly once);
 *  - it parses as an X.509 certificate;
 *  - its physical SHA-256 fingerprint equals the recorded thumbprint.
 *
 * Any mismatch throws a neutral, secret-free StoredCertificateIntegrityException
 * (never a path or raw OpenSSL error).
 */
final class StoredCertificateIntegrityVerifier
{
    public function verifiedPhysicalPem(Certificate $certificate, ValidatedCertificateStorage $storage): string
    {
        // Relation load / adapter calls may throw provider-specific exceptions;
        // every such failure is normalized to the neutral integrity failure,
        // never leaking a raw path or provider message.
        try {
            $file = $certificate->file;
        } catch (Throwable) {
            throw StoredCertificateIntegrityException::create();
        }

        if ($file === null
            || $file->purpose !== StoredFile::PURPOSE_CERTIFICATE
            || (string) $file->storage_disk !== $storage->disk) {
            throw StoredCertificateIntegrityException::create();
        }

        $path = (string) $file->storage_path;

        try {
            if (! $storage->filesystem->exists($path)) {
                throw StoredCertificateIntegrityException::create();
            }

            $bytes = $storage->filesystem->get($path); // read exactly once
        } catch (StoredCertificateIntegrityException $e) {
            throw $e;
        } catch (Throwable) {
            throw StoredCertificateIntegrityException::create();
        }

        if (! is_string($bytes)) {
            throw StoredCertificateIntegrityException::create();
        }

        if (strlen($bytes) !== (int) $file->size_bytes
            || ! hash_equals(strtolower((string) $file->sha256), hash('sha256', $bytes))) {
            throw StoredCertificateIntegrityException::create();
        }

        $physical = @openssl_x509_read($bytes);
        if ($physical === false) {
            $this->clearOpenSslErrors();
            throw StoredCertificateIntegrityException::create();
        }

        $physicalFingerprint = @openssl_x509_fingerprint($physical, 'sha256');
        $this->clearOpenSslErrors();

        if (! is_string($physicalFingerprint)
            || strtolower($physicalFingerprint) !== strtolower((string) $certificate->thumbprint_sha256)) {
            throw StoredCertificateIntegrityException::create();
        }

        return $bytes;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never surfaced.
        }
    }
}
