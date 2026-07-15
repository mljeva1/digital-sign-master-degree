<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use App\Services\Signing\SignerCertificateOutputParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Directly exercises the strict single-signer certificate-output parser with
 * crafted inputs: exactly one certificate is required; zero (whitespace) yields
 * null, and multiple / extra content / wrong block / garbage fail closed with a
 * stable code — the first block is never taken blindly.
 */
final class SignerCertificateOutputParserTest extends TestCase
{
    private string $tempDir;

    private string $cnf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m10-parser-'.bin2hex(random_bytes(8));
        if (! mkdir($this->tempDir, 0700, true) && ! is_dir($this->tempDir)) {
            throw new RuntimeException('temp dir');
        }
        $this->cnf = $this->tempDir.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($this->cnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=parser\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * @return array{pem: string, fingerprint: string}
     */
    private function certificate(string $cn = 'Signer'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $this->cnf]);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['config' => $this->cnf, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['config' => $this->cnf, 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);

        return ['pem' => $pem, 'fingerprint' => strtolower((string) openssl_x509_fingerprint($cert, 'sha256'))];
    }

    private function assertInvalid(string $content): void
    {
        try {
            (new SignerCertificateOutputParser)->parse($content);
            $this->fail('Expected CMS_SIGNER_CERTIFICATE_INVALID.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_SIGNER_CERTIFICATE_INVALID, $e->errorCode());
        }
    }

    public function test_exactly_one_certificate_is_parsed(): void
    {
        $cert = $this->certificate();

        $result = (new SignerCertificateOutputParser)->parse("\n".$cert['pem']."\n");

        $this->assertSame($cert['fingerprint'], $result['fingerprint']);
        $this->assertIsInt($result['validFrom']);
        $this->assertIsInt($result['validTo']);
        $this->assertLessThan($result['validTo'], $result['validFrom']);
    }

    public function test_empty_or_whitespace_is_null(): void
    {
        $this->assertNull((new SignerCertificateOutputParser)->parse(''));
        $this->assertNull((new SignerCertificateOutputParser)->parse("   \n\t  \r\n"));
    }

    public function test_multiple_certificates_fail_closed(): void
    {
        $a = $this->certificate('A');
        $b = $this->certificate('B');
        $this->assertInvalid($a['pem'].$b['pem']);
    }

    public function test_certificate_with_extra_trailing_content_fails_closed(): void
    {
        $cert = $this->certificate();
        $this->assertInvalid($cert['pem']."\nnot-a-certificate trailing bytes\n");
    }

    public function test_certificate_with_leading_content_fails_closed(): void
    {
        $cert = $this->certificate();
        $this->assertInvalid("leading junk before the block\n".$cert['pem']);
    }

    public function test_non_certificate_pem_block_fails_closed(): void
    {
        $this->assertInvalid("-----BEGIN PUBLIC KEY-----\nMFwwDQ==\n-----END PUBLIC KEY-----\n");
    }

    public function test_pure_garbage_fails_closed(): void
    {
        $this->assertInvalid('this is not a certificate at all');
    }
}
