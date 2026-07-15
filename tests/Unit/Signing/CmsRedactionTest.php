<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Models\Certificate;
use App\Models\StoredFile;
use App\Services\Signing\DetachedCmsSignatureResult;
use App\Services\Signing\DetachedCmsSignRequest;
use App\Services\Signing\DetachedCmsVerificationRequest;
use App\Services\Signing\DetachedCmsVerificationResult;
use PHPUnit\Framework\TestCase;

/**
 * Sensitive values (CMS DER bytes, local filesystem paths, and any loaded
 * Certificate/StoredFile object graph) must never leak through json_encode(),
 * jsonSerialize(), toArray(), print_r(), var_dump(), or a DIRECT var_export() of
 * the object. serialize() is refused with a neutral exception; unserialize() and
 * clone() fail closed. Accessors still return the real values/identifier.
 */
final class CmsRedactionTest extends TestCase
{
    private const SECRET_DER = "\x30\x82SECRET-CMS-DER-BYTES";

    private const SECRET_SOURCE_PATH = '/private/keys/final-contract.pdf';

    private const SECRET_ROOT_CA_PATH = '/private/keys/root-ca.pem';

    private function signatureResult(): DetachedCmsSignatureResult
    {
        return new DetachedCmsSignatureResult(
            cmsDer: self::SECRET_DER,
            signedSnapshotSha256: str_repeat('a', 64),
            expectedSourceSha256: str_repeat('a', 64),
            originalSourceSha256AfterSigning: str_repeat('a', 64),
            cmsSha256: str_repeat('b', 64),
            signerFingerprint: str_repeat('c', 64),
            sourceHashAlgorithm: 'sha256',
            signatureProfile: 'detached-cms',
            cmsEncoding: 'DER',
            detached: true,
            verification: new DetachedCmsVerificationResult(true, true, true, true, true, true),
        );
    }

    /**
     * @param  list<string>  $secrets
     */
    private function assertNoLeakAcrossSurfaces(object $object, array $secrets): void
    {
        ob_start();
        var_dump($object);
        $varDump = (string) ob_get_clean();

        $surfaces = [
            'json_encode' => (string) json_encode($object),
            'print_r' => print_r($object, true),
            'var_export' => var_export($object, true), // DIRECT var_export of the object
            'var_dump' => $varDump,
        ];

        foreach (['jsonSerialize', 'toArray', '__debugInfo'] as $method) {
            if (method_exists($object, $method)) {
                $surfaces[$method] = print_r($object->{$method}(), true);
            }
        }

        foreach ($surfaces as $name => $rendered) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $rendered, "{$secret} leaked via {$name}");
            }
        }
    }

    /**
     * @param  list<string>  $secrets
     */
    private function assertSerializationRefused(object $object, array $secrets): void
    {
        try {
            serialize($object);
            $this->fail('Expected serialize() to be refused.');
        } catch (\Throwable $e) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
            $this->assertStringNotContainsStringIgnoringCase('error:0', $e->getMessage()); // no OpenSSL
        }
    }

    public function test_signature_result_redaction_and_accessor(): void
    {
        $result = $this->signatureResult();
        $secrets = ['SECRET-CMS-DER-BYTES'];

        $this->assertSame(self::SECRET_DER, $result->cmsDer());
        $this->assertNoLeakAcrossSurfaces($result, $secrets);
        $this->assertSerializationRefused($result, $secrets);

        $this->assertStringContainsString('detached-cms', (string) json_encode($result));
        $this->assertArrayNotHasKey('cmsDer', $result->toArray());
    }

    public function test_verification_request_redaction_and_accessors(): void
    {
        $request = new DetachedCmsVerificationRequest(
            sourcePath: self::SECRET_SOURCE_PATH,
            cmsDer: self::SECRET_DER,
            rootCaPath: self::SECRET_ROOT_CA_PATH,
            expectedSignerFingerprint: str_repeat('d', 64),
            expectedSourceHash: str_repeat('e', 64),
            certificateActive: true,
        );
        $secrets = ['SECRET-CMS-DER-BYTES', '/private/keys'];

        $this->assertSame(self::SECRET_SOURCE_PATH, $request->sourcePath());
        $this->assertSame(self::SECRET_DER, $request->cmsDer());
        $this->assertSame(self::SECRET_ROOT_CA_PATH, $request->rootCaPath());

        $this->assertNoLeakAcrossSurfaces($request, $secrets);
        $this->assertSerializationRefused($request, $secrets);
    }

    public function test_sign_request_does_not_leak_loaded_certificate_file_graph(): void
    {
        // Reproduce the exact prior leak: a Certificate with a LOADED `file`
        // relation carrying sentinel StoredFile attributes.
        $sentinelPath = 'SENTINEL-STORAGE-PATH/signing/certificates/user-7/leak.pem';
        $sentinelSha = 'SENTINEL-FILE-SHA-'.str_repeat('9', 40);
        $sentinelName = 'SENTINEL-ORIGINAL-FILENAME.pem';

        $file = new StoredFile;
        $file->setAttribute('id', 5);
        $file->setAttribute('purpose', StoredFile::PURPOSE_CERTIFICATE);
        $file->setAttribute('storage_disk', StoredFile::DISK_LOCAL);
        $file->setAttribute('storage_path', $sentinelPath);
        $file->setAttribute('original_filename', $sentinelName);
        $file->setAttribute('sha256', $sentinelSha);

        $certificate = new Certificate;
        $certificate->setAttribute('id', 7);
        $certificate->exists = true; // saved model (required by the request guard)
        $certificate->setRelation('file', $file); // relation LOADED

        $request = new DetachedCmsSignRequest(
            sourcePath: self::SECRET_SOURCE_PATH,
            expectedSourceSha256: str_repeat('f', 64),
            certificate: $certificate,
            expectedOwnerUserId: 42,
        );

        // The explicit identifier accessor still returns the real key.
        $this->assertSame(7, $request->certificateId());

        // No source path AND no loaded StoredFile field may appear on any surface.
        $secrets = [
            self::SECRET_SOURCE_PATH,
            '/private/keys',
            $sentinelPath,
            'SENTINEL-STORAGE-PATH',
            $sentinelSha,
            'SENTINEL-FILE-SHA',
            $sentinelName,
            'SENTINEL-ORIGINAL-FILENAME',
        ];
        $this->assertNoLeakAcrossSurfaces($request, $secrets);
        $this->assertSerializationRefused($request, $secrets);

        // Safe fields remain available.
        $this->assertStringContainsString('42', (string) json_encode($request));
        $this->assertStringContainsString('"certificate_id":7', (string) json_encode($request));
    }

    public function test_unserialize_fails_closed(): void
    {
        $this->expectException(\Throwable::class);
        $result = $this->signatureResult();
        $result->__unserialize(['cmsDer' => self::SECRET_DER]);
    }

    public function test_clone_is_prohibited(): void
    {
        $this->expectException(\Throwable::class);
        $result = $this->signatureResult();
        clone $result;
    }
}
