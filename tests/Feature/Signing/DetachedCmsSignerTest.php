<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use App\Models\Certificate;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Signing\DetachedCmsSignatureResult;
use App\Services\Signing\DetachedCmsSigner;
use App\Services\Signing\DetachedCmsSignRequest;
use App\Services\Signing\DetachedCmsVerificationRequest;
use App\Services\Signing\DetachedCmsVerificationResult;
use App\Services\Signing\DetachedCmsVerifier;
use App\Services\Signing\SignerCertificateRegistrar;
use App\Services\Signing\SigningConfig;
use App\Services\Signing\SigningWorkspaceHandle;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Real native-OpenSSL detached CMS signing hardening: mandatory expected-hash
 * binding, single read-only snapshot used for sign+verify, DB re-fetch and final
 * re-confirmation, source-race guard, redacted result, and deterministic
 * failure injection. Ephemeral PKI only; no Signature/CMS StoredFile rows.
 */
final class DetachedCmsSignerTest extends SigningTestCase
{
    private const PASSPHRASE = 'cms-passphrase-123';

    private function signer(): DetachedCmsSigner
    {
        return app(DetachedCmsSigner::class);
    }

    private function pdfBytes(string $marker = 'contract'): string
    {
        return "%PDF-1.4\n% ".$marker." vehicle sale\n1 0 obj<<>>endobj\n%%EOF\n";
    }

