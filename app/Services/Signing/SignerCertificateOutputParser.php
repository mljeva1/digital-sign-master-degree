<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\DetachedCmsException;

/**
 * Strictly parses the signer-certificate output emitted by openssl_cms_verify.
 *
 * The content must consist of NOTHING but PEM `CERTIFICATE` blocks separated by
 * whitespace, and must contain EXACTLY ONE certificate. The result is that
 * single certificate's SHA-256 fingerprint and validity window; the first block
 * is never taken blindly.
 *
 * Scope note: CMS itself can carry multiple certificates (e.g. a full chain with
 * intermediates), and OpenSSL can both produce and verify such structures — this
 * parser does NOT claim otherwise. The M10 signing profile deliberately issues
 * the signer certificate DIRECTLY from the local Root CA (a two-link
 * leaf → Root CA chain with no intermediate CAs), so a correct artefact embeds
 * exactly one certificate. Requiring exactly one block is therefore a fail-closed
 * profile constraint, not a limitation of CMS/OpenSSL. If the project profile
 * ever introduces intermediate CAs, this rule must be revisited to accept the
 * signer leaf plus its intermediates; that is intentionally out of scope here.
 *
 * Return / failure contract:
 *  - whitespace-only / empty content → null (no signer certificate present);
 *  - exactly one valid CERTIFICATE block → parsed data;
 *  - two or more blocks, any extra non-whitespace content, a non-CERTIFICATE PEM
 *    block, or an unparseable block → CMS_SIGNER_CERTIFICATE_INVALID.
 *
 * Non-final only so the defensive "crypto-valid yet no signer certificate"
 * branch of the verifier can be exercised with a stub in tests.
 */
class SignerCertificateOutputParser
{
    private const BLOCK_PATTERN = '/-----BEGIN CERTIFICATE-----\r?\n[A-Za-z0-9+\/=\r\n]+?-----END CERTIFICATE-----/';

    /**
     * @return array{fingerprint: string, validFrom: int, validTo: int}|null
     */
    public function parse(string $content): ?array
    {
        if (trim($content) === '') {
            return null;
        }

        // The entire content must be CERTIFICATE blocks plus whitespace.
        $remainder = preg_replace(self::BLOCK_PATTERN, '', $content);
        if ($remainder === null || trim($remainder) !== '') {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SIGNER_CERTIFICATE_INVALID);
        }

        $count = preg_match_all(self::BLOCK_PATTERN, $content, $matches);
        if ($count !== 1) {
            // 0 is impossible here (non-empty content with empty remainder implies
            // at least one block); so this is strictly the "multiple" case.
            throw DetachedCmsException::of(DetachedCmsException::CMS_SIGNER_CERTIFICATE_INVALID);
        }

        $certificate = @openssl_x509_read($matches[0][0]);
        if ($certificate === false) {
            $this->clearOpenSslErrors();

            throw DetachedCmsException::of(DetachedCmsException::CMS_SIGNER_CERTIFICATE_INVALID);
        }

        $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');
        $parsed = @openssl_x509_parse($certificate);
        $this->clearOpenSslErrors();

        $from = is_array($parsed) ? ($parsed['validFrom_time_t'] ?? null) : null;
        $to = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        if (! is_string($fingerprint) || ! is_int($from) || ! is_int($to)) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SIGNER_CERTIFICATE_INVALID);
        }

        return ['fingerprint' => strtolower($fingerprint), 'validFrom' => $from, 'validTo' => $to];
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never surfaced.
        }
    }
}
