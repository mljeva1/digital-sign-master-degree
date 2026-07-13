<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Services\Signing\SigningConfig;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;
use Tests\TestCase;

/**
 * Base for M10 certificate-foundation tests.
 *
 * All X.509 material (Root CA, signer certificates, private keys, passphrases)
 * is generated with native PHP OpenSSL into a random OS temp directory that is
 * deleted in tearDown. No crypto fixture is ever committed. The certificates /
 * files / users tables are hand-built for SQLite because the real migrations
 * carry PostgreSQL-only CHECK DDL; these hand-built tables prove APPLICATION
 * behaviour only, never a PostgreSQL CHECK/FK constraint.
 *
 * Safety: schema build/drop only ever runs after a fail-closed confirmation
 * that the connection is an in-memory SQLite database; temp material is always
 * cleaned up even if setUp or a cleanup phase throws.
 */
abstract class SigningTestCase extends TestCase
{
    protected string $tempDir;

    protected string $opensslConfig;

    private bool $schemaSafe = false;

    private const OPENSSL_CNF = <<<'CNF'
[req]
distinguished_name = req_dn
prompt = no

[req_dn]
CN = M10 Test

[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash

[v3_signer]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash

[v3_signer_no_ds]
basicConstraints = critical,CA:FALSE
keyUsage = critical,keyEncipherment
subjectKeyIdentifier = hash

[v3_signer_wrong_eku]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = serverAuth
subjectKeyIdentifier = hash

[v3_signer_no_basic_constraints]
keyUsage = critical,digitalSignature
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
CNF;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m10-cert-'.bin2hex(random_bytes(8));
        if (! mkdir($this->tempDir, 0700, true) && ! is_dir($this->tempDir)) {
            throw new RuntimeException('Could not create temp directory for signing tests.');
        }

        try {
            $this->assertInMemorySqlite();
            $this->schemaSafe = true;
            $this->opensslConfig = $this->writeExternal('openssl.cnf', self::OPENSSL_CNF);
            $this->buildSchema();
            // Pin the local disk root to an existing absolute temp directory so
            // the fail-closed disk-root validation is environment-independent.
            config(['filesystems.disks.local.root' => $this->tempDir]);
        } catch (\Throwable $e) {
            // Any failure after the temp directory exists must clean it up now.
            $this->deleteDirectory($this->tempDir);

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        // Nested finally: every phase is attempted even if an earlier one throws.
        try {
            $this->safeDropSchema();
        } finally {
            try {
                $this->deleteDirectory($this->tempDir);
            } finally {
                $this->tearDownParent();
            }
        }
    }

    protected function tearDownParent(): void
    {
        parent::tearDown();
    }

    protected function signingConfig(): SigningConfig
    {
        return new SigningConfig;
    }

    protected function certificateFilesystem(): Filesystem
    {
        return $this->signingConfig()->certificateStorage()->filesystem;
    }

    protected function putFileChecked(Filesystem $filesystem, string $path, string $contents): void
    {
        if ($filesystem->put($path, $contents) !== true) {
            throw new RuntimeException('Failed to write test storage artefact.');
        }
    }

    // --- ephemeral PKI ------------------------------------------------------

    protected function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $this->opensslConfig,
        ]);

        if ($key === false) {
            throw new RuntimeException('Ephemeral key generation failed.');
        }

        return $key;
    }

    /**
     * @return array{cert: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}
     */
    protected function newRootCa(string $cn = 'M10 Test Root CA'): array
    {
        $key = $this->newKey();
        $csr = openssl_csr_new(['commonName' => $cn], $key, [
            'config' => $this->opensslConfig,
            'digest_alg' => 'sha256',
        ]);

        $cert = openssl_csr_sign($csr, null, $key, 3650, [
            'config' => $this->opensslConfig,
            'x509_extensions' => 'v3_ca',
            'digest_alg' => 'sha256',
        ], random_int(1, PHP_INT_MAX));

        if ($cert === false) {
            throw new RuntimeException('Root CA generation failed.');
        }

        return ['cert' => $cert, 'key' => $key, 'pem' => $this->certificatePem($cert)];
    }

    /**
     * @param  array{cert: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}  $ca
     * @return array{cert: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}
     */
    protected function issueCertificate(array $ca, string $profile = 'v3_signer', int $days = 825, string $cn = 'M10 Test Signer'): array
    {
        $key = $this->newKey();
        $csr = openssl_csr_new(['commonName' => $cn], $key, [
            'config' => $this->opensslConfig,
            'digest_alg' => 'sha256',
        ]);

        $cert = openssl_csr_sign($csr, $ca['cert'], $ca['key'], $days, [
            'config' => $this->opensslConfig,
            'x509_extensions' => $profile,
            'digest_alg' => 'sha256',
        ], random_int(1, PHP_INT_MAX));

        if ($cert === false) {
            throw new RuntimeException('Certificate issuance failed.');
        }

        return ['cert' => $cert, 'key' => $key, 'pem' => $this->certificatePem($cert)];
    }

    protected function certificatePem(OpenSSLCertificate $cert): string
    {
        $pem = '';
        if (openssl_x509_export($cert, $pem) !== true) {
            throw new RuntimeException('Ephemeral certificate export failed.');
        }

        return $pem;
    }

    protected function encryptedKeyPem(OpenSSLAsymmetricKey $key, string $passphrase): string
    {
        $pem = '';
        if (openssl_pkey_export($key, $pem, $passphrase, ['config' => $this->opensslConfig]) !== true) {
            throw new RuntimeException('Ephemeral private-key export failed.');
        }

        return $pem;
    }

    protected function fingerprint(OpenSSLCertificate $cert): string
    {
        return strtolower((string) openssl_x509_fingerprint($cert, 'sha256'));
    }

    /**
     * Point the signing config at the given private key / passphrase / Root CA,
     * writing each to the external temp directory. Returns the Root CA path.
     */
    protected function configureSigning(OpenSSLAsymmetricKey $privateKey, string $passphrase, string $rootCaPem, string $disk = 'local'): string
    {
        $rootCaPath = $this->writeExternal('rootca-'.bin2hex(random_bytes(6)).'.pem', $rootCaPem);

        config([
            'signing.private_key_path' => $this->writeExternal('key-'.bin2hex(random_bytes(6)).'.pem', $this->encryptedKeyPem($privateKey, $passphrase)),
            'signing.passphrase_file_path' => $this->writeExternal('pass-'.bin2hex(random_bytes(6)).'.txt', $passphrase),
            'signing.root_ca_path' => $rootCaPath,
            'signing.certificate_disk' => $disk,
        ]);

        return $rootCaPath;
    }

    protected function writeExternal(string $name, string $contents): string
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.$name;
        $this->writeFileChecked($path, $contents);

        return $path;
    }

    protected function writeFileChecked(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Failed to write complete temp signing material.');
        }
    }

    protected function writeCertificateInput(string $pem): string
    {
        return $this->writeExternal('input-'.bin2hex(random_bytes(6)).'.pem', $pem);
    }

    // --- schema -------------------------------------------------------------

    private function assertInMemorySqlite(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite' || $connection->getConfig('driver') !== 'sqlite') {
            throw new RuntimeException('Refusing to build/drop schema: connection is not sqlite.');
        }

        if ($connection->getConfig('database') !== ':memory:' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Refusing to build/drop schema: database is not :memory:.');
        }

        $inMemory = false;
        foreach ($connection->select('PRAGMA database_list') as $row) {
            $file = $row->file ?? null;
            if (($row->name ?? null) === 'main' && ($file === '' || $file === null)) {
                $inMemory = true;
            }
        }

        if (! $inMemory) {
            throw new RuntimeException('Refusing to build/drop schema: PRAGMA did not confirm an in-memory database.');
        }
    }

    private function assertSchemaMutationSafe(): void
    {
        if (! $this->schemaSafe) {
            throw new RuntimeException('Refusing schema mutation: internal safety flag is not set.');
        }

        $this->assertInMemorySqlite();
    }

    private function buildSchema(): void
    {
        $this->assertSchemaMutationSafe();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->assertSchemaMutationSafe();
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose');
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['storage_disk', 'storage_path']);
        });

        $this->assertSchemaMutationSafe();
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('owner_customer_id')->nullable();
            $table->string('label');
            $table->string('subject_dn');
            $table->string('issuer_dn');
            $table->string('serial_number');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->string('thumbprint_sha256');
            $table->unsignedBigInteger('file_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // Mirror the real global-unique fingerprint so unique-conflict
            // recovery can be exercised deterministically.
            $table->unique('thumbprint_sha256');
        });
    }

    protected function safeDropSchema(): void
    {
        if (! $this->schemaSafe) {
            return;
        }

        $this->assertSchemaMutationSafe();
        Schema::dropIfExists('certificates');
        $this->assertSchemaMutationSafe();
        Schema::dropIfExists('files');
        $this->assertSchemaMutationSafe();
        Schema::dropIfExists('users');
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
