<?php

declare(strict_types=1);

namespace App\Services\Signing;

use JsonSerializable;

/**
 * Result of a successful detached CMS signing operation.
 *
 * Carries the DER CMS bytes plus the metadata a future persistence phase needs.
 * The CMS DER is NOT an instance property — it is held in a private sidecar
 * (see ProtectsSensitiveState) and reachable only through the explicit cmsDer()
 * accessor, so it cannot leak through var_export(), print_r(), var_dump(),
 * serialize(), json_encode(), jsonSerialize(), toArray(), or __debugInfo().
 * No source/root/key/certificate/passphrase path is carried at all.
 *
 * Hash semantics (all SHA-256, lowercase hex):
 *  - signedSnapshotSha256: the hash of the exact immutable temp snapshot that was
 *    signed and verified — the bytes cryptographically covered;
 *  - expectedSourceSha256: the mandatory expected hash of the bound final-PDF
 *    StoredFile record (equal to the signed snapshot hash);
 *  - originalSourceSha256AfterSigning: the ORIGINAL source re-hashed immediately
 *    before returning (equal to the expected hash) — an independent second proof,
 *    not merely before === after.
 */
final class DetachedCmsSignatureResult implements JsonSerializable
{
    use ProtectsSensitiveState;

    public function __construct(
        string $cmsDer,
        private readonly string $signedSnapshotSha256,
        private readonly string $expectedSourceSha256,
        private readonly string $originalSourceSha256AfterSigning,
        private readonly string $cmsSha256,
        private readonly string $signerFingerprint,
        private readonly string $sourceHashAlgorithm,
        private readonly string $signatureProfile,
        private readonly string $cmsEncoding,
        private readonly bool $detached,
        private readonly DetachedCmsVerificationResult $verification,
    ) {
        $this->storeSensitive(['cmsDer' => $cmsDer]);
    }

    /**
     * The DER CMS bytes — the ONLY way to reach the artefact. Held in the private
     * sidecar, never an instance property, never serialized.
     */
    public function cmsDer(): string
    {
        return (string) $this->sensitive('cmsDer');
    }

    public function signedSnapshotSha256(): string
    {
        return $this->signedSnapshotSha256;
    }

    public function expectedSourceSha256(): string
    {
        return $this->expectedSourceSha256;
    }

    public function originalSourceSha256AfterSigning(): string
    {
        return $this->originalSourceSha256AfterSigning;
    }

    public function cmsSha256(): string
    {
        return $this->cmsSha256;
    }

    public function signerFingerprint(): string
    {
        return $this->signerFingerprint;
    }

    public function sourceHashAlgorithm(): string
    {
        return $this->sourceHashAlgorithm;
    }

    public function signatureProfile(): string
    {
        return $this->signatureProfile;
    }

    public function cmsEncoding(): string
    {
        return $this->cmsEncoding;
    }

    public function detached(): bool
    {
        return $this->detached;
    }

    public function verification(): DetachedCmsVerificationResult
    {
        return $this->verification;
    }

    /**
     * Safe metadata view: hashes/fingerprint/profile and the verification signals.
     * Deliberately excludes the CMS DER bytes and any path.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'signed_snapshot_sha256' => $this->signedSnapshotSha256,
            'expected_source_sha256' => $this->expectedSourceSha256,
            'original_source_sha256_after_signing' => $this->originalSourceSha256AfterSigning,
            'cms_sha256' => $this->cmsSha256,
            'signer_fingerprint' => $this->signerFingerprint,
            'source_hash_algorithm' => $this->sourceHashAlgorithm,
            'signature_profile' => $this->signatureProfile,
            'cms_encoding' => $this->cmsEncoding,
            'detached' => $this->detached,
            'cms_bytes' => '[redacted]',
            'verification' => $this->verification->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
