<?php

declare(strict_types=1);

namespace App\Services\Signing;

/**
 * Read-only, display-safe summary of the persisted signature state of a
 * contract's CURRENT final PDF.
 *
 * Every verification signal is reported independently — a UI must never
 * collapse them into a single green mark:
 *  - pdfIntegrityValid: the physical final PDF still matches its own
 *    StoredFile record and the contract binding (shared FinalPdfIntegrityVerifier);
 *  - cmsIntegrityValid: the physical .p7s bytes still match the CMS StoredFile
 *    record (size + SHA-256);
 *  - cryptographicValid / trustValid / certificateTimeValid / certificateActive /
 *    signerFingerprintMatches / sourceHashMatches: the independent M10
 *    DetachedCmsVerifier signals;
 *  - overall: true only when every signal above is true.
 *
 * verificationUnavailable=true means the verification could NOT be executed
 * (operational failure, e.g. signing trust anchor not configured) — it is a
 * fail-closed state, never a claim of validity. Carries only a signed-at
 * timestamp and the public certificate SHA-256 fingerprint — never a path,
 * DER byte, token, DN or serial.
 */
final readonly class ContractSignatureStatus
{
    private function __construct(
        public bool $signaturePresent,
        public bool $verificationUnavailable,
        public bool $pdfIntegrityValid,
        public bool $cmsIntegrityValid,
        public bool $cryptographicValid,
        public bool $trustValid,
        public bool $certificateTimeValid,
        public bool $certificateActive,
        public bool $signerFingerprintMatches,
        public bool $sourceHashMatches,
        public bool $overall,
        public ?string $signedAtIso,
        public ?string $certificateFingerprint,
    ) {}

    /** The current final PDF has no completed signature. */
    public static function absent(): self
    {
        return new self(
            signaturePresent: false,
            verificationUnavailable: false,
            pdfIntegrityValid: false,
            cmsIntegrityValid: false,
            cryptographicValid: false,
            trustValid: false,
            certificateTimeValid: false,
            certificateActive: false,
            signerFingerprintMatches: false,
            sourceHashMatches: false,
            overall: false,
            signedAtIso: null,
            certificateFingerprint: null,
        );
    }

    /** A signature exists but verification could not run — fail closed. */
    public static function unavailable(?string $signedAtIso): self
    {
        return new self(
            signaturePresent: true,
            verificationUnavailable: true,
            pdfIntegrityValid: false,
            cmsIntegrityValid: false,
            cryptographicValid: false,
            trustValid: false,
            certificateTimeValid: false,
            certificateActive: false,
            signerFingerprintMatches: false,
            sourceHashMatches: false,
            overall: false,
            signedAtIso: $signedAtIso,
            certificateFingerprint: null,
        );
    }

    /** The final PDF failed its own physical integrity check — fail closed. */
    public static function pdfIntegrityFailed(?string $signedAtIso): self
    {
        return new self(
            signaturePresent: true,
            verificationUnavailable: false,
            pdfIntegrityValid: false,
            cmsIntegrityValid: false,
            cryptographicValid: false,
            trustValid: false,
            certificateTimeValid: false,
            certificateActive: false,
            signerFingerprintMatches: false,
            sourceHashMatches: false,
            overall: false,
            signedAtIso: $signedAtIso,
            certificateFingerprint: null,
        );
    }

    /** The .p7s bytes no longer match the CMS StoredFile record — fail closed. */
    public static function cmsIntegrityFailed(?string $signedAtIso): self
    {
        return new self(
            signaturePresent: true,
            verificationUnavailable: false,
            pdfIntegrityValid: true,
            cmsIntegrityValid: false,
            cryptographicValid: false,
            trustValid: false,
            certificateTimeValid: false,
            certificateActive: false,
            signerFingerprintMatches: false,
            sourceHashMatches: false,
            overall: false,
            signedAtIso: $signedAtIso,
            certificateFingerprint: null,
        );
    }

    /** Physical integrity held and the detached CMS verification actually ran. */
    public static function verified(
        DetachedCmsVerificationResult $result,
        ?string $signedAtIso,
        ?string $certificateFingerprint,
    ): self {
        return new self(
            signaturePresent: true,
            verificationUnavailable: false,
            pdfIntegrityValid: true,
            cmsIntegrityValid: true,
            cryptographicValid: $result->cryptographicValid,
            trustValid: $result->trustValid,
            certificateTimeValid: $result->certificateTimeValid,
            certificateActive: $result->certificateActive,
            signerFingerprintMatches: $result->signerFingerprintMatches,
            sourceHashMatches: $result->sourceHashMatches,
            overall: $result->overall,
            signedAtIso: $signedAtIso,
            certificateFingerprint: $certificateFingerprint,
        );
    }
}