    /**
     * @return array{user: User, certificate: Certificate, ca: array, signer: array}
     */
    private function registerValidSigner(string $profile = 'v3_signer', int $days = 825): array
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, $profile, $days);
        $this->configureSigning($signer['key'], self::PASSPHRASE, $ca['pem']);
        $user = User::factory()->create();
        $certificate = app(SignerCertificateRegistrar::class)->register($user, $this->writeCertificateInput($signer['pem']));

        return ['user' => $user, 'certificate' => $certificate, 'ca' => $ca, 'signer' => $signer];
    }

    /**
     * @return array{path: string, sha: string, bytes: string}
     */
    private function makeSource(string $bytes, string $name = 'contract.pdf'): array
    {
        return ['path' => $this->writeExternal($name, $bytes), 'sha' => hash('sha256', $bytes), 'bytes' => $bytes];
    }

    private function request(array $ctx, array $src, ?string $expected = null, ?int $owner = null): DetachedCmsSignRequest
    {
        return new DetachedCmsSignRequest(
            $src['path'],
            $expected ?? $src['sha'],
            $ctx['certificate'],
            $owner ?? (int) $ctx['user']->id,
        );
    }

    /**
     * @param  array{cert: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}  $material
     */
    private function seedCertificate(User $user, array $material): Certificate
    {
        $pem = $material['pem'];
        $path = 'signing/certificates/user-'.$user->id.'/seed-'.bin2hex(random_bytes(6)).'.pem';
        $this->putFileChecked($this->certificateFilesystem(), $path, $pem);
        $parsed = openssl_x509_parse($material['cert']);

        $file = StoredFile::create([
            'purpose' => StoredFile::PURPOSE_CERTIFICATE, 'storage_disk' => 'local', 'storage_path' => $path,
            'original_filename' => 'signer-certificate.pem', 'mime_type' => 'application/x-pem-file',
            'size_bytes' => strlen($pem), 'sha256' => hash('sha256', $pem), 'created_by_user_id' => $user->id,
        ]);

        return Certificate::create([
            'owner_type' => Certificate::OWNER_TYPE_USER, 'owner_user_id' => $user->id, 'owner_customer_id' => null,
            'label' => 'seed', 'subject_dn' => '/CN=seed', 'issuer_dn' => '/CN=seed', 'serial_number' => '1',
            'valid_from' => Carbon::createFromTimestampUTC($parsed['validFrom_time_t']),
            'valid_to' => Carbon::createFromTimestampUTC($parsed['validTo_time_t']),
            'thumbprint_sha256' => $this->fingerprint($material['cert']), 'file_id' => $file->id, 'is_active' => true,
        ])->refresh();
    }

    private function assertSignFails(string $expectedCode, callable $fn, ?bool $compensationIncomplete = null): DetachedCmsException
    {
        try {
            $fn();
            $this->fail("Expected CMS signing failure {$expectedCode}, but it succeeded.");
        } catch (DetachedCmsException $e) {
            $this->assertSame($expectedCode, $e->errorCode());
            $this->assertStringNotContainsStringIgnoringCase('error:0', $e->getMessage()); // no raw OpenSSL
            if ($compensationIncomplete !== null) {
                $this->assertSame($compensationIncomplete, $e->compensationIncomplete());
            }

            return $e;
        }
    }

    /**
     * @return list<string>
     */
    private function tempWorkspaceDirs(): array
    {
        return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'m10-cms-*', GLOB_ONLYDIR) ?: [];
    }

    /**
     * A configurable seam signer for deterministic injection.
     */
    private function seamSigner(array $opts = []): DetachedCmsSigner
    {
        return new class($this->signingConfig(), $opts) extends DetachedCmsSigner
        {
            /** @var list<SigningWorkspaceHandle> */
            public array $handles = [];

            public ?bool $naiveWriteBlocked = null;

            public function __construct(SigningConfig $config, private readonly array $opts)
            {
                parent::__construct($config);
            }

            protected function createWorkspace(): SigningWorkspaceHandle
            {
                $handle = parent::createWorkspace();
                $this->handles[] = $handle;

                return $handle;
            }

            protected function cmsSign(string $inputFile, string $outputFile, OpenSSLCertificate $certificate, OpenSSLAsymmetricKey $privateKey): bool
            {
                return ($this->opts['failSign'] ?? false) ? false : parent::cmsSign($inputFile, $outputFile, $certificate, $privateKey);
            }

            protected function removeWorkspace(SigningWorkspaceHandle $handle): bool
            {
                return ($this->opts['failCleanup'] ?? false) ? false : parent::removeWorkspace($handle);
            }

            protected function onSnapshotCreated(string $canonicalSource, string $snapshotPath): void
            {
                if (isset($this->opts['mutateSource'])) {
                    file_put_contents($this->opts['mutateSource'], 'MUTATED-AFTER-SNAPSHOT');
                }
                if (($this->opts['naiveWriteSnapshot'] ?? false) === true) {
                    // Attempt to write WITHOUT clearing read-only: must be blocked.
                    $this->naiveWriteBlocked = @file_put_contents($snapshotPath, 'NAIVE-WRITE') === false;
                }
                if (($this->opts['forceTamperSnapshot'] ?? false) === true) {
                    // Simulate an attacker with write access defeating read-only.
                    @chmod($snapshotPath, 0600);
                    file_put_contents($snapshotPath, 'SNAPSHOT-TAMPERED-AFTER-INITIAL-HASH');
                }
            }

            protected function beforeFinalReconfirm(): void
            {
                if (isset($this->opts['deactivateOwner'])) {
                    DB::table('certificates')->where('owner_user_id', $this->opts['deactivateOwner'])->update(['is_active' => false]);
                }
            }
        };
    }

    /**
     * A signer that captures the certificate state at the exact moment just
     * before the first private-key operation.
     */
    private function capturingSigner(): DetachedCmsSigner
    {
        return new class($this->signingConfig()) extends DetachedCmsSigner
        {
            public ?bool $fileLoadedBeforeKey = null;

            public ?string $freshFileStoragePath = null;

            public int|string|null $freshFileId = null;

            protected function beforePrivateKeyOperation(Certificate $certificate): void
            {
                $this->fileLoadedBeforeKey = $certificate->relationLoaded('file');
                $file = $certificate->relationLoaded('file') ? $certificate->getRelation('file') : null;
                $this->freshFileStoragePath = $file?->storage_path;
                $this->freshFileId = $file?->getKey();
            }
        };
    }

    /**
     * A signer that counts private-key-loader calls and records the relation
     * state at the pre-key seam, to prove strict pre-key / post-key ordering.
     */
    private function trackingSigner(): DetachedCmsSigner
    {
        return new class($this->signingConfig()) extends DetachedCmsSigner
        {
            public int $loadKeyCalls = 0;

            public ?bool $fileLoadedAtSeam = null;

            protected function beforePrivateKeyOperation(Certificate $certificate): void
            {
                $this->fileLoadedAtSeam = $certificate->relationLoaded('file');
            }

            protected function loadPrivateKey(string $privateKeyPem, string $passphrase): OpenSSLAsymmetricKey|false
            {
                $this->loadKeyCalls++;

                return parent::loadPrivateKey($privateKeyPem, $passphrase);
            }
        };
    }

    private function assertPreKeyFailure(string $code, DetachedCmsSigner $signer, DetachedCmsSignRequest $request): void
    {
        try {
            $signer->sign($request);
            $this->fail("Expected pre-key failure {$code}.");
        } catch (DetachedCmsException $e) {
            $this->assertSame($code, $e->errorCode());
        }
        // @phpstan-ignore-next-line property exists on the tracking subclass
        $this->assertSame(0, $signer->loadKeyCalls, 'private-key loader must NOT run on a pre-key failure');
    }

    private function deleteResidual(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->deleteResidual($path);
            } else {
                @chmod($path, 0600); // clear the read-only snapshot bit before unlink
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // --- success + source-byte integrity ------------------------------------

    public function test_snapshot_hash_equals_expected_equals_signed_and_verifies(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $before = $this->tempWorkspaceDirs();

        $result = $this->signer()->sign($this->request($ctx, $src));

        $this->assertInstanceOf(DetachedCmsSignatureResult::class, $result);
        // snapshot hash == expected == signed-content hash (all three equal)
        $this->assertSame($src['sha'], $result->signedSnapshotSha256());
        $this->assertSame($src['sha'], $result->expectedSourceSha256());
        $this->assertSame($src['sha'], $result->originalSourceSha256AfterSigning());
        $this->assertTrue($result->verification()->overall);
        $this->assertTrue($result->verification()->cryptographicValid);
        $this->assertTrue($result->verification()->trustValid);
        $this->assertTrue($result->verification()->sourceHashMatches);
        // original bytes untouched + no temp leak
        $this->assertSame($src['bytes'], file_get_contents($src['path']));
        $this->assertSame($before, $this->tempWorkspaceDirs());
    }

    public function test_cms_output_is_non_empty_binary_der_not_pem(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());

        $result = $this->signer()->sign($this->request($ctx, $src));

        $this->assertNotSame('', $result->cmsDer());
        $this->assertSame(0x30, ord($result->cmsDer()[0]));
        $this->assertStringNotContainsString('-----BEGIN', $result->cmsDer());
        $this->assertSame(hash('sha256', $result->cmsDer()), $result->cmsSha256());
    }

    public function test_signer_fingerprint_belongs_to_the_registered_certificate(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());

        $result = $this->signer()->sign($this->request($ctx, $src));

        $this->assertSame($ctx['certificate']->thumbprint_sha256, $result->signerFingerprint());
        $this->assertSame($this->fingerprint($ctx['signer']['cert']), $result->signerFingerprint());
        $this->assertTrue($result->verification()->signerFingerprintMatches);
    }

    public function test_sign_and_verify_use_the_same_snapshot_file(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());

        $spy = new class extends DetachedCmsVerifier
        {
            public ?string $seenPath = null;

            public function verify(DetachedCmsVerificationRequest $request): DetachedCmsVerificationResult
            {
                $this->seenPath = $request->sourcePath();

                return parent::verify($request);
            }
        };
        $signer = new DetachedCmsSigner($this->signingConfig(), verifier: $spy);

        $result = $signer->sign($this->request($ctx, $src));

        $this->assertTrue($result->verification()->overall);
        $this->assertNotNull($spy->seenPath);
        // The verifier verified the private workspace snapshot, not the original.
        $this->assertSame('source-snapshot.bin', basename($spy->seenPath));
        $this->assertMatchesRegularExpression('/m10-cms-[0-9a-f]{32}/', $spy->seenPath);
        $this->assertNotSame(realpath($src['path']), $spy->seenPath);
    }

    // --- expected-hash binding + source race --------------------------------

    public function test_expected_hash_mismatch_fails_closed(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());

        $this->assertSignFails(
            DetachedCmsException::CMS_SOURCE_HASH_MISMATCH,
            fn () => $this->signer()->sign($this->request($ctx, $src, expected: str_repeat('a', 64))),
        );
    }

    public function test_uppercase_expected_hash_is_accepted_and_canonicalized(): void
    {
        // Contract (b): a case-insensitive 64-hex expected hash is accepted and
        // canonicalized to lowercase; an UPPERCASE expected hash signs the same.
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());

        $result = $this->signer()->sign($this->request($ctx, $src, expected: strtoupper($src['sha'])));

        $this->assertTrue($result->verification()->overall);
        $this->assertSame($src['sha'], $result->expectedSourceSha256()); // lowercase canonical
        $this->assertSame($src['sha'], $result->signedSnapshotSha256());
    }

    public function test_malformed_expected_hash_is_rejected(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());

        // Note: an uppercase hash is NOT malformed — the signer normalizes case;
        // these are genuinely invalid (non-hex, wrong length, empty).
        foreach (['not-a-hash', str_repeat('g', 64), $src['sha'].'00', ''] as $bad) {
            $this->assertSignFails(
                DetachedCmsException::CMS_SOURCE_HASH_MISMATCH,
                fn () => $this->signer()->sign($this->request($ctx, $src, expected: $bad)),
            );
        }
    }

    public function test_deterministic_source_race_between_original_and_snapshot_fails_closed(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $signer = $this->seamSigner(['mutateSource' => $src['path']]);
        $before = $this->tempWorkspaceDirs();

        $this->assertSignFails(
            DetachedCmsException::CMS_SOURCE_HASH_MISMATCH,
            fn () => $signer->sign($this->request($ctx, $src)),
        );
        // The original was mutated after the snapshot; nothing leaked.
        $this->assertSame('MUTATED-AFTER-SNAPSHOT', file_get_contents($src['path']));
        $this->assertSame($before, $this->tempWorkspaceDirs());
    }

    public function test_read_only_snapshot_blocks_a_naive_write_and_still_succeeds(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $before = $this->tempWorkspaceDirs();
        $signer = $this->seamSigner(['naiveWriteSnapshot' => true]);

        $result = $signer->sign($this->request($ctx, $src));

        // The write to the read-only snapshot was refused, so signing succeeds
        // and the initial/final/expected hashes all agree.
        $this->assertTrue($signer->naiveWriteBlocked);
        $this->assertTrue($result->verification()->overall);
        $this->assertSame($src['sha'], $result->signedSnapshotSha256());
        $this->assertSame($before, $this->tempWorkspaceDirs());
    }

    public function test_snapshot_toctou_forced_tamper_fails_closed_on_final_hash(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $before = $this->tempWorkspaceDirs();
        // The tamper defeats read-only after the initial hash; the post-verify
        // snapshot re-hash (expected === initial === final) must catch it.
        $signer = $this->seamSigner(['forceTamperSnapshot' => true]);

        $this->assertSignFails(
            DetachedCmsException::CMS_SOURCE_HASH_MISMATCH,
            fn () => $signer->sign($this->request($ctx, $src)),
        );
        $this->assertSame($before, $this->tempWorkspaceDirs());
    }

    // --- certificate + key + trust precondition failures --------------------

    public function test_wrong_root_ca_breaks_trust(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $otherCa = $this->newRootCa('Other Root');
        config(['signing.root_ca_path' => $this->writeExternal('other-ca.pem', $otherCa['pem'])]);

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_UNTRUSTED,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
    }

    public function test_wrong_private_key_has_its_own_stable_code(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        config([
            'signing.private_key_path' => $this->writeExternal('other-key.pem', $this->encryptedKeyPem($this->newKey(), 'op')),
            'signing.passphrase_file_path' => $this->writeExternal('other-pass.txt', 'op'),
        ]);

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_KEY_MISMATCH,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
    }

    public function test_wrong_passphrase_has_its_own_stable_code(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $this->writeFileChecked((string) config('signing.passphrase_file_path'), 'the-wrong-passphrase');

        $e = $this->assertSignFails(
            DetachedCmsException::CMS_PRIVATE_KEY_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
        $this->assertStringNotContainsString('the-wrong-passphrase', $e->getMessage());
    }

    public function test_inactive_certificate_is_rejected_on_refetch(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        DB::table('certificates')->where('id', $ctx['certificate']->id)->update(['is_active' => false]);

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
    }

    public function test_certificate_deactivated_mid_operation_fails_final_reconfirmation(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $signer = $this->seamSigner(['deactivateOwner' => (int) $ctx['user']->id]);

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $signer->sign($this->request($ctx, $src)),
        );
    }

    public function test_not_yet_valid_certificate_is_rejected(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        Carbon::setTestNow(Carbon::now()->subDays(5));

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
    }

    public function test_expired_certificate_is_rejected(): void
    {
        $ctx = $this->registerValidSigner('v3_signer', 1);
        $src = $this->makeSource($this->pdfBytes());
        Carbon::setTestNow(Carbon::now()->addDays(5));

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
    }

    public function test_ca_certificate_is_rejected(): void
    {
        $ca = $this->newRootCa();
        $caLike = $this->issueCertificate($ca, 'v3_ca');
        $this->configureSigning($caLike['key'], self::PASSPHRASE, $ca['pem']);
        $user = User::factory()->create();
        $certificate = $this->seedCertificate($user, $caLike);
        $src = $this->makeSource($this->pdfBytes());

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign(new DetachedCmsSignRequest($src['path'], $src['sha'], $certificate, (int) $user->id)),
        );
    }

    public function test_certificate_without_digital_signature_is_rejected(): void
    {
        $ca = $this->newRootCa();
        $noDs = $this->issueCertificate($ca, 'v3_signer_no_ds');
        $this->configureSigning($noDs['key'], self::PASSPHRASE, $ca['pem']);
        $user = User::factory()->create();
        $certificate = $this->seedCertificate($user, $noDs);
        $src = $this->makeSource($this->pdfBytes());

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign(new DetachedCmsSignRequest($src['path'], $src['sha'], $certificate, (int) $user->id)),
        );
    }

    public function test_incompatible_eku_is_rejected_as_untrusted(): void
    {
        $ca = $this->newRootCa();
        $wrongEku = $this->issueCertificate($ca, 'v3_signer_wrong_eku');
        $this->configureSigning($wrongEku['key'], self::PASSPHRASE, $ca['pem']);
        $user = User::factory()->create();
        $certificate = $this->seedCertificate($user, $wrongEku);
        $src = $this->makeSource($this->pdfBytes());

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_UNTRUSTED,
            fn () => $this->signer()->sign(new DetachedCmsSignRequest($src['path'], $src['sha'], $certificate, (int) $user->id)),
        );
    }

    public function test_tampered_stored_certificate_is_rejected(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $file = StoredFile::findOrFail($ctx['certificate']->file_id);
        $this->putFileChecked($this->certificateFilesystem(), $file->storage_path, 'garbage-not-a-cert');

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
    }

    public function test_storage_adapter_exception_is_sanitized(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $secretPath = 'signing/certificates/user-'.$ctx['user']->id.'/PROVIDER-SECRET.pem';

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('get')->andThrow(new RuntimeException('provider failure at '.$secretPath));
        Storage::shouldReceive('build')->andReturn($disk);

        $e = $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $src)),
        );
        $this->assertStringNotContainsString('PROVIDER-SECRET', $e->getMessage());
        $this->assertStringNotContainsString('provider failure', $e->getMessage());
    }

    public function test_missing_and_empty_source_are_rejected(): void
    {
        $ctx = $this->registerValidSigner();

        $this->assertSignFails(
            DetachedCmsException::CMS_SOURCE_INVALID,
            fn () => $this->signer()->sign(new DetachedCmsSignRequest($this->tempDir.'/nope.pdf', str_repeat('a', 64), $ctx['certificate'], (int) $ctx['user']->id)),
        );

        $empty = $this->makeSource('', 'empty.pdf');
        $this->assertSignFails(
            DetachedCmsException::CMS_SOURCE_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $empty)),
        );
    }

    public function test_certificate_of_a_different_owner_is_rejected(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $intruder = User::factory()->create();

        $this->assertSignFails(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            fn () => $this->signer()->sign($this->request($ctx, $src, owner: (int) $intruder->id)),
        );
    }

    // --- fresh certificate + eager relation before private key --------------

    public function test_certificate_file_relation_is_eager_loaded_before_the_private_key_operation(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $realFile = StoredFile::findOrFail($ctx['certificate']->file_id);
        $signer = $this->capturingSigner();

        $result = $signer->sign($this->request($ctx, $src));

        $this->assertTrue($result->verification()->overall);
        // Proven by ordering, not by a post-hoc query count: at the seam that runs
        // immediately before openssl_pkey_get_private, the relation is loaded.
        $this->assertTrue($signer->fileLoadedBeforeKey, 'file relation not loaded before the private-key operation');
        $this->assertSame($realFile->storage_path, $signer->freshFileStoragePath);
        $this->assertSame($realFile->getKey(), $signer->freshFileId);
    }

    public function test_signer_uses_fresh_db_relation_not_the_stale_caller_relation(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $realPath = StoredFile::findOrFail($ctx['certificate']->file_id)->storage_path;

        // The caller model carries a STALE loaded `file` relation (sentinel path);
        // the DB record is the real current state.
        $cert = $ctx['certificate'];
        $stale = new StoredFile;
        $stale->setAttribute('id', $cert->file_id);
        $stale->setAttribute('storage_path', 'SENTINEL-STALE-CALLER-PATH');
        $stale->exists = true;
        $cert->setRelation('file', $stale);

        $signer = $this->capturingSigner();
        $result = $signer->sign(new DetachedCmsSignRequest($src['path'], $src['sha'], $cert, (int) $ctx['user']->id));

        $this->assertTrue($result->verification()->overall);
        // The fresh DB relation was used; the stale caller sentinel never appears.
        $this->assertSame($realPath, $signer->freshFileStoragePath);
        $this->assertNotSame('SENTINEL-STALE-CALLER-PATH', $signer->freshFileStoragePath);
    }

    // --- pre-key / post-key ordering ----------------------------------------

    public function test_positive_ordering_loads_the_key_exactly_once_after_pre_key_checks(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $signer = $this->trackingSigner();

        $result = $signer->sign($this->request($ctx, $src));

        $this->assertTrue($result->verification()->overall);
        $this->assertTrue($signer->fileLoadedAtSeam, 'relation must be loaded at the pre-key seam');
        $this->assertSame(1, $signer->loadKeyCalls, 'private-key loader must run exactly once');
    }

    public function test_wrong_owner_is_a_pre_key_failure(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $intruder = User::factory()->create();

        $this->assertPreKeyFailure(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            $this->trackingSigner(),
            $this->request($ctx, $src, owner: (int) $intruder->id),
        );
    }

    public function test_inactive_certificate_is_a_pre_key_failure(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        DB::table('certificates')->where('id', $ctx['certificate']->id)->update(['is_active' => false]);

        $this->assertPreKeyFailure(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            $this->trackingSigner(),
            $this->request($ctx, $src),
        );
    }

    public function test_not_yet_valid_certificate_is_a_pre_key_failure(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        Carbon::setTestNow(Carbon::now()->subDays(5));

        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), $this->request($ctx, $src));
    }

    public function test_expired_certificate_is_a_pre_key_failure(): void
    {
        $ctx = $this->registerValidSigner('v3_signer', 1);
        $src = $this->makeSource($this->pdfBytes());
        Carbon::setTestNow(Carbon::now()->addDays(5));

        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), $this->request($ctx, $src));
    }

    public function test_missing_stored_file_relation_is_a_pre_key_failure(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        StoredFile::query()->whereKey($ctx['certificate']->file_id)->delete();

        $this->assertPreKeyFailure(
            DetachedCmsException::CMS_CERTIFICATE_INVALID,
            $this->trackingSigner(),
            $this->request($ctx, $src),
        );
    }

    public function test_wrong_purpose_or_disk_is_a_pre_key_failure(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());

        StoredFile::query()->whereKey($ctx['certificate']->file_id)->update(['purpose' => StoredFile::PURPOSE_FINAL_PDF]);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), $this->request($ctx, $src));

        StoredFile::query()->whereKey($ctx['certificate']->file_id)->update(['purpose' => StoredFile::PURPOSE_CERTIFICATE, 'storage_disk' => 's3']);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), $this->request($ctx, $src));
    }

    public function test_size_hash_and_fingerprint_mismatches_are_pre_key_failures(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $file = StoredFile::findOrFail($ctx['certificate']->file_id);

        $file->update(['size_bytes' => 1]);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), $this->request($ctx, $src));

        $file->update(['size_bytes' => strlen((string) $this->certificateFilesystem()->get($file->storage_path)), 'sha256' => str_repeat('0', 64)]);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), $this->request($ctx, $src));

        // Fingerprint mismatch: a DIFFERENT valid certificate with consistent size/hash.
        $other = $this->issueCertificate($this->newRootCa('Other'), 'v3_signer');
        $this->putFileChecked($this->certificateFilesystem(), $file->storage_path, $other['pem']);
        $file->update(['size_bytes' => strlen($other['pem']), 'sha256' => hash('sha256', $other['pem'])]);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), $this->request($ctx, $src));
    }

    public function test_ca_true_missing_ds_and_wrong_eku_are_pre_key_failures(): void
    {
        $ca = $this->newRootCa();
        $user = User::factory()->create();
        $src = $this->makeSource($this->pdfBytes());

        $caLike = $this->issueCertificate($ca, 'v3_ca');
        $this->configureSigning($caLike['key'], self::PASSPHRASE, $ca['pem']);
        $caCert = $this->seedCertificate($user, $caLike);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), new DetachedCmsSignRequest($src['path'], $src['sha'], $caCert, (int) $user->id));

        $noDs = $this->issueCertificate($ca, 'v3_signer_no_ds');
        $this->configureSigning($noDs['key'], self::PASSPHRASE, $ca['pem']);
        $noDsCert = $this->seedCertificate($user, $noDs);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_INVALID, $this->trackingSigner(), new DetachedCmsSignRequest($src['path'], $src['sha'], $noDsCert, (int) $user->id));

        $wrongEku = $this->issueCertificate($ca, 'v3_signer_wrong_eku');
        $this->configureSigning($wrongEku['key'], self::PASSPHRASE, $ca['pem']);
        $ekuCert = $this->seedCertificate($user, $wrongEku);
        $this->assertPreKeyFailure(DetachedCmsException::CMS_CERTIFICATE_UNTRUSTED, $this->trackingSigner(), new DetachedCmsSignRequest($src['path'], $src['sha'], $ekuCert, (int) $user->id));
    }

    public function test_key_mismatch_is_a_post_key_failure_after_the_loader(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        // A DIFFERENT valid private key: it loads, but does not match the cert.
        config([
            'signing.private_key_path' => $this->writeExternal('other-key.pem', $this->encryptedKeyPem($this->newKey(), 'op')),
            'signing.passphrase_file_path' => $this->writeExternal('other-pass.txt', 'op'),
        ]);
        $signer = $this->trackingSigner();

        try {
            $signer->sign($this->request($ctx, $src));
            $this->fail('Expected CMS_CERTIFICATE_KEY_MISMATCH.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_CERTIFICATE_KEY_MISMATCH, $e->errorCode());
        }
        // Distinct from a pre-key failure: the loader WAS reached exactly once.
        $this->assertSame(1, $signer->loadKeyCalls, 'key mismatch is a post-key check: the loader must have run');
    }

    // --- temp workspace cleanup ---------------------------------------------

    public function test_temp_workspace_is_cleaned_up_after_signing_failure(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $before = $this->tempWorkspaceDirs();
        $signer = $this->seamSigner(['failSign' => true]);

        $this->assertSignFails(
            DetachedCmsException::CMS_SIGN_FAILED,
            fn () => $signer->sign($this->request($ctx, $src)),
            compensationIncomplete: false,
        );

        $this->assertNotEmpty($signer->handles);
        $this->assertSame($before, $this->tempWorkspaceDirs());
        foreach ($signer->handles as $handle) {
            $this->assertDirectoryDoesNotExist($handle->path());
        }
    }

    public function test_cleanup_failure_preserves_primary_code_and_signals_incomplete(): void
    {
        $ctx = $this->registerValidSigner();
        $src = $this->makeSource($this->pdfBytes());
        $signer = $this->seamSigner(['failSign' => true, 'failCleanup' => true]);

        try {
            $this->assertSignFails(
                DetachedCmsException::CMS_SIGN_FAILED,
                fn () => $signer->sign($this->request($ctx, $src)),
                compensationIncomplete: true,
            );
        } finally {
            foreach ($signer->handles as $handle) {
                $this->deleteResidual($handle->path());
            }
        }
    }
}
