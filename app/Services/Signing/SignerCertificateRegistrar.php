<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Exceptions\Signing\SignerCertificateProfileException;
use App\Exceptions\Signing\StoredCertificateIntegrityException;
use App\Models\Certificate;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registers a local X.509 signer certificate for a creator-user owner.
 *
 * Scope: configuration + registration only. No CMS signing/verifying, no
 * route/controller/UI, no audit event, and only the PUBLIC certificate is
 * stored. The private key is loaded solely to prove it matches the certificate
 * and is never persisted or logged. "At most one active signer certificate per
 * user" is enforced at the application layer (no DB constraint in this phase).
 *
 * Concurrency/idempotency: the persistence transaction re-fetches the owner
 * under lockForUpdate (withTrashed) and fails closed if the owner is missing or
 * soft-deleted — the passed-in model is never trusted as proof of existence.
 * Every new attempt writes to a UNIQUE (uuid) path so no attempt can overwrite
 * another's artefact; failure cleanup deletes only the current attempt's own
 * path and reports whether it completed. A fingerprint unique-violation is
 * recovered by re-reading the winning row; any other DB error is a plain
 * persistence failure.
 *
 * NOTE: process crashes and genuine cleanup failures can still leave an orphan
 * certificate file that a later reconciliation must resolve; not every orphan
 * is prevented.
 *
 * The class is intentionally non-final to expose a narrow protected insert seam
 * for deterministic unique-conflict control-flow tests.
 */
class SignerCertificateRegistrar
{
    private const CLEANUP_COMPLETED = 'completed';

    private const CLEANUP_INCOMPLETE = 'incomplete';

    private readonly SignerCertificateProfileValidator $profileValidator;

    private readonly StoredCertificateIntegrityVerifier $integrityVerifier;

    public function __construct(
        private readonly SigningConfig $config,
        ?SignerCertificateProfileValidator $profileValidator = null,
        ?StoredCertificateIntegrityVerifier $integrityVerifier = null,
    ) {
        $this->profileValidator = $profileValidator ?? new SignerCertificateProfileValidator;
        $this->integrityVerifier = $integrityVerifier ?? new StoredCertificateIntegrityVerifier;
    }

