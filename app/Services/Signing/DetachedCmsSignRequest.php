<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use App\Models\Certificate;
use JsonSerializable;

/**
 * Input for a detached CMS signing operation.
 *
 * Carries only references the caller already holds: the physical source path
 * (the frozen final-PDF artefact on disk), the mandatory expected SHA-256 of the
 * bound final-PDF StoredFile record, the signer certificate IDENTIFIER, and the
 * user id the certificate must belong to. The private key, passphrase, and Root
 * CA are resolved by the service through the secure SigningConfig layer and are
 * never passed here.
 *
 * The full Eloquent Certificate model (and any loaded relations such as `file`)
 * is deliberately NOT retained: the constructor accepts a Certificate purely to
 * read its primary key in the model's real key type, and stores only that
 * identifier. DetachedCmsSigner re-fetches the current Certificate (with its
 * StoredFile) from the database as the sole authoritative certificate state, so
 * a stale/loaded object graph can never leak through var_export/print_r/etc.
 *
 * The sensitive source path is held in a private sidecar (ProtectsSensitiveState),
 * never as an instance property; only the explicit accessor exposes it.
 */
final class DetachedCmsSignRequest implements JsonSerializable
{
    use ProtectsSensitiveState;

    private readonly int|string $certificateId;

    public function __construct(
        string $sourcePath,
        private readonly string $expectedSourceSha256,
        Certificate $certificate,
        private readonly int $expectedOwnerUserId,
    ) {
        // Retain only the identifier in its real key type — never the model graph.
        // An unsaved model or an invalid/empty key is rejected with the existing
        // neutral CMS contract BEFORE the typed property is assigned, so a raw
        // TypeError / Eloquent error can never surface.
        $this->certificateId = $this->requireSavedCertificateKey($certificate);
        $this->storeSensitive(['sourcePath' => $sourcePath]);
    }

    /**
     * Validate the certificate's primary key against the model's real key
     * configuration, using the RAW (uncast) key attribute — never the cast
     * getKey() value, because an integer-key model silently casts invalid raw
     * values (e.g. 'abc', '  ', '12.5') to a bogus integer such as 0. Fails closed
     * with the existing neutral CMS contract for every invalid case.
     *
     * M10 supports the concrete integer-key Certificate model only. Any other
     * key type — including one produced by a runtime setKeyType() mutation — is
     * rejected here as CMS_CERTIFICATE_INVALID, so an arbitrary non-integer
     * identifier can never pass normalization and later reach the database
     * (e.g. as a PostgreSQL "invalid input syntax for integer" error). Widening
     * to a generic string-key model is deliberately NOT introduced.
     */
    private function requireSavedCertificateKey(Certificate $certificate): int|string
    {
        if ($certificate->exists !== true) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        $keyName = $certificate->getKeyName();
        // The raw, uncast attribute as it actually sits on the model.
        $raw = $certificate->getAttributes()[$keyName] ?? null;

        return match ($certificate->getKeyType()) {
            'int', 'integer' => $this->normalizePositiveIntKey($raw),
            default => throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID),
        };
    }

    private function normalizePositiveIntKey(mixed $raw): int
    {
        // A genuine positive integer.
        if (is_int($raw)) {
            if ($raw > 0) {
                return $raw;
            }

            throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
        }

        // A raw unsigned decimal string with no sign, no leading zero, and no
        // other characters, that round-trips (guards overflow/truncation).
        if (is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw) === 1) {
            $value = (int) $raw;
            if ($value > 0 && (string) $value === $raw) {
                return $value;
            }
        }

        throw DetachedCmsException::of(DetachedCmsException::CMS_CERTIFICATE_INVALID);
    }

    public function sourcePath(): string
    {
        return (string) $this->sensitive('sourcePath');
    }

    public function expectedSourceSha256(): string
    {
        return $this->expectedSourceSha256;
    }

    public function certificateId(): int|string
    {
        return $this->certificateId;
    }

    public function expectedOwnerUserId(): int
    {
        return $this->expectedOwnerUserId;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'expected_owner_user_id' => $this->expectedOwnerUserId,
            'certificate_id' => $this->certificateId,
            'source_path' => '[redacted]',
            'expected_source_sha256' => '[redacted]',
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
