<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use App\Models\User;
use App\Services\Signing\DetachedCmsSigner;
use App\Services\Signing\DetachedCmsSignRequest;
use App\Services\Signing\DetachedCmsVerificationRequest;
use App\Services\Signing\DetachedCmsVerificationResult;
use App\Services\Signing\DetachedCmsVerifier;
use App\Services\Signing\SignerCertificateOutputParser;
use App\Services\Signing\SignerCertificateRegistrar;
use App\Services\Signing\SigningClock;
use App\Services\Signing\SigningTempWorkspace;
use App\Services\Signing\SigningWorkspaceHandle;

/**
 * Real native detached CMS verification: independent signal separation, signer
 * time derived from the embedded certificate via an injectable clock, strict
 * single-signer handling, arbitrary/tampered input, and filesystem failure
 * injection. Genuine CMS artefacts are produced by the signer over ephemeral PKI.
 */
final class DetachedCmsVerifierTest extends SigningTestCase
{
    /**
     * @return array{der: string, source: string, rootCa: string, fingerprint: string, hash: string, bytes: string}
     */
    private function signed(): array
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, 'v3_signer');
        $this->configureSigning($signer['key'], 'verify-pass', $ca['pem']);
        $user = User::factory()->create();
        $certificate = app(SignerCertificateRegistrar::class)->register($user, $this->writeCertificateInput($signer['pem']));

        $bytes = "%PDF-1.4\n% verifier fixture\n%%EOF\n";
        $source = $this->writeExternal('fixture.pdf', $bytes);
        $result = app(DetachedCmsSigner::class)
            ->sign(new DetachedCmsSignRequest($source, hash('sha256', $bytes), $certificate, (int) $user->id));

        return [
            'der' => $result->cmsDer(),
            'source' => $source,
            'rootCa' => (string) config('signing.root_ca_path'),
            'fingerprint' => $result->signerFingerprint(),
            'hash' => $result->expectedSourceSha256(),
            'bytes' => $bytes,
        ];
    }

    private function request(array $s, array $overrides = []): DetachedCmsVerificationRequest
    {
        return new DetachedCmsVerificationRequest(
            sourcePath: $overrides['source'] ?? $s['source'],
            cmsDer: $overrides['der'] ?? $s['der'],
            rootCaPath: $overrides['rootCa'] ?? $s['rootCa'],
            expectedSignerFingerprint: $overrides['fingerprint'] ?? $s['fingerprint'],
            expectedSourceHash: $overrides['hash'] ?? $s['hash'],
            certificateActive: $overrides['active'] ?? true,
        );
    }

    private function fixedClock(int $timestamp): SigningClock
    {
        return new class($timestamp) implements SigningClock
        {
            public function __construct(private readonly int $ts) {}

            public function timestamp(): int
            {
                return $this->ts;
            }
        };
    }

    public function test_valid_artifact_reports_every_signal_true(): void
    {
        $s = $this->signed();

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s));

        $this->assertTrue($result->cryptographicValid);
        $this->assertTrue($result->trustValid);
        $this->assertTrue($result->signerFingerprintMatches);
        $this->assertTrue($result->sourceHashMatches);
        $this->assertTrue($result->certificateActive);
        $this->assertTrue($result->certificateTimeValid);
        $this->assertTrue($result->overall);
    }

    public function test_wrong_root_ca_keeps_crypto_valid_but_fails_trust(): void
    {
        $s = $this->signed();
        $otherCa = $this->newRootCa('Untrusting Root');
        $wrongCaPath = $this->writeExternal('wrong-ca.pem', $otherCa['pem']);

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s, ['rootCa' => $wrongCaPath]));

        $this->assertTrue($result->cryptographicValid);
        $this->assertFalse($result->trustValid);
        $this->assertFalse($result->overall);
    }

    public function test_content_tamper_breaks_the_cryptographic_signal_specifically(): void
    {
        $s = $this->signed();
        $tampered = $s['bytes'];
        $tampered[10] = $tampered[10] === 'X' ? 'Y' : 'X';
        $tamperedPath = $this->writeExternal('tampered.pdf', $tampered);

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s, ['source' => $tamperedPath]));

        $this->assertFalse($result->cryptographicValid);
        $this->assertFalse($result->sourceHashMatches);
        $this->assertFalse($result->overall);
    }

    public function test_cms_tamper_breaks_the_cryptographic_signal_specifically(): void
    {
        $s = $this->signed();
        $der = $s['der'];
        $i = strlen($der) - 1;
        $der[$i] = chr(ord($der[$i]) ^ 0xFF);

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s, ['der' => $der]));

        $this->assertFalse($result->cryptographicValid);
        $this->assertTrue($result->sourceHashMatches); // the content itself is untouched
        $this->assertFalse($result->overall);
    }

    public function test_fingerprint_mismatch_is_isolated(): void
    {
        $s = $this->signed();

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s, ['fingerprint' => str_repeat('a', 64)]));

        $this->assertTrue($result->cryptographicValid);
        $this->assertTrue($result->trustValid);
        $this->assertFalse($result->signerFingerprintMatches);
        $this->assertFalse($result->overall);
    }

    public function test_source_hash_mismatch_is_isolated(): void
    {
        $s = $this->signed();

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s, ['hash' => str_repeat('0', 64)]));

        $this->assertTrue($result->cryptographicValid);
        $this->assertFalse($result->sourceHashMatches);
        $this->assertFalse($result->overall);
    }

    public function test_inactive_certificate_signal_propagates(): void
    {
        $s = $this->signed();

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s, ['active' => false]));

        $this->assertFalse($result->certificateActive);
        $this->assertFalse($result->overall);
    }

    public function test_certificate_time_validity_is_derived_from_the_cms_certificate_and_clock(): void
    {
        $s = $this->signed();

        // A clock far in the future makes the embedded certificate expired even
        // though crypto + trust remain valid — proving time comes from the CMS
        // certificate, never a caller boolean.
        $future = app(DetachedCmsVerifier::class, ['clock' => $this->fixedClock(strtotime('+100 years'))]);
        $result = $future->verify($this->request($s));

        $this->assertTrue($result->cryptographicValid);
        $this->assertTrue($result->trustValid);
        $this->assertFalse($result->certificateTimeValid);
        $this->assertFalse($result->overall);

        // A clock far in the past makes it not-yet-valid.
        $past = app(DetachedCmsVerifier::class, ['clock' => $this->fixedClock(strtotime('-100 years'))]);
        $this->assertFalse($past->verify($this->request($s))->certificateTimeValid);
    }

    public function test_arbitrary_der_like_input_does_not_pass(): void
    {
        $s = $this->signed();
        $bogusDer = "\x30\x82".random_bytes(200); // DER SEQUENCE prefix, not a real CMS

        $result = app(DetachedCmsVerifier::class)->verify($this->request($s, ['der' => $bogusDer]));

        $this->assertFalse($result->cryptographicValid);
        $this->assertFalse($result->signerFingerprintMatches);
        $this->assertFalse($result->overall);
    }

    public function test_pem_or_non_der_cms_input_is_rejected(): void
    {
        $s = $this->signed();

        foreach (["-----BEGIN CMS-----\nabc\n-----END CMS-----\n", 'not der', ''] as $bogus) {
            try {
                app(DetachedCmsVerifier::class)->verify($this->request($s, ['der' => $bogus]));
                $this->fail('Expected CMS_OUTPUT_INVALID.');
            } catch (DetachedCmsException $e) {
                $this->assertSame(DetachedCmsException::CMS_OUTPUT_INVALID, $e->errorCode());
            }
        }
    }

    public function test_crypto_valid_without_a_single_signer_certificate_fails_closed(): void
    {
        $s = $this->signed();
        // Stub the parser to report "no signer certificate" while crypto is valid.
        $emptyParser = new class extends SignerCertificateOutputParser
        {
            public function parse(string $content): ?array
            {
                return null;
            }
        };
        $verifier = new DetachedCmsVerifier(signerParser: $emptyParser);

        try {
            $verifier->verify($this->request($s));
            $this->fail('Expected CMS_SIGNER_CERTIFICATE_INVALID.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_SIGNER_CERTIFICATE_INVALID, $e->errorCode());
        }
    }

    public function test_missing_source_and_root_ca_are_operational_failures(): void
    {
        $s = $this->signed();

        try {
            app(DetachedCmsVerifier::class)->verify($this->request($s, ['source' => $this->tempDir.'/missing.pdf']));
            $this->fail('Expected CMS_SOURCE_INVALID.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_SOURCE_INVALID, $e->errorCode());
        }

        try {
            app(DetachedCmsVerifier::class)->verify($this->request($s, ['rootCa' => $this->tempDir.'/missing-ca.pem']));
            $this->fail('Expected CMS_CONFIG_INVALID.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_CONFIG_INVALID, $e->errorCode());
        }
    }

    public function test_temp_workspace_creation_failure_is_normalized(): void
    {
        $s = $this->signed();
        $verifier = new class extends DetachedCmsVerifier
        {
            protected function createWorkspace(): SigningWorkspaceHandle
            {
                throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
            }
        };

        try {
            $verifier->verify($this->request($s));
            $this->fail('Expected CMS_TEMP_WORKSPACE_FAILED.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED, $e->errorCode());
        }
    }

    public function test_cleanup_failure_signals_incomplete_without_leaking(): void
    {
        $s = $this->signed();
        $verifier = new class extends DetachedCmsVerifier
        {
            /** @var list<SigningWorkspaceHandle> */
            public array $created = [];

            protected function createWorkspace(): SigningWorkspaceHandle
            {
                $handle = (new SigningTempWorkspace)->create();
                $this->created[] = $handle;

                return $handle;
            }

            protected function removeWorkspace(SigningWorkspaceHandle $handle): bool
            {
                return false; // simulate an unconfirmed cleanup
            }
        };

        try {
            $verifier->verify($this->request($s));
            $this->fail('Expected CMS_TEMP_CLEANUP_INCOMPLETE.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_TEMP_CLEANUP_INCOMPLETE, $e->errorCode());
            $this->assertTrue($e->compensationIncomplete());
        } finally {
            foreach ($verifier->created as $handle) {
                $dir = $handle->path();
                foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
                    @chmod($f, 0600);
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }

    public function test_result_object_is_pure_booleans(): void
    {
        $s = $this->signed();
        $result = app(DetachedCmsVerifier::class)->verify($this->request($s));

        $this->assertInstanceOf(DetachedCmsVerificationResult::class, $result);
        foreach ($result->toArray() as $value) {
            $this->assertIsBool($value);
        }
    }
}