    public function register(User $owner, string $certificateInputPath): Certificate
    {
        $this->clearOpenSslErrors();

        // Resolve every configured dependency up front. Besides preserving a
        // stable CONFIG_INVALID failure, this gives the operation its single
        // isolated, validated storage adapter before any persistence work.
        $privateKeyPem = $this->config->privateKeyPem();
        $passphrase = $this->config->passphrase();
        $rootCaPath = $this->config->rootCaPath();
        $storage = $this->config->certificateStorage();

        // 1. Load the public signer certificate from the explicit input path.
        $certificatePem = $this->readReadableFile($certificateInputPath);
        if ($certificatePem === null) {
            $this->fail(RegistrationException::CERTIFICATE_LOAD_FAILED);
        }

        $certificate = @openssl_x509_read($certificatePem);
        if ($certificate === false) {
            $this->fail(RegistrationException::CERTIFICATE_LOAD_FAILED);
        }

        // 2. Load the configured private key using the passphrase-file content.
        $privateKey = @openssl_pkey_get_private($privateKeyPem, $passphrase);
        if ($privateKey === false) {
            $this->fail(RegistrationException::PRIVATE_KEY_LOAD_FAILED);
        }

        // 3. Load the configured Root CA certificate (trust anchor).
        $rootCaPem = $this->readReadableFile($rootCaPath);
        if ($rootCaPem === null || @openssl_x509_read($rootCaPem) === false) {
            $this->fail(RegistrationException::ROOT_CA_LOAD_FAILED);
        }

        // 4. Shared signer-profile rules in the registration precedence:
        //    profile (parse, CA:FALSE, digitalSignature, validity window) → key
        //    match → S/MIME trust (no NOVERIFY). The key match precedes the
        //    trust check so a certificate that is BOTH key-mismatched AND
        //    untrusted fails as CERTIFICATE_KEY_MISMATCH. The rules live in one
        //    place; here the neutral defect is mapped to a stable registration
        //    failure code.
        try {
            $profile = $this->profileValidator->validateForRegistration($certificate, $privateKey, $rootCaPath);
        } catch (SignerCertificateProfileException $e) {
            $this->fail($this->registrationCodeForDefect($e->defect));
        }

        $fingerprint = $profile['fingerprint'];
        $parsed = $profile['parsed'];
        $validFrom = $profile['validFrom'];
        $validTo = $profile['validTo'];

        $this->clearOpenSslErrors();

        // Canonical public-certificate PEM export (never the raw input).
        $exported = '';
        if (@openssl_x509_export($certificate, $exported) !== true || $exported === '') {
            $this->fail(RegistrationException::CERTIFICATE_LOAD_FAILED);
        }

        return $this->persist((int) $owner->getKey(), $storage, $exported, $fingerprint, $parsed, $validFrom, $validTo);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function persist(
        int $ownerId,
        ValidatedCertificateStorage $storage,
        string $exportedPem,
        string $fingerprint,
        array $parsed,
        Carbon $validFrom,
        Carbon $validTo,
    ): Certificate {
        $attemptPath = null;
        $writeAttempted = false;
        $pathPreexisted = null;

        try {
            return DB::transaction(function () use (
                $ownerId,
                $storage,
                $exportedPem,
                $fingerprint,
                $parsed,
                $validFrom,
                $validTo,
                &$attemptPath,
                &$writeAttempted,
                &$pathPreexisted,
            ): Certificate {
                $owner = $this->lockOwnerOrFail($ownerId);

                $existing = $this->existingByFingerprint($fingerprint);
                if ($existing !== null) {
                    return $this->resolveExisting($owner, $existing, $storage);
                }

                $attemptPath = $this->uniqueAttemptPath($ownerId, $fingerprint);
                $this->assertOwnedAttemptPath($attemptPath, $ownerId, $fingerprint);
                $pathPreexisted = $this->attemptPathExists($storage->filesystem, $attemptPath);
                if ($pathPreexisted) {
                    throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
                }

                $physicalPem = $this->writeAndVerifyCertificateFile(
                    $storage->filesystem,
                    $attemptPath,
                    $exportedPem,
                    $fingerprint,
                    $writeAttempted,
                );

                return $this->insertCertificateRecords($owner, $storage->disk, $attemptPath, $physicalPem, $fingerprint, $parsed, $validFrom, $validTo);
            });
        } catch (Throwable $e) {
            $cleanup = $this->safeDeleteAttempt(
                $storage->filesystem,
                $attemptPath,
                $writeAttempted,
                $pathPreexisted,
            );

            if ($cleanup === self::CLEANUP_INCOMPLETE) {
                throw RegistrationException::of(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED)
                    ->markCompensationIncomplete();
            }

            try {
                if ($this->isFingerprintUniqueViolation($e)) {
                    return $this->recoverFromUniqueConflict($ownerId, $fingerprint, $storage);
                }

                $exception = $e instanceof RegistrationException
                    ? $e
                    : RegistrationException::of(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED);
            } catch (Throwable $recoveryFailure) {
                $exception = $recoveryFailure instanceof RegistrationException
                    ? $recoveryFailure
                    : RegistrationException::of(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED);
            }

            throw $exception;
        }
    }

    /**
     * Narrow protected seam: create the StoredFile + Certificate for a new
     * artefact and deactivate the owner's other actives. Overridden only in
     * tests to deterministically simulate a fingerprint unique-violation.
     *
     * @param  array<string, mixed>  $parsed
     */
    protected function insertCertificateRecords(
        User $owner,
        string $disk,
        string $attemptPath,
        string $physicalPem,
        string $fingerprint,
        array $parsed,
        Carbon $validFrom,
        Carbon $validTo,
    ): Certificate {
        $file = StoredFile::create([
            'purpose' => StoredFile::PURPOSE_CERTIFICATE,
            'storage_disk' => $disk,
            'storage_path' => $attemptPath,
            'original_filename' => 'signer-certificate.pem',
            'mime_type' => 'application/x-pem-file',
            'size_bytes' => strlen($physicalPem),
            'sha256' => hash('sha256', $physicalPem),
            'created_by_user_id' => $owner->id,
        ]);

        $this->deactivateOtherActiveCertificates((int) $owner->id, null);

        return Certificate::create([
            'owner_type' => Certificate::OWNER_TYPE_USER,
            'owner_user_id' => $owner->id,
            'owner_customer_id' => null,
            'label' => 'Signer certificate (user '.$owner->id.')',
            'subject_dn' => $this->distinguishedName($parsed['subject'] ?? []),
            'issuer_dn' => $this->distinguishedName($parsed['issuer'] ?? []),
            'serial_number' => $this->serialNumber($parsed),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'thumbprint_sha256' => $fingerprint,
            'file_id' => $file->id,
            'is_active' => true,
        ]);
    }

    /**
     * Post-lock lookup of an already-registered fingerprint. A narrow protected
     * seam so tests can deterministically simulate the "not yet visible at
     * recheck" side of a race; recoverFromUniqueConflict always uses the real
     * query below.
     */
    protected function existingByFingerprint(string $fingerprint): ?Certificate
    {
        return Certificate::query()->where('thumbprint_sha256', $fingerprint)->first();
    }

    protected function lockOwnerOrFail(int $ownerId): User
    {
        // Re-fetch under lock; never trust the caller's (possibly stale) model.
        // Remove only the soft-delete scope explicitly. The model does not use
        // it today, but this remains correct if the trait is enabled later;
        // every other global scope remains active.
        $owner = User::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereKey($ownerId)
            ->lockForUpdate()
            ->first();

        if ($owner === null || $owner->deleted_at !== null) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_OWNER_UNAVAILABLE);
        }

