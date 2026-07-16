<?php

declare(strict_types=1);

namespace App\Services\Signing;

use JsonSerializable;

/**
 * Result of a successful M11 contract signing operation.
 *
 * Carries ONLY safe identifiers and domain data a future controller needs. It
 * deliberately exposes no CMS DER bytes, no private-key/passphrase data, no
 * absolute local path, and no full model graph — only stable ids, the covered
 * source SHA-256, the signer certificate fingerprint, and the signed time.
 *
 * $idempotentExisting is true when the operation returned a pre-existing
 * completed signature (no new CMS artefact, StoredFile, Signature, or audit
 * event was created).
 */
final readonly class ContractSigningResult implements JsonSerializable
{
    public function __construct(
        public int $signatureId,
        public int $contractId,
        public int $sourceFileId,
        public int $cmsFileId,
        public int $certificateId,
        public string $sourceSha256,
        public string $signerFingerprint,
        public string $signedAt,
        public bool $idempotentExisting,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'signature_id' => $this->signatureId,
            'contract_id' => $this->contractId,
            'source_file_id' => $this->sourceFileId,
            'cms_file_id' => $this->cmsFileId,
            'certificate_id' => $this->certificateId,
            'source_sha256' => $this->sourceSha256,
            'signer_fingerprint' => $this->signerFingerprint,
            'signed_at' => $this->signedAt,
            'idempotent_existing' => $this->idempotentExisting,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
