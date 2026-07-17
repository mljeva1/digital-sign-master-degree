<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Models\Certificate;
use App\Models\StoredFile;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Local-only provisioning command: environment gate, project-local write
 * boundary, shared-material reuse, no-overwrite guarantees, ownership-safe
 * compensation, and no secret in the output.
 */
final class ProvisionLocalSignerCommandTest extends SigningTestCase
{
    private string $signingRoot;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        // The command derives its trust boundary STRUCTURALLY from storage_path(),
        // so the whole storage path is relocated into the per-test temp directory.
        // The real boundary logic therefore runs unchanged while the developer's
        // actual signing material is never read, written, or deleted.
        $this->originalStoragePath = app()->storagePath();

        $storage = $this->tempDir.DIRECTORY_SEPARATOR.'storage';
        $private = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private';
        if (! mkdir($private, 0700, true) && ! is_dir($private)) {
            throw new \RuntimeException('Could not create the temporary storage tree.');
        }

        app()->useStoragePath($storage);

        $this->signingRoot = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'signing'.DIRECTORY_SEPARATOR.'local');
        config(['signing.local_material_path' => $this->signingRoot]);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalStoragePath)) {
            app()->useStoragePath($this->originalStoragePath);
        }

        parent::tearDown();
    }

    private function sharedFiles(): array
    {
        return ['test-root-ca.pem', 'test-root-ca-key.pem', 'test-signer-key.pem', 'test-signer-passphrase.txt'];
    }

    /**
     * Create a REAL directory reparse point: a junction on Windows (no privilege
     * required) and a directory symlink elsewhere. The test is only skipped when
     * the platform genuinely cannot create one, and never counts as passing
     * unless the redirect is proven to actually resolve to the target.
     */
    private function makeReparsePoint(string $link, string $target): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>&1');
            if (! is_dir($link)) {
                $this->markTestSkipped('A Windows junction could not be created in this environment.');
            }
        } elseif (@symlink($target, $link) === false) {
            $this->markTestSkipped('A directory symlink could not be created in this environment.');
        }

        // Proof the redirect is real; otherwise the assertions below are vacuous.
        $this->assertSame(realpath($target), realpath($link), 'the reparse point does not redirect to the external target');
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    public function test_refuses_non_local_environment(): void
    {
        $user = User::factory()->create();
        $original = app()['env'];
        app()['env'] = 'production';

        try {
            $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])
                ->expectsOutputToContain('only in the local or testing environment')
                ->assertExitCode(1);
        } finally {
            app()['env'] = $original;
        }

        $this->assertDirectoryDoesNotExist($this->signingRoot);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_runs_in_testing_environment_and_defaults_to_project_local_root(): void
    {
        $user = User::factory()->create();
        $this->assertTrue(app()->environment('testing'));

        // No --directory: the default must be the project-local signing root.
        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])
            ->expectsOutputToContain('Result: PROVISIONED')
            ->assertExitCode(0);

        foreach ($this->sharedFiles() as $name) {
            $this->assertFileExists($this->signingRoot.DIRECTORY_SEPARATOR.$name);
        }
        $this->assertFileExists($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-cert-user-'.$user->id.'.pem');
        $this->assertSame(1, Certificate::query()->where('owner_user_id', $user->id)->where('is_active', true)->count());
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenDirectoryProvider(): array
    {
        return [
            'public root' => ['public'],
            'repository root' => ['.'],
            'app' => ['app'],
            'resources' => ['resources'],
            'config' => ['config'],
            'database' => ['database'],
            'tests' => ['tests'],
            'vendor' => ['vendor'],
        ];
    }

    #[DataProvider('forbiddenDirectoryProvider')]
    public function test_refuses_directory_outside_the_signing_root(string $relative): void
    {
        $user = User::factory()->create();

        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => base_path($relative),
        ])->assertExitCode(1);

        $this->assertSame(0, Certificate::query()->count());
        foreach ($this->sharedFiles() as $name) {
            $this->assertFileDoesNotExist(base_path($relative).DIRECTORY_SEPARATOR.$name);
        }
    }

    public function test_refuses_external_absolute_directory(): void
    {
        $user = User::factory()->create();
        $outside = $this->tempDir.DIRECTORY_SEPARATOR.'outside-root';

        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => $outside,
        ])
            ->expectsOutputToContain('inside the project-local signing root')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($outside);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_refuses_traversal_segments(): void
    {
        $user = User::factory()->create();
        $escaped = dirname($this->signingRoot).DIRECTORY_SEPARATOR.'escaped';

        // A RELATIVE traversal: absolute input is refused earlier by its own
        // rule, so this is what actually exercises the traversal guard now.
        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => '..'.DIRECTORY_SEPARATOR.'escaped',
        ])
            ->expectsOutputToContain('traversal')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($escaped);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_refuses_child_junction_escape_below_the_verified_root(): void
    {
        $user = User::factory()->create();
        $escapeTarget = $this->tempDir.DIRECTORY_SEPARATOR.'escape-target';
        mkdir($escapeTarget, 0700, true);
        mkdir($this->signingRoot, 0700, true);

        $link = $this->signingRoot.DIRECTORY_SEPARATOR.'escape';
        $this->makeReparsePoint($link, $escapeTarget);

        // A RELATIVE subpath that looks contained but canonically escapes: the
        // containment check must run on the resolved path, not the lexical one.
        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => 'escape',
        ])
            ->expectsOutputToContain('inside the project-local signing root')
            ->assertExitCode(1);

        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame([], $this->entries($escapeTarget), 'nothing may be written into the escape target');
    }

    /**
     * The audited write-before-validation path: `escape` is a junction out of the
     * root and `--directory=escape/new` names a subdirectory that does not exist.
     * A recursive mkdir() before the containment check physically created
     * `<external target>/new` and only THEN refused. Refusing afterwards is not
     * enough — nothing may be written outside the canonical root at all.
     */
    public function test_nested_subpath_through_a_child_junction_never_writes_outside_the_root(): void
    {
        $user = User::factory()->create();

        $external = $this->tempDir.DIRECTORY_SEPARATOR.'escape-target';
        mkdir($external, 0700, true);
        file_put_contents($external.DIRECTORY_SEPARATOR.'marker.txt', 'foreign');
        $markerBefore = (string) file_get_contents($external.DIRECTORY_SEPARATOR.'marker.txt');

        mkdir($this->signingRoot, 0700, true);
        $link = $this->signingRoot.DIRECTORY_SEPARATOR.'escape';
        $this->makeReparsePoint($link, $external);

        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => 'escape/new',
        ])->assertExitCode(1);

        // The decisive assertion: the external child was never created.
        $this->assertDirectoryDoesNotExist($external.DIRECTORY_SEPARATOR.'new');
        $this->assertDirectoryDoesNotExist($link.DIRECTORY_SEPARATOR.'new');

        // The external target is byte-identical and gained nothing.
        $this->assertSame(['marker.txt'], $this->entries($external));
        $this->assertSame($markerBefore, (string) file_get_contents($external.DIRECTORY_SEPARATOR.'marker.txt'));

        // No material and no DB record anywhere.
        foreach ($this->sharedFiles() as $name) {
            $this->assertFileDoesNotExist($external.DIRECTORY_SEPARATOR.$name);
        }
        $this->assertFileDoesNotExist($external.DIRECTORY_SEPARATOR.'test-signer-cert-user-'.$user->id.'.pem');
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_nonexistent_plain_subpath_fails_closed_without_creating_it(): void
    {
        $user = User::factory()->create();
        mkdir($this->signingRoot, 0700, true);

        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => 'missing/subdir',
        ])->assertExitCode(1);

        // --directory never creates anything, not even inside the root.
        $this->assertDirectoryDoesNotExist($this->signingRoot.DIRECTORY_SEPARATOR.'missing');
        $this->assertSame([], $this->entries($this->signingRoot));
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_existing_canonical_subdirectory_is_accepted(): void
    {
        $user = User::factory()->create();
        $subdir = $this->signingRoot.DIRECTORY_SEPARATOR.'existing'.DIRECTORY_SEPARATOR.'subdir';
        mkdir($subdir, 0700, true);

        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => 'existing/subdir',
        ])
            ->expectsOutputToContain('Result: PROVISIONED')
            ->assertExitCode(0);

        foreach ($this->sharedFiles() as $name) {
            $this->assertFileExists($subdir.DIRECTORY_SEPARATOR.$name);
        }
        $this->assertSame(1, Certificate::query()->where('owner_user_id', $user->id)->where('is_active', true)->count());
    }

    public function test_existing_file_given_as_directory_is_refused(): void
    {
        $user = User::factory()->create();
        mkdir($this->signingRoot, 0700, true);
        file_put_contents($this->signingRoot.DIRECTORY_SEPARATOR.'notadir', 'x');

        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => 'notadir',
        ])->assertExitCode(1);

        $this->assertSame(0, Certificate::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeDirectoryInputProvider(): array
    {
        return [
            'unc path' => ['\\\\server\\share'],
            'unix absolute' => ['/etc/signing'],
            'drive absolute' => ['C:\\Windows\\Temp'],
            'parent traversal' => ['existing/../../escaped'],
            'current segment' => ['./existing'],
            'empty segment' => ['existing//subdir'],
        ];
    }

    #[DataProvider('unsafeDirectoryInputProvider')]
    public function test_refuses_unsafe_directory_input(string $raw): void
    {
        $user = User::factory()->create();

        $this->artisan('signing:provision-local-signer', [
            'user' => (string) $user->id,
            '--directory' => $raw,
        ])->assertExitCode(1);

        $this->assertSame(0, Certificate::query()->count());
    }

    /**
     * The exact case the audit found: the signing ROOT ITSELF is a junction /
     * reparse point out of the canonical tree. Trusting realpath() of the root
     * would silently promote the external target into the allowed boundary.
     */
    public function test_refuses_root_that_is_a_reparse_point_to_an_external_directory(): void
    {
        $user = User::factory()->create();

        $external = $this->tempDir.DIRECTORY_SEPARATOR.'external-root';
        mkdir($external, 0700, true);
        file_put_contents($external.DIRECTORY_SEPARATOR.'preexisting.txt', 'foreign');

        // Only `local` itself is the reparse point; its canonical parent is real.
        mkdir(dirname($this->signingRoot), 0700, true);
        $this->makeReparsePoint($this->signingRoot, $external);

        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])
            ->expectsOutputToContain('inside the project-local signing root')
            ->assertExitCode(1);

        // Refused BEFORE any material or DB record was generated.
        $this->assertSame(0, Certificate::query()->count());
        foreach ($this->sharedFiles() as $name) {
            $this->assertFileDoesNotExist($external.DIRECTORY_SEPARATOR.$name);
        }
        $this->assertFileDoesNotExist($external.DIRECTORY_SEPARATOR.'test-signer-cert-user-'.$user->id.'.pem');

        // The external target is untouched: nothing added, and compensation
        // never deleted a foreign file.
        $this->assertSame(['preexisting.txt'], $this->entries($external));
        $this->assertSame('foreign', (string) file_get_contents($external.DIRECTORY_SEPARATOR.'preexisting.txt'));
    }

    public function test_refuses_configured_root_repointed_away_from_the_expected_location(): void
    {
        $user = User::factory()->create();
        $elsewhere = $this->tempDir.DIRECTORY_SEPARATOR.'repointed';

        // Repointing the config must not move the trust boundary.
        config(['signing.local_material_path' => $elsewhere]);

        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])
            ->expectsOutputToContain('expected location')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($elsewhere);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_second_user_reuses_shared_material_and_both_match_one_key(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->artisan('signing:provision-local-signer', ['user' => (string) $first->id])
            ->expectsOutputToContain('CREATED (first run)')
            ->assertExitCode(0);

        $keyBefore = (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-key.pem');
        $caBefore = (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-root-ca.pem');

        $this->artisan('signing:provision-local-signer', ['user' => (string) $second->id])
            ->expectsOutputToContain('REUSED (existing)')
            ->assertExitCode(0);

        // Shared material is never regenerated or overwritten.
        $this->assertSame($keyBefore, (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-key.pem'));
        $this->assertSame($caBefore, (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-root-ca.pem'));

        // Each user has exactly one active certificate; both match the SINGLE
        // configured private key and are trusted by the SAME Root CA.
        $config = $this->signingConfig();
        $key = openssl_pkey_get_private($config->privateKeyPem(), $config->passphrase());
        $this->assertNotFalse($key);

        foreach ([$first, $second] as $user) {
            $cert = Certificate::query()->where('owner_user_id', $user->id)->where('is_active', true)->sole();
            $file = StoredFile::query()->findOrFail($cert->file_id);
            $x509 = openssl_x509_read(\Storage::disk($file->storage_disk)->get($file->storage_path));

            $this->assertNotFalse($x509);
            $this->assertTrue(openssl_x509_check_private_key($x509, $key), 'certificate must match the one configured key');
            $this->assertTrue(
                openssl_x509_checkpurpose($x509, X509_PURPOSE_SMIME_SIGN, [$config->rootCaPath()]) === true,
                'certificate must be trusted by the shared Root CA'
            );
            $this->assertSame(
                strtolower((string) openssl_x509_fingerprint($x509, 'sha256')),
                strtolower((string) $cert->thumbprint_sha256)
            );
        }

        // Distinct identities: different serials => different fingerprints.
        $this->assertNotSame(
            Certificate::query()->where('owner_user_id', $first->id)->value('thumbprint_sha256'),
            Certificate::query()->where('owner_user_id', $second->id)->value('thumbprint_sha256')
        );
    }

    public function test_second_run_for_same_user_refuses_existing_active_identity(): void
    {
        $user = User::factory()->create();

        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])->assertExitCode(0);
        $keyBytes = (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-key.pem');

        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])
            ->expectsOutputToContain('already has an active signer certificate')
            ->assertExitCode(1);

        $this->assertSame($keyBytes, (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-key.pem'));
        $this->assertSame(1, Certificate::query()->where('owner_user_id', $user->id)->count());
    }

    public function test_preexisting_user_certificate_file_is_never_overwritten(): void
    {
        $user = User::factory()->create();
        mkdir($this->signingRoot, 0700, true);
        $foreign = "FOREIGN MATERIAL - DO NOT TOUCH\n";
        $path = $this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-cert-user-'.$user->id.'.pem';
        file_put_contents($path, $foreign);

        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])
            ->expectsOutputToContain('never overwritten')
            ->assertExitCode(1);

        $this->assertSame($foreign, (string) file_get_contents($path));
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_registration_failure_removes_only_own_generated_files(): void
    {
        $user = User::factory()->create();
        mkdir($this->signingRoot, 0700, true);
        $foreignPath = $this->signingRoot.DIRECTORY_SEPARATOR.'unrelated-note.txt';
        file_put_contents($foreignPath, 'foreign file');

        // Break certificate storage so registration fails AFTER generation.
        config(['signing.certificate_disk' => 'public']);

        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])
            ->expectsOutputToContain('were removed')
            ->assertExitCode(1);

        foreach ($this->sharedFiles() as $name) {
            $this->assertFileDoesNotExist($this->signingRoot.DIRECTORY_SEPARATOR.$name);
        }
        $this->assertFileExists($foreignPath);
        $this->assertSame('foreign file', (string) file_get_contents($foreignPath));
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_compensation_never_deletes_shared_material_owned_by_an_earlier_run(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->artisan('signing:provision-local-signer', ['user' => (string) $first->id])->assertExitCode(0);
        $keyBytes = (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-key.pem');

        // Make the SECOND run fail at registration; it must not remove the shared
        // CA/key the first run created and still owns.
        config(['signing.certificate_disk' => 'public']);

        $this->artisan('signing:provision-local-signer', ['user' => (string) $second->id])
            ->assertExitCode(1);

        foreach ($this->sharedFiles() as $name) {
            $this->assertFileExists($this->signingRoot.DIRECTORY_SEPARATOR.$name, $name.' must survive a later run failure');
        }
        $this->assertSame($keyBytes, (string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-key.pem'));
        $this->assertFileDoesNotExist($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-cert-user-'.$second->id.'.pem');
        $this->assertSame(1, Certificate::query()->count());
    }

    public function test_output_never_contains_private_key_or_passphrase(): void
    {
        $user = User::factory()->create();

        $this->artisan('signing:provision-local-signer', ['user' => (string) $user->id])->assertExitCode(0);

        $passphrase = trim((string) file_get_contents($this->signingRoot.DIRECTORY_SEPARATOR.'test-signer-passphrase.txt'));
        $this->assertNotSame('', $passphrase);

        // Re-render the command output and assert nothing secret is present.
        $second = User::factory()->create();
        $this->artisan('signing:provision-local-signer', ['user' => (string) $second->id])
            ->doesntExpectOutputToContain($passphrase)
            ->doesntExpectOutputToContain('BEGIN PRIVATE KEY')
            ->doesntExpectOutputToContain('BEGIN ENCRYPTED PRIVATE KEY')
            ->doesntExpectOutputToContain($this->signingRoot)
            ->assertExitCode(0);
    }

    public function test_material_is_not_git_tracked(): void
    {
        // The real project-local root is covered by an explicit .gitignore rule
        // plus the pre-existing storage/app/private wildcard.
        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        $this->assertStringContainsString('/storage/app/private/signing/', $gitignore);
    }
}
