<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\SignerCertificateProfileException;
use Illuminate\Support\Carbon;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Single source of truth for the X.509 SIGNER PROFILE security rules, shared by
 * signer-certificate registration and CMS signing so the rules are never
 * duplicated inconsistently.
 *
 * The rules are exposed as small named primitives so each caller can compose the
 * exact ordering its security contract requires WITHOUT duplicating any rule:
 *
 *  validateProfile() — everything that needs neither the private key nor the
 *  trust anchor, in order: parse + canonical lowercase SHA-256 fingerprint;
 *  explicit basicConstraints CA:FALSE (no implicit end-entity); keyUsage
 *  digitalSignature; within the validity window.
 *
 *  assertTrusted() — X509_PURPOSE_SMIME_SIGN against the Root CA (NO
 *  OPENSSL_CMS_NOVERIFY, strict === true).
 *
 *  validatePrivateKeyMatch() — the ONLY check that needs the private key: the
 *  private key matches the signer certificate.
 *
 * Two composed contracts for the two workflows, which require the OPPOSITE
 * ordering between the trust check and the private-key match:
 *
 *  validateBeforePrivateKey() — the CMS signer's pre-key contract: every
 *  non-key rule (profile THEN trust) must pass before the signer loads the
 *  private key, so trust is enforced here, before the post-key match the signer
 *  performs separately via validatePrivateKeyMatch().
 *
 *  validateForRegistration() — the registrar's original inline precedence:
 *  profile → private-key match → trust. A certificate that is BOTH
 *  key-mismatched AND untrusted must fail as KeyMismatch, so the key match is
 *  asserted BEFORE the trust check.
 *
 * On any failure it drains the OpenSSL error queue and throws a neutral
 * SignerCertificateProfileException carrying a SignerCertificateDefect; it never
 * surfaces a path, PEM, or raw OpenSSL error. The profile phase returns the
 * parsed certificate data, the fingerprint, and the validity window.
 *
 * @phpstan-type ProfileResult array{parsed: array<string, mixed>, fingerprint: string, validFrom: Carbon, validTo: Carbon}
 */
final class SignerCertificateProfileValidator
{
    /**
     * Registration contract: profile rules, THEN private-key match, THEN trust.
     * The key match precedes the trust check so a certificate that is both
     * key-mismatched and untrusted fails as KeyMismatch (the original inline
     * registrar precedence).
     *
     * @return array{parsed: array<string, mixed>, fingerprint: string, validFrom: Carbon, validTo: Carbon}
     */
    public function validateForRegistration(OpenSSLCertificate|string $certificate, OpenSSLAsymmetricKey $privateKey, string $rootCaPath): array
    {
        $result = $this->validateProfile($certificate);
        $this->validatePrivateKeyMatch($certificate, $privateKey);
        $this->assertTrusted($certificate, $rootCaPath);

        return $result;
    }

    /**
     * CMS signer pre-key contract: all non-key rules (profile THEN trust). The
     * signer performs the private-key match separately, after this passes and
     * only then loads the private key.
     *
     * @return array{parsed: array<string, mixed>, fingerprint: string, validFrom: Carbon, validTo: Carbon}
     */
    public function validateBeforePrivateKey(OpenSSLCertificate|string $certificate, string $rootCaPath): array
    {
        $result = $this->validateProfile($certificate);
        $this->assertTrusted($certificate, $rootCaPath);

        return $result;
    }

    /**
     * The only rule that needs the private-key handle.
     */
    public function validatePrivateKeyMatch(OpenSSLCertificate|string $certificate, OpenSSLAsymmetricKey $privateKey): void
    {
        if (@openssl_x509_check_private_key($certificate, $privateKey) !== true) {
            $this->fail(SignerCertificateDefect::KeyMismatch);
        }

        $this->clearOpenSslErrors();
    }

    /**
     * Signer-profile rules that need neither the private key nor the trust
     * anchor: parse + fingerprint, explicit CA:FALSE, digitalSignature, and the
     * validity window.
     *
     * @return array{parsed: array<string, mixed>, fingerprint: string, validFrom: Carbon, validTo: Carbon}
     */
    private function validateProfile(OpenSSLCertificate|string $certificate): array
    {
        $parsed = @openssl_x509_parse($certificate);
        $fingerprintRaw = @openssl_x509_fingerprint($certificate, 'sha256');
        if (! is_array($parsed) || ! is_string($fingerprintRaw) || preg_match('/^[0-9a-f]{64}$/i', $fingerprintRaw) !== 1) {
            $this->fail(SignerCertificateDefect::ParseFailed);
        }
        $fingerprint = strtolower($fingerprintRaw);

        $this->assertExplicitEndEntity($parsed);

        if (! $this->hasDigitalSignatureUsage($parsed)) {
            $this->fail(SignerCertificateDefect::KeyUsageInvalid);
        }

        [$validFrom, $validTo] = $this->validityWindow($parsed);
        $now = Carbon::now();
        if ($now->lessThan($validFrom)) {
            $this->fail(SignerCertificateDefect::NotYetValid);
        }
        if ($now->greaterThanOrEqualTo($validTo)) {
            $this->fail(SignerCertificateDefect::Expired);
        }

        $this->clearOpenSslErrors();

        return ['parsed' => $parsed, 'fingerprint' => $fingerprint, 'validFrom' => $validFrom, 'validTo' => $validTo];
    }

    /**
     * S/MIME-signing trust against the Root CA (strict, no NOVERIFY).
     */
    private function assertTrusted(OpenSSLCertificate|string $certificate, string $rootCaPath): void
    {
        if (@openssl_x509_checkpurpose($certificate, X509_PURPOSE_SMIME_SIGN, [$rootCaPath]) !== true) {
            $this->fail(SignerCertificateDefect::Untrusted);
        }

        $this->clearOpenSslErrors();
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function assertExplicitEndEntity(array $parsed): void
    {
        $constraints = $parsed['extensions']['basicConstraints'] ?? null;
        if (! is_string($constraints)
            || preg_match('/^\s*CA\s*:\s*(TRUE|FALSE)\s*$/i', $constraints, $matches) !== 1) {
            $this->fail(SignerCertificateDefect::BasicConstraintsInvalid);
        }

        if (strtoupper($matches[1]) === 'TRUE') {
            $this->fail(SignerCertificateDefect::IsCa);
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function hasDigitalSignatureUsage(array $parsed): bool
    {
        return stripos((string) ($parsed['extensions']['keyUsage'] ?? ''), 'Digital Signature') !== false;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{0: Carbon, 1: Carbon}
     */
    private function validityWindow(array $parsed): array
    {
        $from = $parsed['validFrom_time_t'] ?? null;
        $to = $parsed['validTo_time_t'] ?? null;

        if (! is_int($from) || ! is_int($to)) {
            $this->fail(SignerCertificateDefect::ParseFailed);
        }

        return [Carbon::createFromTimestampUTC($from), Carbon::createFromTimestampUTC($to)];
    }

    private function fail(SignerCertificateDefect $defect): never
    {
        $this->clearOpenSslErrors();

        throw SignerCertificateProfileException::of($defect);
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never surfaced.
        }
    }
}
