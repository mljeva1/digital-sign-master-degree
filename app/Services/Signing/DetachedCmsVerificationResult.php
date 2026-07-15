<?php

declare(strict_types=1);

namespace App\Services\Signing;

/**
 * Normalized, independently-reported outcome of a detached CMS verification.
 *
 * Each boolean is a distinct proof:
 *  - cryptographicValid: the CMS signature matches the exact content bytes
 *    (verified with OPENSSL_CMS_NOVERIFY, i.e. no trust chain — NOVERIFY is used
 *    ONLY to isolate this signal, never reported as the trust result);
 *  - trustValid: a full verification (crypto + chain to the configured Root CA,
 *    NO NOVERIFY) succeeded;
 *  - certificateTimeValid: the time-validity of the SINGLE certificate actually
 *    extracted from the CMS signer output, computed against the verifier's
 *    INJECTED clock. It is NOT a caller-supplied boolean and NOT merely the
 *    registered DB certificate's window;
 *  - certificateActive: the registered certificate is active;
 *  - signerFingerprintMatches: the CMS-embedded signer certificate fingerprint
 *    equals the registered certificate thumbprint;
 *  - sourceHashMatches: the SHA-256 of the verified content equals the expected
 *    signed-source hash.
 *
 * `overall` is true only when every signal is true. This object carries no path,
 * PEM, CMS bytes, or raw error and is pure enough to unit-test in isolation.
 */
final readonly class DetachedCmsVerificationResult
{
    public bool $overall;

    public function __construct(
        public bool $cryptographicValid,
        public bool $trustValid,
        public bool $certificateTimeValid,
        public bool $certificateActive,
        public bool $signerFingerprintMatches,
        public bool $sourceHashMatches,
    ) {
        $this->overall = $cryptographicValid
            && $trustValid
            && $certificateTimeValid
            && $certificateActive
            && $signerFingerprintMatches
            && $sourceHashMatches;
    }

    public function isValid(): bool
    {
        return $this->overall;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'cryptographic_valid' => $this->cryptographicValid,
            'trust_valid' => $this->trustValid,
            'certificate_time_valid' => $this->certificateTimeValid,
            'certificate_active' => $this->certificateActive,
            'signer_fingerprint_matches' => $this->signerFingerprintMatches,
            'source_hash_matches' => $this->sourceHashMatches,
            'overall' => $this->overall,
        ];
    }
}
