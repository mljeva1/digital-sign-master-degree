<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use App\Exceptions\Signing\SignerCertificateProfileException;
use App\Exceptions\Signing\StoredCertificateIntegrityException;
use App\Models\Certificate;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Throwable;

/**
 * Native detached CMS (PKCS#7) signer over a frozen source artefact, producing
 * DER output using ONLY the PHP ext-openssl API (openssl_cms_sign) — no OpenSSL
 * CLI and no shell string concatenation.
 *
 * Reusable internal service. NO Signature/StoredFile persistence, no audit
 * event, no final-PDF mutation, no route/UI. The original source bytes are never
 * modified.
 *
 * Source-byte integrity contract:
 *  - the source path is canonicalized and opened once as a regular file;
 *  - from that single stream a private, read-only temp SNAPSHOT is written;
 *  - the snapshot's real SHA-256 must equal the mandatory expected SHA-256 of the
 *    bound final-PDF StoredFile record (strictly validated, hash_equals);
 *  - signing, crypto-only verify and trust verify all use ONLY that snapshot;
 *  - immediately before returning, the ORIGINAL is re-hashed and must still equal
 *    the expected hash (a deterministic source-race between original and snapshot
 *    fails closed) — this is an independent proof, not merely before === after.
 *
 * Certificate contract:
 *  - the Certificate + StoredFile state is re-fetched from the DB before the
 *    private key is used and re-confirmed immediately before a successful return;
 *  - owner, active, stored-file physical integrity, CA:FALSE, digitalSignature,
 *    key match, and S/MIME trust are all re-validated;
 *  - relation/storage exceptions are normalized to neutral stable codes.
 *
 * No private-key/passphrase path, passphrase, temp path, PEM, CMS bytes, or raw
 * OpenSSL error is ever exposed. The class is non-final to expose narrow
 * protected seams for deterministic failure-injection tests.
 */
class DetachedCmsSigner
{
    private readonly StoredCertificateIntegrityVerifier $integrityVerifier;

    private readonly SignerCertificateProfileValidator $profileValidator;

    private readonly DetachedCmsVerifier $verifier;

    private readonly SigningTempWorkspace $workspace;

    public function __construct(
        private readonly SigningConfig $config,
        ?StoredCertificateIntegrityVerifier $integrityVerifier = null,
        ?SignerCertificateProfileValidator $profileValidator = null,
        ?DetachedCmsVerifier $verifier = null,
        ?SigningTempWorkspace $workspace = null,
    ) {
        $this->integrityVerifier = $integrityVerifier ?? new StoredCertificateIntegrityVerifier;
        $this->profileValidator = $profileValidator ?? new SignerCertificateProfileValidator;
        $this->verifier = $verifier ?? new DetachedCmsVerifier;
        $this->workspace = $workspace ?? new SigningTempWorkspace;
    }

