<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Models\Certificate;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Signing\SignerCertificateRegistrar;
use App\Services\Signing\SigningConfig;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Real native-OpenSSL registration + application persistence with deterministic
 * storage/database failure injection. NOT PostgreSQL constraint proofs (SQLite
 * hand-built schema). A true concurrent PostgreSQL insert race is NOT executed;
 * the seam-based tests prove the unique-conflict recovery control-flow only.
 */
final class SignerCertificateRegistrarTest extends SigningTestCase
{
    private function registrar(): SignerCertificateRegistrar
    {
        return app(SignerCertificateRegistrar::class);
    }

    /**
     * @return array{ca: array, signer: array, input: string}
     */
    private function validSetup(): array
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, 'v3_signer');
        $this->configureSigning($signer['key'], 'passphrase-123', $ca['pem']);

        return ['ca' => $ca, 'signer' => $signer, 'input' => $this->writeCertificateInput($signer['pem'])];
    }

    private function assertRegistrationFails(string $expectedCode, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected registration failure {$expectedCode}, but it succeeded.");
        } catch (RegistrationException $e) {
            $this->assertSame($expectedCode, $e->errorCode());
        }
    }

    private function seamRegistrar(Closure $onInsert): SignerCertificateRegistrar
    {
        return new class($this->signingConfig(), $onInsert) extends SignerCertificateRegistrar
        {
            private Closure $onInsert;

            public function __construct(SigningConfig $config, Closure $onInsert)
            {
                parent::__construct($config);
                $this->onInsert = $onInsert;
            }

            // Force the post-lock recheck to miss (simulate the "not yet
            // visible" side of a race); recovery still uses the real lookup.
            protected function existingByFingerprint(string $fingerprint): ?Certificate
            {
                return null;
            }

            protected function insertCertificateRecords(User $owner, string $disk, string $attemptPath, string $exportedPem, string $fingerprint, array $parsed, Carbon $validFrom, Carbon $validTo): Certificate
            {
                return ($this->onInsert)($owner, $disk, $attemptPath, $exportedPem, $fingerprint);
            }
        };
    }

    /**
     * Seed a valid winning certificate artefact (file + rows) for an owner.
     *
     * @param  array{cert: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey, pem: string}  $signer
     */
    private function seedWinner(int $ownerId, array $signer): string
    {
        $pem = $signer['pem'];
        $path = 'signing/certificates/user-'.$ownerId.'/winner-'.bin2hex(random_bytes(6)).'.pem';
        $this->putFileChecked($this->certificateFilesystem(), $path, $pem);

        $file = StoredFile::create([
            'purpose' => StoredFile::PURPOSE_CERTIFICATE, 'storage_disk' => 'local', 'storage_path' => $path,
            'original_filename' => 'signer-certificate.pem', 'mime_type' => 'application/x-pem-file',
            'size_bytes' => strlen($pem), 'sha256' => hash('sha256', $pem), 'created_by_user_id' => $ownerId,
        ]);
        Certificate::create([
            'owner_type' => Certificate::OWNER_TYPE_USER, 'owner_user_id' => $ownerId, 'owner_customer_id' => null,
            'label' => 'winner', 'subject_dn' => '/CN=w', 'issuer_dn' => '/CN=w', 'serial_number' => '1',
            'valid_from' => now()->subDay(), 'valid_to' => now()->addYear(),
            'thumbprint_sha256' => $this->fingerprint($signer['cert']), 'file_id' => $file->id, 'is_active' => true,
        ]);

        return $path;
    }

    private function fingerprintUniqueException(): QueryException
    {
        return $this->sqliteUniqueException('certificates.thumbprint_sha256');
    }

    private function sqliteUniqueException(string $target, bool $withRawDriverMessage = true): QueryException
    {
        $driverMessage = 'UNIQUE constraint failed: '.$target;
        $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 '.$driverMessage, 23000);
        if ($withRawDriverMessage) {
            $previous->errorInfo = ['23000', 19, $driverMessage];
        }

        return new QueryException('sqlite', 'insert into "certificates" ...', [], $previous);
    }

    private function postgresUniqueException(string $constraint, int $sqlState = 23505): QueryException
    {
        $previous = new PDOException(
            'SQLSTATE['.$sqlState.']: Unique violation: 7 ERROR: duplicate key value violates unique constraint "'.$constraint.'"',
            $sqlState,
        );

        return new QueryException('pgsql', 'insert into "certificates" ...', [], $previous);
    }

    private function classifiesAsFingerprintUnique(Throwable $e): bool
    {
        $registrar = new class($this->signingConfig()) extends SignerCertificateRegistrar
        {
            public function classify(Throwable $e): bool
            {
                return $this->isFingerprintUniqueViolation($e);
            }
        };

        return $registrar->classify($e);
    }

    private function unrelatedUniqueException(): QueryException
    {
        $driverMessage = 'UNIQUE constraint failed: files.storage_disk, files.storage_path';
        $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 '.$driverMessage, 23000);
        $previous->errorInfo = ['23000', 19, $driverMessage];

        return new QueryException('sqlite', 'insert into "files" ...', [], $previous);
    }

    // --- configuration guards -----------------------------------------------

    public function test_missing_configuration_is_rejected(): void
    {
        $user = User::factory()->create();
        config(['signing.private_key_path' => null, 'signing.passphrase_file_path' => null, 'signing.root_ca_path' => null]);

        $this->assertRegistrationFails(RegistrationException::CONFIG_INVALID, fn () => $this->registrar()->register($user, $this->tempDir.'/missing.pem'));
    }

    public function test_private_key_inside_repository_is_rejected(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $inRepo = base_path('storage/framework/m10-test-key-'.bin2hex(random_bytes(6)).'.pem');
        $this->writeFileChecked($inRepo, $this->encryptedKeyPem($setup['signer']['key'], 'x'));

        try {
            config(['signing.private_key_path' => $inRepo]);
            $this->assertRegistrationFails(RegistrationException::CONFIG_INVALID, fn () => $this->registrar()->register($user, $setup['input']));
        } finally {
            @unlink($inRepo);
        }
    }

    // --- native OpenSSL validation ------------------------------------------

    public function test_wrong_passphrase_is_rejected(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($signer['key'], 'correct-pass', $ca['pem']);
        $this->writeFileChecked((string) config('signing.passphrase_file_path'), 'wrong-pass');

        $this->assertRegistrationFails(RegistrationException::PRIVATE_KEY_LOAD_FAILED, fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($signer['pem'])));
    }

    public function test_certificate_key_mismatch_is_rejected(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($this->newKey(), 'p', $ca['pem']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_KEY_MISMATCH, fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($signer['pem'])));
    }

    public function test_untrusted_certificate_is_rejected(): void
    {
        $trustedCa = $this->newRootCa('Trusted Root');
        $otherCa = $this->newRootCa('Other Root');
        $signer = $this->issueCertificate($otherCa);
        $this->configureSigning($signer['key'], 'p', $trustedCa['pem']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_UNTRUSTED, fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($signer['pem'])));
    }

    public function test_certificate_with_incompatible_eku_is_rejected_as_untrusted(): void
    {
        $ca = $this->newRootCa();
        $wrongEku = $this->issueCertificate($ca, 'v3_signer_wrong_eku');
        $this->configureSigning($wrongEku['key'], 'p', $ca['pem']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_UNTRUSTED, fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($wrongEku['pem'])));
    }

    public function test_untrusted_and_key_mismatched_certificate_is_rejected_as_key_mismatch(): void
    {
        // A certificate that is simultaneously issued by a NON-trusted CA and
        // configured with a NON-matching private key. Registration precedence
        // asserts the private-key match BEFORE the trust check, so the stable
        // outcome is CERTIFICATE_KEY_MISMATCH (never CERTIFICATE_UNTRUSTED).
        $trustedCa = $this->newRootCa('Trusted Root');
        $otherCa = $this->newRootCa('Other Root');
        $signer = $this->issueCertificate($otherCa);
        $this->configureSigning($this->newKey(), 'p', $trustedCa['pem']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_KEY_MISMATCH, fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($signer['pem'])));
    }

    public function test_certificate_without_basic_constraints_is_rejected_precisely(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, 'v3_signer_no_basic_constraints');
        $this->configureSigning($signer['key'], 'p', $ca['pem']);

        $this->assertRegistrationFails(
            RegistrationException::CERTIFICATE_BASIC_CONSTRAINTS_INVALID,
            fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($signer['pem'])),
        );
    }

    public function test_not_yet_valid_certificate_is_rejected(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::now()->subDays(5));

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_NOT_YET_VALID, fn () => $this->registrar()->register($user, $setup['input']));
    }

    public function test_expired_certificate_is_rejected(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, 'v3_signer', 1);
        $this->configureSigning($signer['key'], 'p', $ca['pem']);
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::now()->addDays(5));

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_EXPIRED, fn () => $this->registrar()->register($user, $this->writeCertificateInput($signer['pem'])));
    }

    public function test_ca_certificate_is_rejected_as_signer(): void
    {
        $ca = $this->newRootCa();
        $caLike = $this->issueCertificate($ca, 'v3_ca');
        $this->configureSigning($caLike['key'], 'p', $ca['pem']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_IS_CA, fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($caLike['pem'])));
    }

    public function test_missing_digital_signature_usage_is_rejected(): void
    {
        $ca = $this->newRootCa();
        $noDs = $this->issueCertificate($ca, 'v3_signer_no_ds');
        $this->configureSigning($noDs['key'], 'p', $ca['pem']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_KEY_USAGE_INVALID, fn () => $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($noDs['pem'])));
    }

    // --- owner lifecycle under lock -----------------------------------------

    public function test_soft_deleted_owner_is_rejected(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_OWNER_UNAVAILABLE, fn () => $this->registrar()->register($user, $setup['input']));
    }

    public function test_stale_owner_model_is_rejected_after_soft_delete(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $stale = User::query()->findOrFail($user->id); // fetched while still present
        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_OWNER_UNAVAILABLE, fn () => $this->registrar()->register($stale, $setup['input']));
    }

    public function test_soft_delete_scope_cannot_hide_owner_before_explicit_locked_check(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $stale = User::query()->findOrFail($user->id);
        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);
        $globalScopes = User::getAllGlobalScopes();

        try {
            User::addGlobalScope(
                SoftDeletingScope::class,
                fn ($builder) => $builder->whereNull($builder->getModel()->qualifyColumn('deleted_at')),
            );
            $this->assertNull(User::query()->find($user->id));
            $this->assertRegistrationFails(
                RegistrationException::CERTIFICATE_OWNER_UNAVAILABLE,
                fn () => $this->registrar()->register($stale, $setup['input']),
            );
        } finally {
            User::setAllGlobalScopes($globalScopes);
        }
    }

    // --- successful registration & metadata ---------------------------------

    public function test_successful_registration_creates_single_active_certificate(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();

        $certificate = $this->registrar()->register($user, $setup['input']);

        $this->assertTrue($certificate->wasRecentlyCreated);
        $this->assertTrue($certificate->is_active);
        $this->assertSame(Certificate::OWNER_TYPE_USER, $certificate->owner_type);
        $this->assertSame($user->id, (int) $certificate->owner_user_id);
        $this->assertSame($this->fingerprint($setup['signer']['cert']), $certificate->thumbprint_sha256);
    }

    public function test_public_certificate_is_stored_on_private_disk_with_certificate_purpose(): void
    {
        $setup = $this->validSetup();
        $certificate = $this->registrar()->register(User::factory()->create(), $setup['input']);
        $file = StoredFile::findOrFail($certificate->file_id);

        $this->assertSame(StoredFile::PURPOSE_CERTIFICATE, $file->purpose);
        $this->assertSame('local', $file->storage_disk);
        $filesystem = $this->certificateFilesystem();
        $this->assertTrue($filesystem->exists($file->storage_path));

        $stored = $filesystem->get($file->storage_path);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $stored);
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertSame(strlen($stored), (int) $file->size_bytes);
        $this->assertSame(hash('sha256', $stored), $file->sha256);
    }

    public function test_pre_cached_named_adapter_cannot_override_canonical_root(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $oldRoot = $this->tempDir.DIRECTORY_SEPARATOR.'old-root';
        $canonicalRoot = $this->tempDir.DIRECTORY_SEPARATOR.'canonical-root';

        foreach ([$oldRoot, $canonicalRoot] as $directory) {
            if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Failed to create storage-root test directory.');
            }
        }

        config(['filesystems.disks.local.root' => $oldRoot]);
        Storage::disk('local'); // cache an adapter built from the stale root
        config(['filesystems.disks.local.root' => $canonicalRoot]);

        $certificate = $this->registrar()->register($user, $setup['input']);
        $path = StoredFile::findOrFail($certificate->file_id)->storage_path;
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $path);

        $this->assertFileExists($canonicalRoot.DIRECTORY_SEPARATOR.$relative);
        $this->assertFileDoesNotExist($oldRoot.DIRECTORY_SEPARATOR.$relative);
    }

    public function test_owner_metadata_and_fingerprint_are_correct(): void
    {
        $setup = $this->validSetup();
        $certificate = $this->registrar()->register(User::factory()->create(), $setup['input']);

        $this->assertStringContainsString('CN=', $certificate->subject_dn);
        $this->assertStringContainsString('CN=', $certificate->issuer_dn);
        $this->assertNotSame('', $certificate->serial_number);
        $this->assertSame($this->fingerprint($setup['signer']['cert']), $certificate->thumbprint_sha256);
    }

    public function test_input_bundle_stores_only_the_canonical_public_certificate(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($signer['key'], 'p', $ca['pem']);
        $bundle = $signer['pem']."\n".$this->encryptedKeyPem($signer['key'], 'p');

        $certificate = $this->registrar()->register(User::factory()->create(), $this->writeCertificateInput($bundle));
        $stored = $this->certificateFilesystem()->get(StoredFile::findOrFail($certificate->file_id)->storage_path);

        $this->assertSame(1, substr_count($stored, 'BEGIN CERTIFICATE'));
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
    }

    public function test_previous_active_certificate_is_deactivated(): void
    {
        $ca = $this->newRootCa();
        $user = User::factory()->create();

        $a = $this->issueCertificate($ca);
        $this->configureSigning($a['key'], 'p', $ca['pem']);
        $certA = $this->registrar()->register($user, $this->writeCertificateInput($a['pem']));

        $b = $this->issueCertificate($ca);
        $this->configureSigning($b['key'], 'p', $ca['pem']);
        $certB = $this->registrar()->register($user, $this->writeCertificateInput($b['pem']));

        $this->assertFalse($certA->fresh()->is_active);
        $this->assertTrue($certB->fresh()->is_active);
        $this->assertSame(1, Certificate::query()->where('owner_user_id', $user->id)->where('is_active', true)->count());
        $this->assertSame(2, Certificate::query()->where('owner_user_id', $user->id)->count());
    }

    public function test_each_registration_uses_a_unique_storage_path(): void
    {
        $ca = $this->newRootCa();
        $user = User::factory()->create();

        $a = $this->issueCertificate($ca);
        $this->configureSigning($a['key'], 'p', $ca['pem']);
        $pathA = StoredFile::findOrFail($this->registrar()->register($user, $this->writeCertificateInput($a['pem']))->file_id)->storage_path;

        $b = $this->issueCertificate($ca);
        $this->configureSigning($b['key'], 'p', $ca['pem']);
        $pathB = StoredFile::findOrFail($this->registrar()->register($user, $this->writeCertificateInput($b['pem']))->file_id)->storage_path;

        $this->assertNotSame($pathA, $pathB);
    }

    public function test_persistence_failure_after_deactivation_rolls_previous_active_state_back(): void
    {
        $ca = $this->newRootCa();
        $user = User::factory()->create();
        $firstSigner = $this->issueCertificate($ca);
        $this->configureSigning($firstSigner['key'], 'p', $ca['pem']);
        $first = $this->registrar()->register($user, $this->writeCertificateInput($firstSigner['pem']));

        $secondSigner = $this->issueCertificate($ca);
        $this->configureSigning($secondSigner['key'], 'p', $ca['pem']);
        Certificate::creating(fn () => throw new RuntimeException('injected failure after deactivation'));

        try {
            $this->assertRegistrationFails(
                RegistrationException::CERTIFICATE_PERSISTENCE_FAILED,
                fn () => $this->registrar()->register($user, $this->writeCertificateInput($secondSigner['pem'])),
            );
        } finally {
            app('events')->forget('eloquent.creating: '.Certificate::class);
        }

        $this->assertTrue($first->fresh()->is_active);
        $this->assertSame(1, Certificate::query()->where('is_active', true)->count());
        $this->assertSame(1, Certificate::query()->count());
        $this->assertSame(1, StoredFile::query()->count());
    }

    // --- idempotent integrity contract --------------------------------------

    public function test_registration_is_idempotent_for_a_valid_artifact(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();

        $first = $this->registrar()->register($user, $setup['input']);
        $second = $this->registrar()->register($user, $setup['input']);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(1, Certificate::query()->where('owner_user_id', $user->id)->count());
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_CERTIFICATE)->count());
    }

    public function test_idempotent_resolution_fails_on_wrong_storage_disk(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $certificate = $this->registrar()->register($user, $setup['input']);
        StoredFile::query()->whereKey($certificate->file_id)->update(['storage_disk' => 's3']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, fn () => $this->registrar()->register($user, $setup['input']));
    }

    public function test_idempotent_resolution_fails_on_wrong_size_bytes(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $certificate = $this->registrar()->register($user, $setup['input']);
        StoredFile::query()->whereKey($certificate->file_id)->update(['size_bytes' => 1]);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, fn () => $this->registrar()->register($user, $setup['input']));
    }

    public function test_idempotent_resolution_fails_on_wrong_physical_file_hash_metadata(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $certificate = $this->registrar()->register($user, $setup['input']);
        StoredFile::query()->whereKey($certificate->file_id)->update(['sha256' => str_repeat('0', 64)]);

        $this->assertRegistrationFails(
            RegistrationException::CERTIFICATE_PERSISTENCE_FAILED,
            fn () => $this->registrar()->register($user, $setup['input']),
        );
        $this->assertTrue($certificate->fresh()->is_active);
        $this->assertSame(1, Certificate::query()->count());
        $this->assertSame(1, StoredFile::query()->count());
    }

    public function test_idempotent_resolution_fails_when_hash_matches_but_certificate_fingerprint_differs(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $certificate = $this->registrar()->register($user, $setup['input']);
        $file = StoredFile::findOrFail($certificate->file_id);

        // Replace with a DIFFERENT valid certificate; keep file-hash/size consistent.
        $other = $this->issueCertificate($this->newRootCa('Other'), 'v3_signer');
        $this->putFileChecked($this->certificateFilesystem(), $file->storage_path, $other['pem']);
        $file->update(['sha256' => hash('sha256', $other['pem']), 'size_bytes' => strlen($other['pem'])]);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, fn () => $this->registrar()->register($user, $setup['input']));
    }

    public function test_idempotent_resolution_fails_on_unparseable_physical_pem(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $certificate = $this->registrar()->register($user, $setup['input']);
        $file = StoredFile::findOrFail($certificate->file_id);

        $garbage = 'not a certificate at all';
        $this->putFileChecked($this->certificateFilesystem(), $file->storage_path, $garbage);
        $file->update(['sha256' => hash('sha256', $garbage), 'size_bytes' => strlen($garbage)]);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, fn () => $this->registrar()->register($user, $setup['input']));
    }

    public function test_same_fingerprint_for_different_owner_fails_closed(): void
    {
        $setup = $this->validSetup();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->registrar()->register($owner, $setup['input']);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_OWNER_CONFLICT, fn () => $this->registrar()->register($intruder, $setup['input']));
        $this->assertSame(1, Certificate::query()->count());
    }

    // --- unique-conflict recovery control-flow (deterministic seam) ----------

    public function test_exact_postgresql_fingerprint_unique_classifier(): void
    {
        $this->assertTrue($this->classifiesAsFingerprintUnique(
            $this->postgresUniqueException('certificates_thumbprint_sha256_unique'),
        ));

        foreach ([
            'certificates_thumbprint_sha256_unique_shadow',
            'prefix_certificates_thumbprint_sha256_unique',
            'certificates_thumbprint_sha256_uniqu',
        ] as $constraint) {
            $this->assertFalse($this->classifiesAsFingerprintUnique(
                $this->postgresUniqueException($constraint),
            ));
        }

        $this->assertFalse($this->classifiesAsFingerprintUnique(
            $this->postgresUniqueException('certificates_thumbprint_sha256_unique', 23000),
        ));
    }

    public function test_exact_sqlite_fingerprint_unique_classifier(): void
    {
        $this->assertTrue($this->classifiesAsFingerprintUnique(
            $this->sqliteUniqueException('certificates.thumbprint_sha256'),
        ));

        foreach ([
            'certificates.thumbprint_sha256_shadow',
            'prefix_certificates.thumbprint_sha256',
            'certificates.thumbprint_sha256_suffix',
            'certificates.thumbprint_sha25',
            'certificates.thumbprint_sha256, certificates.owner_user_id',
            'certificates.thumbprint_sha256; certificates.owner_user_id',
            'other_certificates.thumbprint_sha256',
            'certificates.other_thumbprint',
        ] as $target) {
            $this->assertFalse($this->classifiesAsFingerprintUnique(
                $this->sqliteUniqueException($target),
            ));
        }

        $this->assertFalse($this->classifiesAsFingerprintUnique(
            $this->sqliteUniqueException('certificates.thumbprint_sha256', false),
        ));
    }

    public function test_unique_conflict_recovers_idempotently_for_same_owner(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($signer['key'], 'p', $ca['pem']);
        $user = User::factory()->create();

        // Winner is committed BEFORE registration; the seam forces the recheck
        // to miss it and then simulates the fingerprint unique violation.
        $winnerPath = $this->seedWinner((int) $user->id, $signer);
        $registrar = $this->seamRegistrar(fn () => throw $this->fingerprintUniqueException());

        $result = $registrar->register($user, $this->writeCertificateInput($signer['pem']));
        $winner = Certificate::query()->where('thumbprint_sha256', $this->fingerprint($signer['cert']))->firstOrFail();

        $this->assertSame($winner->id, $result->id);
        $this->assertFalse($result->wasRecentlyCreated);
        // Only this attempt's own path was cleaned up; the winner file remains.
        $this->assertSame([$winnerPath], $this->certificateFilesystem()->allFiles('signing/certificates/user-'.$user->id));
    }

    public function test_postgresql_unique_control_flow_recovers_only_exact_constraint(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($signer['key'], 'p', $ca['pem']);
        $user = User::factory()->create();
        $this->seedWinner((int) $user->id, $signer);
        $registrar = $this->seamRegistrar(
            fn () => throw $this->postgresUniqueException('certificates_thumbprint_sha256_unique'),
        );

        $result = $registrar->register($user, $this->writeCertificateInput($signer['pem']));

        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame($this->fingerprint($signer['cert']), $result->thumbprint_sha256);
    }

    public function test_unique_conflict_recovery_fails_closed_for_different_owner(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($signer['key'], 'p', $ca['pem']);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->seedWinner((int) $other->id, $signer);
        $registrar = $this->seamRegistrar(fn () => throw $this->fingerprintUniqueException());

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_OWNER_CONFLICT, fn () => $registrar->register($user, $this->writeCertificateInput($signer['pem'])));
    }

    public function test_unique_conflict_recovery_fails_closed_when_owner_becomes_unavailable(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($signer['key'], 'p', $ca['pem']);
        $user = User::factory()->create();

        $this->seedWinner((int) $user->id, $signer);

        // Owner is available at the first lock but unavailable on the recovery
        // re-lock (the second lockOwnerOrFail call).
        $registrar = new class($this->signingConfig()) extends SignerCertificateRegistrar
        {
            public int $lockCalls = 0;

            protected function existingByFingerprint(string $fingerprint): ?Certificate
            {
                return null;
            }

            protected function insertCertificateRecords(User $owner, string $disk, string $attemptPath, string $exportedPem, string $fingerprint, array $parsed, Carbon $validFrom, Carbon $validTo): Certificate
            {
                $driverMessage = 'UNIQUE constraint failed: certificates.thumbprint_sha256';
                $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 '.$driverMessage, 23000);
                $previous->errorInfo = ['23000', 19, $driverMessage];

                throw new QueryException('sqlite', 'insert into "certificates" ...', [], $previous);
            }

            protected function lockOwnerOrFail(int $ownerId): User
            {
                $this->lockCalls++;
                if ($this->lockCalls >= 2) {
                    throw RegistrationException::of(RegistrationException::CERTIFICATE_OWNER_UNAVAILABLE);
                }

                return parent::lockOwnerOrFail($ownerId);
            }
        };

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_OWNER_UNAVAILABLE, fn () => $registrar->register($user, $this->writeCertificateInput($signer['pem'])));
    }

    public function test_unrelated_unique_violation_does_not_trigger_recovery(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();

        $registrar = $this->seamRegistrar(function () {
            throw $this->unrelatedUniqueException();
        });

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, fn () => $registrar->register($user, $setup['input']));
    }

    public function test_unique_recovery_is_not_attempted_when_attempt_cleanup_is_incomplete(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca);
        $this->configureSigning($signer['key'], 'p', $ca['pem']);
        $user = User::factory()->create();
        $this->seedWinner((int) $user->id, $signer);

        $store = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists($path, $store));
        $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use (&$store): bool {
            $store[$path] = $contents;

            return true;
        });
        $disk->shouldReceive('get')->once()->andReturnUsing(fn (string $path): string => $store[$path]);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('build')->once()->andReturn($disk);
        $registrar = $this->seamRegistrar(fn () => throw $this->fingerprintUniqueException());

        try {
            $registrar->register($user, $this->writeCertificateInput($signer['pem']));
            $this->fail('Expected cleanup-incomplete persistence failure.');
        } catch (RegistrationException $e) {
            $this->assertSame(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, $e->errorCode());
            $this->assertTrue($e->compensationIncomplete());
        }

        $this->assertCount(1, $store);
        $this->assertSame(1, Certificate::query()->count());
        $this->assertSame(1, StoredFile::query()->count());
    }

    // --- storage / cleanup failure ------------------------------------------

    public function test_storage_put_failure_is_normalized(): void
    {
        $setup = $this->validSetup();
        $store = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists($path, $store));
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('build')->once()->andReturn($disk);

        $this->assertRegistrationFails(RegistrationException::CERTIFICATE_STORAGE_FAILED, fn () => $this->registrar()->register(User::factory()->create(), $setup['input']));
        $this->assertSame([], $store);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_put_false_after_partial_write_cleans_its_owned_path(): void
    {
        $setup = $this->validSetup();
        $store = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists($path, $store));
        $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use (&$store): bool {
            $store[$path] = substr($contents, 0, 32);

            return false;
        });
        $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$store): bool {
            unset($store[$path]);

            return true;
        });
        Storage::shouldReceive('build')->once()->andReturn($disk);

        $this->assertRegistrationFails(
            RegistrationException::CERTIFICATE_STORAGE_FAILED,
            fn () => $this->registrar()->register(User::factory()->create(), $setup['input']),
        );
        $this->assertSame([], $store);
        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, StoredFile::query()->count());
    }

    public function test_physical_write_mutation_is_rejected_and_cleaned_before_db_insert(): void
    {
        $setup = $this->validSetup();
        $store = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists($path, $store));
        $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use (&$store): bool {
            $store[$path] = substr($contents, 0, -16);

            return true;
        });
        $disk->shouldReceive('get')->once()->andReturnUsing(fn (string $path): string => $store[$path]);
        $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$store): bool {
            unset($store[$path]);

            return true;
        });
        Storage::shouldReceive('build')->once()->andReturn($disk);

        $this->assertRegistrationFails(
            RegistrationException::CERTIFICATE_STORAGE_FAILED,
            fn () => $this->registrar()->register(User::factory()->create(), $setup['input']),
        );
        $this->assertSame([], $store);
        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, StoredFile::query()->count());
    }

    public function test_preexisting_attempt_path_is_never_overwritten_or_deleted(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();
        $fingerprint = $this->fingerprint($setup['signer']['cert']);
        $fixedPath = 'signing/certificates/user-'.$user->id.'/'.$fingerprint.'-123e4567-e89b-42d3-a456-426614174000.pem';
        $store = [$fixedPath => 'preexisting-owner-data'];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->with($fixedPath)->andReturn(true);
        $disk->shouldNotReceive('put');
        $disk->shouldNotReceive('get');
        $disk->shouldNotReceive('delete');
        Storage::shouldReceive('build')->once()->andReturn($disk);
        $registrar = new class($this->signingConfig(), $fixedPath) extends SignerCertificateRegistrar
        {
            public function __construct(SigningConfig $config, private readonly string $fixedPath)
            {
                parent::__construct($config);
            }

            protected function uniqueAttemptPath(int $ownerId, string $fingerprint): string
            {
                return $this->fixedPath;
            }
        };

        $this->assertRegistrationFails(
            RegistrationException::CERTIFICATE_STORAGE_FAILED,
            fn () => $registrar->register($user, $setup['input']),
        );
        $this->assertSame([$fixedPath => 'preexisting-owner-data'], $store);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_db_failure_after_storage_write_deletes_only_its_own_path(): void
    {
        $ca = $this->newRootCa();
        $user = User::factory()->create();

        $a = $this->issueCertificate($ca);
        $this->configureSigning($a['key'], 'p', $ca['pem']);
        $pathA = StoredFile::findOrFail($this->registrar()->register($user, $this->writeCertificateInput($a['pem']))->file_id)->storage_path;

        $b = $this->issueCertificate($ca);
        $this->configureSigning($b['key'], 'p', $ca['pem']);
        StoredFile::creating(fn () => throw new RuntimeException('injected db failure'));

        try {
            $this->assertRegistrationFails(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, fn () => $this->registrar()->register($user, $this->writeCertificateInput($b['pem'])));
        } finally {
            app('events')->forget('eloquent.creating: '.StoredFile::class);
        }

        $this->assertSame([$pathA], $this->certificateFilesystem()->allFiles('signing/certificates/user-'.$user->id));
        $this->assertTrue($this->certificateFilesystem()->exists($pathA));
        $this->assertSame(1, Certificate::query()->count());
    }

    public function test_cleanup_delete_failure_produces_compensation_incomplete_signal(): void
    {
        $setup = $this->validSetup();
        $user = User::factory()->create();

        $store = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$store): bool {
            $store[$path] = $contents;

            return true;
        });
        $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists($path, $store));
        $disk->shouldReceive('get')->once()->andReturnUsing(fn (string $path): string => $store[$path]);
        $disk->shouldReceive('delete')->andReturn(false); // file stays after failed delete
        Storage::shouldReceive('build')->once()->andReturn($disk);

        StoredFile::creating(fn () => throw new RuntimeException('injected db failure'));

        try {
            $this->registrar()->register($user, $setup['input']);
            $this->fail('Expected a persistence failure.');
        } catch (RegistrationException $e) {
            $this->assertSame(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, $e->errorCode());
            $this->assertTrue($e->compensationIncomplete());
        } finally {
            app('events')->forget('eloquent.creating: '.StoredFile::class);
        }

        $this->assertCount(1, $store); // written file still present (orphan for reconciliation)
        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, StoredFile::query()->count());
    }
}
