<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads and validates the external signing material configuration.
 *
 * Guarantees (throwing SIGNING_CONFIG_INVALID otherwise):
 *  - the private-key, passphrase-file and Root CA paths resolve to canonical,
 *    existing, readable regular files (returned canonically, not as the
 *    original alias/symlink);
 *  - the private-key and passphrase files live OUTSIDE the repository and any
 *    public directory/disk (case-insensitive containment on Windows);
 *  - the passphrase file is non-empty (only a single trailing line ending is
 *    stripped; every other whitespace/line-ending byte is preserved);
 *  - the certificate disk is configured, is not `public`, has no public
 *    visibility, a local disk root is not inside a public directory, and an
 *    isolated adapter can be instantiated from the validated config
 *    (fail-closed). S3 validation cannot prove external bucket-policy privacy;
 *    that remains a deployment control.
 *
 * No configured path, passphrase, or PEM content is exposed in any exception.
 */
final class SigningConfig
{
    public function validate(): void
    {
        $this->privateKeyPath();
        $this->passphrase();
        $this->rootCaPath();
        $this->certificateDisk();
    }

    public function privateKeyPath(): string
    {
        return $this->canonicalSecretFile('signing.private_key_path');
    }

    public function privateKeyPem(): string
    {
        return $this->readFile($this->privateKeyPath());
    }

    public function passphrase(): string
    {
        $path = $this->canonicalSecretFile('signing.passphrase_file_path');
        $passphrase = $this->stripSingleTrailingLineEnding($this->readFile($path));

        if ($passphrase === '') {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        return $passphrase;
    }

    public function rootCaPath(): string
    {
        // The Root CA is a public trust-anchor certificate; it need not live
        // outside the repository, but must resolve to a readable regular file.
        $raw = $this->requireConfiguredPath('signing.root_ca_path');

        return $this->canonicalReadableFile($raw);
    }

    public function rootCaPem(): string
    {
        return $this->readFile($this->rootCaPath());
    }

    public function certificateDisk(): string
    {
        return $this->certificateStorage()->disk;
    }

    public function certificateStorage(): ValidatedCertificateStorage
    {
        $disk = config('signing.certificate_disk');
        if (! is_string($disk) || trim($disk) === '') {
            $disk = 'local';
        }

        // Only schema-supported disk names are allowed (this also forbids the
        // framework's public disk).
        if (! in_array($disk, ['local', 's3'], true)) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        $diskConfig = config('filesystems.disks.'.$disk);
        if (! is_array($diskConfig)) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        if (($diskConfig['visibility'] ?? null) === 'public') {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        $driver = $diskConfig['driver'] ?? null;
        if (($disk === 'local' && $driver !== 'local') || ($disk === 's3' && $driver !== 's3')) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        if ($driver === 'local') {
            $diskConfig['root'] = $this->safeLocalRoot($diskConfig['root'] ?? null);
        }

        // Build from the validated config instead of using the manager's named
        // disk cache. The returned isolated adapter is the only adapter the
        // registrar uses for this operation.
        try {
            $filesystem = Storage::build($diskConfig);
        } catch (Throwable) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        return new ValidatedCertificateStorage($disk, $filesystem);
    }

    /**
     * Fail-closed local disk root: absolute, existing directory, canonically
     * resolvable, and never equal to or inside a public directory. No fallback
     * to the raw path when realpath() fails; the canonical root replaces the
     * configured value for storage.
     */
    private function safeLocalRoot(mixed $root): string
    {
        if (! is_string($root) || $root === '' || ! $this->isAbsolutePath($root)) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        // Inspect normalized path segments without changing the path passed to
        // realpath(). Exact traversal segments are forbidden even when they
        // would canonicalize to an otherwise safe directory.
        $segments = explode('/', str_replace('\\', '/', $root));
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        $real = realpath($root);
        if ($real === false || ! is_dir($real)) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        foreach ($this->publicRoots() as $publicRoot) {
            if ($this->isWithin($real, $publicRoot)) {
                throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
            }
        }

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/') {
            return true; // POSIX absolute
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return true; // Windows drive-absolute
        }

        return str_starts_with($path, '\\\\') || str_starts_with($path, '//'); // UNC
    }

    private function canonicalSecretFile(string $key): string
    {
        $raw = $this->requireConfiguredPath($key);
        $canonical = $this->canonicalReadableFile($raw);
        $this->assertOutsideRepositoryAndPublic($canonical);

        return $canonical;
    }

    private function requireConfiguredPath(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        return $value;
    }

    private function canonicalReadableFile(string $path): string
    {
        $real = realpath($path);

        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        return $real;
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
        }

        return $contents;
    }

    private function stripSingleTrailingLineEnding(string $value): string
    {
        if (str_ends_with($value, "\r\n")) {
            return substr($value, 0, -2);
        }

        if (str_ends_with($value, "\n") || str_ends_with($value, "\r")) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    private function assertOutsideRepositoryAndPublic(string $path): void
    {
        foreach ($this->forbiddenSecretRoots() as $root) {
            if ($this->isWithin($path, $root)) {
                throw RegistrationException::of(RegistrationException::CONFIG_INVALID);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function forbiddenSecretRoots(): array
    {
        return $this->realRoots([
            base_path(),
            public_path(),
            storage_path('app/public'),
            is_string(config('filesystems.disks.public.root')) ? config('filesystems.disks.public.root') : null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function publicRoots(): array
    {
        return $this->realRoots([
            public_path(),
            storage_path('app/public'),
            is_string(config('filesystems.disks.public.root')) ? config('filesystems.disks.public.root') : null,
        ]);
    }

    /**
     * @param  list<?string>  $roots
     * @return list<string>
     */
    private function realRoots(array $roots): array
    {
        $resolved = [];

        foreach (array_filter($roots) as $root) {
            $real = realpath($root);
            $resolved[] = $real !== false ? $real : $root;
        }

        return $resolved;
    }

    private function isWithin(string $child, string $parent): bool
    {
        $child = $this->normalizePath($child);
        $parent = $this->normalizePath($parent);

        return $child === $parent || str_starts_with($child, $parent.'/');
    }

    private function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        // Windows filesystems are case-insensitive.
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }
}