    public function sign(DetachedCmsSignRequest $request): DetachedCmsSignatureResult
    {
        $this->clearOpenSslErrors();

        // 1. Resolve secure configuration (paths/passphrase/disk validated).
        try {
            $privateKeyPem = $this->config->privateKeyPem();
            $passphrase = $this->config->passphrase();
            $rootCaPath = $this->config->rootCaPath();
            $storage = $this->config->certificateStorage();
        } catch (Throwable) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CONFIG_INVALID);
        }

        // 2. The expected source hash is mandatory. Contract (b): a case-INSENSITIVE
        //    64-char hex SHA-256 is accepted and then canonicalized to lowercase;
        //    every later comparison uses that canonical lowercase form.
        $rawExpected = $request->expectedSourceSha256();
        if (preg_match('/^[0-9A-Fa-f]{64}$/', $rawExpected) !== 1) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_HASH_MISMATCH);
        }
        $expectedSourceHash = strtolower($rawExpected);

        // 3. Canonicalize + validate the source as a regular, readable, non-empty file.
        $canonicalSource = $this->canonicalSourcePath($request->sourcePath());

        // 4. All native work happens inside a fail-safe canonical temp workspace.
        $handle = $this->createWorkspace();
        $cleanedUp = null;

        try {
            $result = $this->signWithinWorkspace(
                $request,
                $handle->path(),
                $canonicalSource,
                $expectedSourceHash,
                $privateKeyPem,
                $passphrase,
                $rootCaPath,
                $storage,
            );

            $cleanedUp = $this->removeWorkspace($handle);
            if ($cleanedUp !== true) {
                throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_CLEANUP_INCOMPLETE)
                    ->markCompensationIncomplete();
            }

            return $result;
        } catch (Throwable $e) {
            if ($cleanedUp === null) {
                $cleanedUp = $this->removeWorkspace($handle);
            }
            $this->clearOpenSslErrors();

            $exception = $e instanceof DetachedCmsException
                ? $e
                : DetachedCmsException::of(DetachedCmsException::CMS_SIGN_FAILED);

            if ($cleanedUp !== true) {
                $exception->markCompensationIncomplete();
            }

            throw $exception;
        }
    }

    private function signWithinWorkspace(
        DetachedCmsSignRequest $request,
        string $dir,
        string $canonicalSource,
        string $expectedSourceHash,
        string $privateKeyPem,
        string $passphrase,
        string $rootCaPath,
        ValidatedCertificateStorage $storage,
    ): DetachedCmsSignatureResult {
        // (a) Private snapshot from a single opened source stream. The write
        //     stream is closed and the file is set READ-ONLY before it is first
        //     hashed, and stays read-only through sign + both verifications.
        $snapshot = $this->createSnapshot($canonicalSource, $dir);

        // (b) Hash the actual (now read-only) snapshot bytes; it MUST equal the
        //     expected hash. This is the initial snapshot hash.
        $initialSnapshotHash = @hash_file('sha256', $snapshot);
        if (! is_string($initialSnapshotHash) || ! hash_equals($expectedSourceHash, strtolower($initialSnapshotHash))) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_HASH_MISMATCH);
        }
        $initialSnapshotHash = strtolower($initialSnapshotHash);

        // Test seam: allows a deterministic attempt to modify the snapshot
        // between the initial hash and the OpenSSL operations (TOCTOU probe).
        $this->onSnapshotCreated($canonicalSource, $snapshot);

        // (c) Re-fetch the current certificate state from the DB before key use.
        //     The request holds only the identifier; this fresh record (with its
        //     StoredFile eager-loaded) is the sole authoritative certificate state.
        $ownerId = $request->expectedOwnerUserId();
        $certificate = $this->refetchCertificate($request->certificateId());

        // (pre-key) ALL checks that do not need a private-key handle: owner,
        // active, StoredFile physical integrity, and the certificate
        // time/profile/trust rules. Nothing below touches the private key.
        [$physicalPem, $fingerprint] = $this->validateCertificateBeforePrivateKey($certificate, $ownerId, $storage, $rootCaPath);

        // All pre-key checks have now succeeded. Only here does the private-key
        // operation begin.
        $this->beforePrivateKeyOperation($certificate);

        // (d) Load the configured private key (the sole private-key loader seam).
        $privateKey = $this->loadPrivateKey($privateKeyPem, $passphrase);
        if ($privateKey === false) {
            $this->clearOpenSslErrors();

            throw DetachedCmsException::of(DetachedCmsException::CMS_PRIVATE_KEY_INVALID);
        }

        // (post-key) The only check that technically needs the private key.
        $this->assertPrivateKeyMatchesCertificate($physicalPem, $privateKey);

        // (f) Sign the SNAPSHOT.
        $signerCert = @openssl_x509_read($physicalPem);
        if ($signerCert === false) {
            $this->clearOpenSslErrors();

            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        $cmsOut = $dir.DIRECTORY_SEPARATOR.'signature.p7s';
        $this->clearOpenSslErrors();
        $signed = $this->cmsSign($snapshot, $cmsOut, $signerCert, $privateKey);
        $this->clearOpenSslErrors();
        if ($signed !== true) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SIGN_FAILED);
        }

        $der = @file_get_contents($cmsOut);
        if (! is_string($der) || $der === '' || str_contains($der, '-----BEGIN') || ord($der[0]) !== 0x30) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_OUTPUT_INVALID);
        }

        // (g) Immediate detached verification over the SAME snapshot.
        $verification = $this->verifier->verify(new DetachedCmsVerificationRequest(
            sourcePath: $snapshot,
            cmsDer: $der,
            rootCaPath: $rootCaPath,
            expectedSignerFingerprint: $fingerprint,
            expectedSourceHash: $expectedSourceHash,
            certificateActive: $certificate->is_active === true,
        ));

        if (! $verification->cryptographicValid) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CRYPTO_VERIFY_FAILED);
        }
        if (! $verification->trustValid) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_TRUST_VERIFY_FAILED);
        }
        if (! $verification->signerFingerprintMatches) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SIGNER_FINGERPRINT_MISMATCH);
        }
        if (! $verification->sourceHashMatches) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_HASH_MISMATCH);
        }
        if (! $verification->isValid()) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CRYPTO_VERIFY_FAILED);
        }

        // (g') After BOTH verification passes, recompute the snapshot hash and
        //     require expected === initial === final. This closes the snapshot
        //     TOCTOU: any modification between the initial hash and the OpenSSL
        //     operations is detected here and fails closed.
        $finalSnapshotHash = @hash_file('sha256', $snapshot);
        if (! is_string($finalSnapshotHash)
            || ! hash_equals($expectedSourceHash, strtolower($finalSnapshotHash))
            || ! hash_equals($initialSnapshotHash, strtolower($finalSnapshotHash))) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_HASH_MISMATCH);
        }

        // Test seam: allows a deterministic DB change before the final re-confirm.
        $this->beforeFinalReconfirm();

        // (h) Re-confirm the ORIGINAL against the expected hash (source-race guard).
        $originalHashAfter = @hash_file('sha256', $canonicalSource);
        if (! is_string($originalHashAfter) || ! hash_equals($expectedSourceHash, strtolower($originalHashAfter))) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_HASH_MISMATCH);
        }

        // (i) Re-confirm active + integrity + fingerprint on fresh DB state.
        $this->reconfirmActiveIntegrity($certificate->getKey(), $ownerId, $storage, $fingerprint);

        return new DetachedCmsSignatureResult(
            cmsDer: $der,
            signedSnapshotSha256: $initialSnapshotHash,
            expectedSourceSha256: $expectedSourceHash,
            originalSourceSha256AfterSigning: strtolower($originalHashAfter),
            cmsSha256: hash('sha256', $der),
            signerFingerprint: $fingerprint,
            sourceHashAlgorithm: 'sha256',
            signatureProfile: 'detached-cms',
            cmsEncoding: 'DER',
            detached: true,
            verification: $verification,
        );
    }

    private function canonicalSourcePath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_INVALID);
        }

        $size = @filesize($real);
        if ($size === false || $size <= 0) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_INVALID);
        }

        return $real;
    }

    /**
     * Copy the source into a private snapshot using a single opened source
     * stream, close the write stream, then set the snapshot READ-ONLY (0400)
     * before it is ever hashed — so the same artefact is hashed, signed and
     * verified and cannot be rewritten in between. A failed read-only chmod fails
     * closed. (0400 restricts the file; it is NOT filesystem-immutable — cleanup
     * clears the read-only bit immediately before unlink.)
     */
    protected function createSnapshot(string $canonicalSource, string $dir): string
    {
        $snapshot = $dir.DIRECTORY_SEPARATOR.'source-snapshot.bin';

        $in = @fopen($canonicalSource, 'rb');
        if ($in === false) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_INVALID);
        }

        try {
            $out = @fopen($snapshot, 'wb');
            if ($out === false) {
                throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
            }

            try {
                $copied = @stream_copy_to_stream($in, $out);
            } finally {
                @fclose($out);
            }
        } finally {
            @fclose($in);
        }

        if ($copied === false) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
        }

        // Read-only from here through hash + sign + verify; a chmod failure fails
        // closed. Cleanup restores the write bit right before unlink.
        if (@chmod($snapshot, 0400) !== true) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
        }

        return $snapshot;
    }

    private function refetchCertificate(int|string $id): Certificate
    {
        try {
            // Eager-load the StoredFile relation in the SAME refetch so the
            // authoritative certificate + file are complete before any
            // private-key operation (no post-key lazy load in the integrity
            // verifier). Also used by the final reconfirm.
            $fresh = Certificate::query()->with('file')->whereKey($id)->first();
        } catch (Throwable) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        if ($fresh === null) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        return $fresh;
    }

    /**
     * @return array{0: string, 1: string} physical PEM, lowercase fingerprint
     */
    private function validateCertificateBeforePrivateKey(
        Certificate $certificate,
        int $expectedOwnerId,
        ValidatedCertificateStorage $storage,
        string $rootCaPath,
    ): array {
        if ($certificate->owner_type !== Certificate::OWNER_TYPE_USER
            || (int) $certificate->owner_user_id !== $expectedOwnerId) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        if ($certificate->is_active !== true) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        try {
            $physicalPem = $this->integrityVerifier->verifiedPhysicalPem($certificate, $storage);
        } catch (StoredCertificateIntegrityException) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        // Profile/time/CA/EKU/trust — everything except the private-key match.
        try {
            $profile = $this->profileValidator->validateBeforePrivateKey($physicalPem, $rootCaPath);
        } catch (SignerCertificateProfileException $e) {
            throw DetachedCmsException::of($this->cmsCodeForDefect($e->defect));
        }

        if (! hash_equals(strtolower((string) $certificate->thumbprint_sha256), $profile['fingerprint'])) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        return [$physicalPem, $profile['fingerprint']];
    }

    private function assertPrivateKeyMatchesCertificate(string $physicalPem, OpenSSLAsymmetricKey $privateKey): void
    {
        try {
            $this->profileValidator->validatePrivateKeyMatch($physicalPem, $privateKey);
        } catch (SignerCertificateProfileException $e) {
            throw DetachedCmsException::of($this->cmsCodeForDefect($e->defect));
        }
    }

    /**
     * Immediately-before-return re-confirmation of the relevant active/integrity
     * state on freshly re-read DB rows.
     */
    private function reconfirmActiveIntegrity(int|string $id, int $expectedOwnerId, ValidatedCertificateStorage $storage, string $expectedFingerprint): void
    {
        $fresh = $this->refetchCertificate($id);

        if ($fresh->owner_type !== Certificate::OWNER_TYPE_USER
            || (int) $fresh->owner_user_id !== $expectedOwnerId
            || $fresh->is_active !== true
            || ! hash_equals($expectedFingerprint, strtolower((string) $fresh->thumbprint_sha256))) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        try {
            $this->integrityVerifier->verifiedPhysicalPem($fresh, $storage);
        } catch (StoredCertificateIntegrityException) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }
    }

    private function cmsCodeForDefect(SignerCertificateDefect $defect): string
    {
        return match ($defect) {
            SignerCertificateDefect::KeyMismatch => DetachedCmsException::CMS_CERTIFICATE_KEY_MISMATCH,
            SignerCertificateDefect::Untrusted => DetachedCmsException::CMS_CERTIFICATE_UNTRUSTED,
            default => DetachedCmsException::CMS_CERTIFICATE_INVALID,
        };
    }

    protected function cmsSign(string $inputFile, string $outputFile, OpenSSLCertificate $certificate, OpenSSLAsymmetricKey $privateKey): bool
    {
        return @openssl_cms_sign(
            $inputFile,
            $outputFile,
            $certificate,
            $privateKey,
            null,
            OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
            OPENSSL_ENCODING_DER,
        );
    }

    protected function createWorkspace(): SigningWorkspaceHandle
    {
        return $this->workspace->create();
    }

    protected function removeWorkspace(SigningWorkspaceHandle $handle): bool
    {
        return $this->workspace->discard($handle);
    }

    /**
     * Test seam: runs right after the snapshot is created (read-only) and its
     * initial hash is verified, receiving both the original source path and the
     * snapshot path so a TOCTOU attempt can be exercised deterministically.
     */
    protected function onSnapshotCreated(string $canonicalSource, string $snapshotPath): void {}

    /** Test seam: runs right before the final DB active/integrity re-confirmation. */
    protected function beforeFinalReconfirm(): void {}

    /**
     * Test seam: runs after ALL pre-key checks have succeeded and immediately
     * before the first private-key operation, so a test can prove the relation is
     * already loaded and comes from the fresh DB record.
     */
    protected function beforePrivateKeyOperation(Certificate $certificate): void {}

    /**
     * The sole private-key loader. It is the only place that calls
     * openssl_pkey_get_private(), so a test can override it to prove the loader is
     * NOT reached on any pre-key failure and is reached exactly once otherwise.
     */
    protected function loadPrivateKey(string $privateKeyPem, string $passphrase): OpenSSLAsymmetricKey|false
    {
        return @openssl_pkey_get_private($privateKeyPem, $passphrase);
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never surfaced.
        }
    }
}
