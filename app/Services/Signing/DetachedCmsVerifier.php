<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use Throwable;

/**
 * Native detached CMS (PKCS#7) verifier over frozen source bytes and a DER CMS
 * artefact, using ONLY the PHP ext-openssl API (no OpenSSL CLI).
 *
 * It reports each trust/validity signal independently by issuing two native
 * verifications, each with a documented, single purpose:
 *
 *  1. crypto-only (OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY,
 *     empty ca_info): proves the signature cryptographically matches the exact
 *     content bytes and yields the embedded signer certificate output.
 *     NOVERIFY isolates the cryptographic signal ONLY — never a trust result.
 *  2. full trust (OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED, ca_info=[rootCa]):
 *     proves the signature is cryptographically valid AND chains to the
 *     configured local Root CA.
 *
 * The signer-certificate output is parsed STRICTLY: it must be exactly one PEM
 * CERTIFICATE block (whitespace aside). Zero (when crypto is valid), multiple, or
 * any extra content fails closed with CMS_SIGNER_CERTIFICATE_INVALID — the first
 * block is never taken blindly. The fingerprint and time-validity signals are
 * computed from THAT single embedded certificate; time validity uses the injected
 * clock, never a caller-supplied boolean.
 *
 * A failed verification is a RESULT (overall=false), not an exception; exceptions
 * are reserved for operational/structural failures (invalid CMS input, malformed
 * signer output, temp workspace, cleanup). The raw OpenSSL error queue never
 * leaves this service.
 *
 * Confirmed PHP 8.3 detached-DER verify form:
 *   openssl_cms_verify($contentFilename, $flags, $signerCertsOut, [$rootCa],
 *       null, null, null, $cmsSignatureFilename, OPENSSL_ENCODING_DER)
 */
class DetachedCmsVerifier
{
    private readonly SigningTempWorkspace $workspace;

    private readonly SigningClock $clock;

    private readonly SignerCertificateOutputParser $signerParser;

    public function __construct(
        ?SigningTempWorkspace $workspace = null,
        ?SigningClock $clock = null,
        ?SignerCertificateOutputParser $signerParser = null,
    ) {
        $this->workspace = $workspace ?? new SigningTempWorkspace;
        $this->clock = $clock ?? new SystemSigningClock;
        $this->signerParser = $signerParser ?? new SignerCertificateOutputParser;
    }

    public function verify(DetachedCmsVerificationRequest $request): DetachedCmsVerificationResult
    {
        $this->clearOpenSslErrors();

        // The CMS input must be non-empty binary DER — never PEM or arbitrary
        // content substituted for a real signature.
        if (! $this->looksLikeDer($request->cmsDer())) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_OUTPUT_INVALID);
        }

        if (! is_file($request->sourcePath()) || ! is_readable($request->sourcePath())) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_SOURCE_INVALID);
        }

        if (! is_file($request->rootCaPath()) || ! is_readable($request->rootCaPath())) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_CONFIG_INVALID);
        }

        $handle = $this->createWorkspace();
        $cleanedUp = null;

        try {
            $result = $this->runVerification($request, $handle->path());
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
                : DetachedCmsException::of(DetachedCmsException::CMS_CRYPTO_VERIFY_FAILED);

            if ($cleanedUp !== true) {
                $exception->markCompensationIncomplete();
            }

            throw $exception;
        }
    }

    private function runVerification(DetachedCmsVerificationRequest $request, string $dir): DetachedCmsVerificationResult
    {
        $cms = $request->cmsDer();
        $cmsFile = $dir.DIRECTORY_SEPARATOR.'signature.p7s';
        if (file_put_contents($cmsFile, $cms) !== strlen($cms)) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
        }
        @chmod($cmsFile, 0600);

        $flags = OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED;
        $signerCertsFile = $dir.DIRECTORY_SEPARATOR.'signer-certs.pem';

        // (1) Cryptographic-only signal; also emits the signer certificate output.
        $this->clearOpenSslErrors();
        $cryptographicValid = @openssl_cms_verify(
            $request->sourcePath(),
            $flags | OPENSSL_CMS_NOVERIFY,
            $signerCertsFile,
            [],
            null,
            null,
            null,
            $cmsFile,
            OPENSSL_ENCODING_DER,
        ) === true;
        $this->clearOpenSslErrors();

        // (2) Full trust signal against the configured Root CA (no NOVERIFY).
        $trustValid = @openssl_cms_verify(
            $request->sourcePath(),
            $flags,
            $dir.DIRECTORY_SEPARATOR.'trust-certs.pem',
            [$request->rootCaPath()],
            null,
            null,
            null,
            $cmsFile,
            OPENSSL_ENCODING_DER,
        ) === true;
        $this->clearOpenSslErrors();

        // Strictly extract the SINGLE embedded signer certificate.
        $signer = $this->signerParser->parse($this->readSignerOutput($signerCertsFile));

        if ($signer === null && $cryptographicValid) {
            // A cryptographically valid signature must expose exactly one signer
            // certificate; its absence is a structural anomaly.
            throw DetachedCmsException::of(DetachedCmsException::CMS_SIGNER_CERTIFICATE_INVALID);
        }

        $signerFingerprintMatches = $signer !== null
            && hash_equals(strtolower($request->expectedSignerFingerprint()), $signer['fingerprint']);

        $certificateTimeValid = $signer !== null && $this->isWithinWindow($signer['validFrom'], $signer['validTo']);

        $actualHash = @hash_file('sha256', $request->sourcePath());
        $sourceHashMatches = is_string($actualHash)
            && hash_equals(strtolower($request->expectedSourceHash()), strtolower($actualHash));

        return new DetachedCmsVerificationResult(
            cryptographicValid: $cryptographicValid,
            trustValid: $trustValid,
            certificateTimeValid: $certificateTimeValid,
            certificateActive: $request->certificateActive(),
            signerFingerprintMatches: $signerFingerprintMatches,
            sourceHashMatches: $sourceHashMatches,
        );
    }

    private function readSignerOutput(string $certsFile): string
    {
        if (! is_file($certsFile)) {
            return '';
        }

        $pem = @file_get_contents($certsFile);

        return is_string($pem) ? $pem : '';
    }

    private function isWithinWindow(int $validFrom, int $validTo): bool
    {
        $now = $this->clock->timestamp();

        return $now >= $validFrom && $now < $validTo;
    }

    private function looksLikeDer(string $bytes): bool
    {
        return $bytes !== ''
            && ! str_contains($bytes, '-----BEGIN')
            && ord($bytes[0]) === 0x30; // DER SEQUENCE tag
    }

    protected function createWorkspace(): SigningWorkspaceHandle
    {
        return $this->workspace->create();
    }

    protected function removeWorkspace(SigningWorkspaceHandle $handle): bool
    {
        return $this->workspace->discard($handle);
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never surfaced.
        }
    }
}
