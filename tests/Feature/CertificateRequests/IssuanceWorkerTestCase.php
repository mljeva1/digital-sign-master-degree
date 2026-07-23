<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Models\CertificateRequest;
use App\Models\User;
use App\Services\CertificateRequests\CertificateRequestWorkflow;
use App\Services\Signing\LocalSignerCertificateIssuanceService;
use App\Services\Signing\LocalSigningRoot;
use RuntimeException;

/**
 * Hermetic base for the M14 Phase B issuance-worker suites.
 *
 * On top of the shared SQLite application harness it relocates the whole storage
 * path into a per-test temp directory, so the worker's REAL native-OpenSSL
 * issuance runs against a freshly bootstrapped local signing root without ever
 * reading, writing, or deleting the developer's actual signing material. The temp
 * tree is removed in tearDown.
 */
abstract class IssuanceWorkerTestCase extends CertificateRequestTestCase
{
    protected string $signingRoot;

    private string $originalStoragePath;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = app()->storagePath();

        $this->tempStorageBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m14-issue-'.bin2hex(random_bytes(8));
        $storage = $this->tempStorageBase.DIRECTORY_SEPARATOR.'storage';
        $private = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private';
        if (! mkdir($private, 0700, true) && ! is_dir($private)) {
            throw new RuntimeException('Could not create the temporary storage tree.');
        }

        app()->useStoragePath($storage);

        $this->signingRoot = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'signing'.DIRECTORY_SEPARATOR.'local');

        config([
            'signing.local_material_path' => $this->signingRoot,
            'signing.private_key_path' => $this->signingRoot.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_SIGNER_KEY,
            'signing.passphrase_file_path' => $this->signingRoot.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_PASSPHRASE,
            'signing.root_ca_path' => $this->signingRoot.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_ROOT_CA,
            'signing.certificate_disk' => 'local',
            'filesystems.disks.local.root' => storage_path('app'.DIRECTORY_SEPARATOR.'private'),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalStoragePath)) {
            app()->useStoragePath($this->originalStoragePath);
        }

        if (isset($this->tempStorageBase)) {
            $this->deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    /** Bootstrap the shared Root CA + signer key + passphrase into the temp root. */
    protected function bootstrapSigningRoot(): void
    {
        $root = (new LocalSigningRoot)->ensure();
        if ($root === null) {
            throw new RuntimeException('Could not establish the temporary signing root.');
        }

        $created = [];
        app(LocalSignerCertificateIssuanceService::class)->bootstrapOrLoadMaterial($root, $created);
    }

    protected function workflow(): CertificateRequestWorkflow
    {
        return app(CertificateRequestWorkflow::class);
    }

    /** An approved request ready for the worker (queue pinned atomic). */
    protected function approvedRequest(?User $subject = null): CertificateRequest
    {
        $this->useAtomicDatabaseQueue();
        $subject ??= $this->userWithRole();

        return $this->workflow()->approve($this->workflow()->create($subject), $this->operator());
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
