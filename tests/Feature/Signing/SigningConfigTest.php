<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

/**
 * Config-level guards: schema-supported certificate disk, fail-closed local
 * disk root, canonical secret paths, and single-trailing-line-ending passphrase
 * handling. No PostgreSQL involved. Disk names stay within the schema-supported
 * set (local|s3); storage backing is faked, never renamed to an unsupported
 * disk.
 */
final class SigningConfigTest extends SigningTestCase
{
    private function expectConfigInvalid(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected SIGNING_CONFIG_INVALID.');
        } catch (RegistrationException $e) {
            $this->assertSame(RegistrationException::CONFIG_INVALID, $e->errorCode());
        }
    }

    // --- certificate disk name ---------------------------------------------

    public function test_disk_named_public_is_rejected(): void
    {
        config(['signing.certificate_disk' => 'public']);
        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    public function test_disk_name_outside_local_or_s3_is_rejected(): void
    {
        config(['signing.certificate_disk' => 'weird-disk']);
        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    // --- local disk root (fail-closed) -------------------------------------

    public function test_relative_local_root_is_rejected(): void
    {
        config(['filesystems.disks.local.root' => 'public/certificates', 'signing.certificate_disk' => 'local']);
        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    public function test_nonexistent_local_root_with_traversal_is_rejected(): void
    {
        config([
            'filesystems.disks.local.root' => base_path('does-not-exist-'.bin2hex(random_bytes(6)).'/../public'),
            'signing.certificate_disk' => 'local',
        ]);
        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    public function test_existing_absolute_local_root_with_parent_traversal_segment_is_rejected(): void
    {
        $alias = $this->tempDir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.basename($this->tempDir);

        $this->assertDirectoryExists($alias);
        config(['filesystems.disks.local.root' => $alias, 'signing.certificate_disk' => 'local']);

        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    public function test_existing_absolute_local_root_with_current_directory_segment_is_rejected(): void
    {
        $alias = $this->tempDir.DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR;

        $this->assertDirectoryExists($alias);
        config(['filesystems.disks.local.root' => $alias, 'signing.certificate_disk' => 'local']);

        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    public function test_existing_local_root_with_legitimate_dotted_segment_is_supported(): void
    {
        $root = $this->tempDir.DIRECTORY_SEPARATOR.'.storage'.DIRECTORY_SEPARATOR.'cert..backup'.DIRECTORY_SEPARATOR.'v1.2';
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Failed to create dotted storage-root test directory.');
        }

        config(['filesystems.disks.local.root' => $root, 'signing.certificate_disk' => 'local']);

        $this->assertSame('local', $this->signingConfig()->certificateDisk());
    }

    public function test_existing_local_root_inside_public_is_rejected(): void
    {
        $root = public_path('m10certs-'.bin2hex(random_bytes(6)));
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Failed to create public-root test directory.');
        }

        try {
            config(['filesystems.disks.local.root' => $root, 'signing.certificate_disk' => 'local']);
            $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
        } finally {
            @rmdir($root);
        }
    }

    public function test_disk_with_public_visibility_is_rejected(): void
    {
        config([
            'filesystems.disks.local.visibility' => 'public',
            'signing.certificate_disk' => 'local',
        ]);
        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    public function test_safe_existing_private_local_root_is_supported(): void
    {
        // setUp already pins the local root to the existing temp directory.
        config(['signing.certificate_disk' => 'local']);
        $this->assertSame('local', $this->signingConfig()->certificateDisk());
    }

    public function test_s3_disk_without_public_visibility_is_supported(): void
    {
        $isolated = $this->certificateFilesystem();
        Storage::shouldReceive('build')
            ->once()
            ->with(Mockery::on(fn (array $config): bool => ($config['driver'] ?? null) === 's3'))
            ->andReturn($isolated);
        config(['signing.certificate_disk' => 's3']);
        $this->assertSame('s3', $this->signingConfig()->certificateDisk());
    }

    public function test_s3_disk_with_explicit_public_visibility_is_rejected(): void
    {
        config([
            'signing.certificate_disk' => 's3',
            'filesystems.disks.s3.visibility' => 'public',
        ]);

        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    public function test_adapter_build_exception_is_normalized(): void
    {
        Storage::shouldReceive('build')->once()->andThrow(new RuntimeException('unsafe raw adapter detail'));

        $this->expectConfigInvalid(fn () => $this->signingConfig()->certificateDisk());
    }

    // --- passphrase trimming ------------------------------------------------

    public function test_single_trailing_line_ending_is_stripped(): void
    {
        foreach (["secret\n", "secret\r\n", "secret\r"] as $raw) {
            config(['signing.passphrase_file_path' => $this->writeExternal('pp-'.bin2hex(random_bytes(6)).'.txt', $raw)]);
            $this->assertSame('secret', $this->signingConfig()->passphrase());
        }
    }

    public function test_internal_whitespace_and_extra_line_endings_are_preserved(): void
    {
        config(['signing.passphrase_file_path' => $this->writeExternal('pp-multi.txt', "a b\tc \n\n")]);
        $this->assertSame("a b\tc \n", $this->signingConfig()->passphrase());

        config(['signing.passphrase_file_path' => $this->writeExternal('pp-spaces.txt', "  spaced  \n")]);
        $this->assertSame('  spaced  ', $this->signingConfig()->passphrase());
    }

    public function test_empty_passphrase_file_is_rejected(): void
    {
        config(['signing.passphrase_file_path' => $this->writeExternal('pp-empty.txt', "\n")]);
        $this->expectConfigInvalid(fn () => $this->signingConfig()->passphrase());
    }

    // --- canonical path containment -----------------------------------------

    public function test_alias_path_resolving_into_the_repository_is_rejected(): void
    {
        $real = base_path('storage/framework/m10-alias-'.bin2hex(random_bytes(6)).'.pem');
        $this->writeFileChecked($real, "dummy\n");
        $alias = base_path('storage/framework/../framework/'.basename($real));

        try {
            config(['signing.private_key_path' => $alias]);
            $this->expectConfigInvalid(fn () => $this->signingConfig()->privateKeyPath());
        } finally {
            @unlink($real);
        }
    }
}
