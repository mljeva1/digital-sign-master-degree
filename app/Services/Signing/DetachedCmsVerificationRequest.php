<?php

declare(strict_types=1);

namespace App\Services\Signing;

use JsonSerializable;

/**
 * Input for a detached CMS verification.
 *
 * The verifier is reusable and self-contained: it is given the physical content
 * path to verify against, the DER CMS bytes (in memory), the Root CA trust
 * anchor path, the expected signer fingerprint / source hash, and the current
 * certificate ACTIVE fact from the caller (who has re-fetched it from the DB).
 * Certificate TIME validity is NOT taken from the caller — the verifier derives
 * it from the single signer certificate embedded in the CMS, using its injected
 * clock.
 *
 * Sensitive fields (content path, CMS bytes, Root CA path) are held in a private
 * sidecar (see ProtectsSensitiveState), never as instance properties, so they
 * cannot leak through var_export/print_r/var_dump/serialize; only explicit
 * accessors expose them.
 */
final class DetachedCmsVerificationRequest implements JsonSerializable
{
    use ProtectsSensitiveState;

    public function __construct(
        string $sourcePath,
        string $cmsDer,
        string $rootCaPath,
        private readonly string $expectedSignerFingerprint,
        private readonly string $expectedSourceHash,
        private readonly bool $certificateActive,
    ) {
        $this->storeSensitive([
            'sourcePath' => $sourcePath,
            'cmsDer' => $cmsDer,
            'rootCaPath' => $rootCaPath,
        ]);
    }

    public function sourcePath(): string
    {
        return (string) $this->sensitive('sourcePath');
    }

    public function cmsDer(): string
    {
        return (string) $this->sensitive('cmsDer');
    }

    public function rootCaPath(): string
    {
        return (string) $this->sensitive('rootCaPath');
    }

    public function expectedSignerFingerprint(): string
    {
        return $this->expectedSignerFingerprint;
    }

    public function expectedSourceHash(): string
    {
        return $this->expectedSourceHash;
    }

    public function certificateActive(): bool
    {
        return $this->certificateActive;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'certificate_active' => $this->certificateActive,
            'source_path' => '[redacted]',
            'cms_der' => '[redacted]',
            'root_ca_path' => '[redacted]',
            'expected_signer_fingerprint' => '[redacted]',
            'expected_source_hash' => '[redacted]',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
