<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Models\Certificate;

/**
 * Read-only, display-safe summary of a user's signer certificate, used only to
 * tell the owner up-front whether signing is available.
 *
 * It is a PREFLIGHT HINT, never an authorization decision:
 * ContractSigningService re-resolves and re-validates the certificate under a
 * lock and remains the sole authority. `usable` therefore means "the UI may
 * offer the button", not "signing will succeed".
 *
 * Carries only already-public metadata (label, CN, issuer CN, validity window,
 * SHA-256 fingerprint). It never exposes a storage path, PEM/DER bytes, the
 * private key, the passphrase, an internal file id, or an OpenSSL message.
 */
final readonly class SignerCertificateStatus
{
    public const STATE_MISSING = 'missing';

    public const STATE_ACTIVE = 'active';

    public const STATE_EXPIRED = 'expired';

    public const STATE_INACTIVE = 'inactive';

    /**
     * Two or more certificates match ContractSigningService's candidate query,
     * so the server cannot select one and signing fails closed with
     * SIGNER_CERTIFICATE_AMBIGUOUS. The UI must say so instead of offering a
     * button that cannot work.
     */
    public const STATE_AMBIGUOUS = 'ambiguous';

    private function __construct(
        public string $state,
        public ?string $label,
        public ?string $subjectCommonName,
        public ?string $issuerCommonName,
        public ?string $validFrom,
        public ?string $validTo,
        public ?string $fingerprint,
    ) {}

    /** No certificate row exists for this user at all. */
    public static function missing(): self
    {
        return new self(self::STATE_MISSING, null, null, null, null, null, null);
    }

    /**
     * More than one candidate certificate. Deliberately carries NO metadata:
     * showing one arbitrary candidate's label/CN/fingerprint would present a
     * selection the server never made and cannot make.
     */
    public static function ambiguous(): self
    {
        return new self(self::STATE_AMBIGUOUS, null, null, null, null, null, null);
    }

    /**
     * Summarise a persisted certificate. The state is derived from the record's
     * own flags/window — an expired or deactivated certificate is never
     * presented as active.
     */
    public static function fromCertificate(
        Certificate $certificate,
        ?string $subjectCommonName,
        ?string $issuerCommonName,
    ): self {
        $state = match (true) {
            $certificate->is_active !== true => self::STATE_INACTIVE,
            $certificate->valid_to === null || $certificate->valid_to->isPast() => self::STATE_EXPIRED,
            $certificate->valid_from !== null && $certificate->valid_from->isFuture() => self::STATE_INACTIVE,
            default => self::STATE_ACTIVE,
        };

        return new self(
            $state,
            $certificate->label !== null ? (string) $certificate->label : null,
            $subjectCommonName,
            $issuerCommonName,
            $certificate->valid_from?->toIso8601String(),
            $certificate->valid_to?->toIso8601String(),
            strtolower((string) $certificate->thumbprint_sha256) ?: null,
        );
    }

    /** True only when the UI may offer the signing button. */
    public function usable(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function label(): string
    {
        return match ($this->state) {
            self::STATE_ACTIVE => 'Aktivan',
            self::STATE_EXPIRED => 'Istekao',
            self::STATE_INACTIVE => 'Opozvan ili deaktiviran',
            self::STATE_AMBIGUOUS => 'Više aktivnih certifikata',
            default => 'Nije registriran',
        };
    }
}