        return $owner;
    }

    private function resolveExisting(User $owner, Certificate $existing, ValidatedCertificateStorage $storage): Certificate
    {
        if ($existing->owner_type !== Certificate::OWNER_TYPE_USER
            || (int) $existing->owner_user_id !== (int) $owner->id) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_OWNER_CONFLICT);
        }

        // Full integrity contract BEFORE any mutation (no new cert, no
        // deactivation of others on mismatch).
        $this->assertStoredCertificateIntegrity($existing, $storage);

        $this->deactivateOtherActiveCertificates((int) $owner->id, (int) $existing->id);

        if ($existing->is_active !== true) {
            $existing->is_active = true;
            $existing->save();
        }

        return $existing->refresh();
    }

    private function recoverFromUniqueConflict(int $ownerId, string $fingerprint, ValidatedCertificateStorage $storage): Certificate
    {
        return DB::transaction(function () use ($ownerId, $fingerprint, $storage): Certificate {
            $owner = $this->lockOwnerOrFail($ownerId);

            $winner = Certificate::query()->where('thumbprint_sha256', $fingerprint)->first();
            if ($winner === null) {
                throw RegistrationException::of(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED);
            }

            return $this->resolveExisting($owner, $winner, $storage);
        });
    }

    private function assertStoredCertificateIntegrity(Certificate $existing, ValidatedCertificateStorage $storage): void
    {
        // Delegate to the shared physical-integrity verifier so registration and
        // CMS signing enforce the identical rule; map its neutral failure to the
        // registration-scoped persistence code.
        try {
            $this->integrityVerifier->verifiedPhysicalPem($existing, $storage);
        } catch (StoredCertificateIntegrityException) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED);
        }
    }

    private function registrationCodeForDefect(SignerCertificateDefect $defect): string
    {
        return match ($defect) {
            SignerCertificateDefect::ParseFailed => RegistrationException::CERTIFICATE_LOAD_FAILED,
            SignerCertificateDefect::BasicConstraintsInvalid => RegistrationException::CERTIFICATE_BASIC_CONSTRAINTS_INVALID,
            SignerCertificateDefect::IsCa => RegistrationException::CERTIFICATE_IS_CA,
            SignerCertificateDefect::KeyUsageInvalid => RegistrationException::CERTIFICATE_KEY_USAGE_INVALID,
            SignerCertificateDefect::NotYetValid => RegistrationException::CERTIFICATE_NOT_YET_VALID,
            SignerCertificateDefect::Expired => RegistrationException::CERTIFICATE_EXPIRED,
            SignerCertificateDefect::KeyMismatch => RegistrationException::CERTIFICATE_KEY_MISMATCH,
            SignerCertificateDefect::Untrusted => RegistrationException::CERTIFICATE_UNTRUSTED,
        };
    }

    private function deactivateOtherActiveCertificates(int $ownerId, ?int $exceptId): void
    {
        $query = Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $ownerId)
            ->where('is_active', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->update(['is_active' => false]);
    }

    protected function uniqueAttemptPath(int $ownerId, string $fingerprint): string
    {
        return 'signing/certificates/user-'.$ownerId.'/'.$fingerprint.'-'.Str::uuid()->toString().'.pem';
    }

    private function assertOwnedAttemptPath(string $path, int $ownerId, string $fingerprint): void
    {
        $prefix = 'signing/certificates/user-'.$ownerId.'/'.$fingerprint.'-';
        if (! str_starts_with($path, $prefix) || ! str_ends_with($path, '.pem')) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }

        $uuid = substr($path, strlen($prefix), -4);
        if (! Str::isUuid($uuid)) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }
    }

    private function attemptPathExists(Filesystem $filesystem, string $path): bool
    {
        try {
            return $filesystem->exists($path);
        } catch (Throwable) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }
    }

    private function writeAndVerifyCertificateFile(
        Filesystem $filesystem,
        string $path,
        string $pem,
        string $fingerprint,
        bool &$writeAttempted,
    ): string {
        $writeAttempted = true;

        try {
            $stored = $filesystem->put($path, $pem);
        } catch (Throwable) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }

        if ($stored !== true) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }

        try {
            $physicalPem = $filesystem->get($path); // exactly one post-write read
        } catch (Throwable) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }

        if (! is_string($physicalPem) || ! hash_equals($pem, $physicalPem)) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }

        $physicalCertificate = @openssl_x509_read($physicalPem);
        $physicalFingerprint = $physicalCertificate === false
            ? false
            : @openssl_x509_fingerprint($physicalCertificate, 'sha256');
        $this->clearOpenSslErrors();

        if (! is_string($physicalFingerprint) || strtolower($physicalFingerprint) !== $fingerprint) {
            throw RegistrationException::of(RegistrationException::CERTIFICATE_STORAGE_FAILED);
        }

        return $physicalPem;
    }

    /**
     * Delete ONLY this attempt's own path. Returns a structured completed/
     * incomplete signal (confirmed via delete() result and a follow-up
     * exists()); never exposes the path or a raw exception.
     */
    private function safeDeleteAttempt(
        Filesystem $filesystem,
        ?string $path,
        bool $writeAttempted,
        ?bool $pathPreexisted,
    ): string {
        if ($path === null || ! $writeAttempted || $pathPreexisted !== false) {
            return self::CLEANUP_COMPLETED;
        }

        try {
            $deleted = $filesystem->delete($path);
        } catch (Throwable) {
            $deleted = false;
        }

        try {
            $stillExists = $filesystem->exists($path);
        } catch (Throwable) {
            $stillExists = true;
        }

        return ($deleted === true && $stillExists === false) ? self::CLEANUP_COMPLETED : self::CLEANUP_INCOMPLETE;
    }

    /**
     * Only a genuine global-fingerprint unique violation triggers recovery.
     * Any other unique/DB error remains a plain persistence failure.
     */
    protected function isFingerprintUniqueViolation(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $message = $e->getMessage();

        // PostgreSQL: exact SQLSTATE + exact quoted constraint name.
        if ((string) $e->getCode() === '23505') {
            if (preg_match('/\bconstraint\s+"([^"]+)"/i', $message, $matches) !== 1) {
                return false;
            }

            return $matches[1] === 'certificates_thumbprint_sha256_unique';
        }

        // SQLite: trust only the raw driver message copied from PDO errorInfo.
        // Never parse Laravel's outer "(Connection: ...)" wrapper because a
        // partial match could hide an additional target or trailing content.
        $rawDriverMessage = $e->errorInfo[2] ?? null;
        if (! is_string($rawDriverMessage)) {
            return false;
        }

        $normalizedDriverMessage = preg_replace('/\s+/', ' ', trim($rawDriverMessage));

        return $normalizedDriverMessage === 'UNIQUE constraint failed: certificates.thumbprint_sha256';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function serialNumber(array $parsed): string
    {
        $serial = $parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '';

        return substr((string) $serial, 0, 255);
    }

    /**
     * @param  array<int|string, mixed>  $parts
     */
    private function distinguishedName(array $parts): string
    {
        $segments = [];

        foreach ($parts as $key => $value) {
            if (is_array($value)) {
                $value = implode('+', array_map('strval', $value));
            }

            $segments[] = $key.'='.$value;
        }

        return substr('/'.implode('/', $segments), 0, 500);
    }

    private function readReadableFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never logged.
        }
    }

    private function fail(string $code): never
    {
        $this->clearOpenSslErrors();

        throw RegistrationException::of($code);
    }
}
