<?php

declare(strict_types=1);

namespace App\Services\Signing;

/**
 * The single canonical trust boundary for project-local dev signing material.
 *
 * The boundary is STRUCTURAL, never "whatever realpath() returns": the local
 * root must be the real, direct child named `local` of the real directory
 * `<storage>/app/private/signing`. Resolving a path and then trusting its own
 * realpath() as the root is exactly the escape this class exists to prevent —
 * a junction/symlink at `signing/local` pointing outside the private tree would
 * otherwise promote its external target into the "allowed" root.
 *
 * Authoritative reparse signal (same rule as SigningTempWorkspace): a path is a
 * redirect when is_link() reports it OR its realpath() diverges from the lexical
 * path. On Windows is_link() alone is not sufficient, so the parent identity of
 * the CANONICAL path is what decides.
 *
 * This class only resolves and verifies directories. It never reads, writes or
 * deletes signing material, and never echoes a path into user-facing output.
 */
final class LocalSigningRoot
{
    /** Required basename of the local root. */
    public const DIRECTORY_NAME = 'local';

    /** Required basename of the canonical parent. */
    private const PARENT_NAME = 'signing';

    /**
     * The lexical expectation, derived from the storage path rather than from
     * configuration, so a repointed config value can be detected instead of
     * silently becoming the new boundary.
     */
    public function expectedPath(): string
    {
        return $this->expectedParentPath().DIRECTORY_SEPARATOR.self::DIRECTORY_NAME;
    }

    public function expectedParentPath(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.self::PARENT_NAME);
    }

    /** True when a configured value is exactly the expected local root. */
    public function matchesExpectedPath(?string $configured): bool
    {
        return is_string($configured)
            && trim($configured) !== ''
            && $this->normalize($configured) === $this->normalize($this->expectedPath());
    }

    /**
     * Verify the existing local root without creating anything.
     *
     * @return string|null the canonical root, or null when it is absent,
     *                     redirected, or not the expected direct child
     */
    public function verified(): ?string
    {
        return $this->resolve(false);
    }

    /**
     * Verify the local root, creating the expected directories when absent.
     * Creation is re-verified before the path is returned, so a directory that
     * was created and then redirected is still refused.
     */
    public function ensure(): ?string
    {
        return $this->resolve(true);
    }

    private function resolve(bool $create): ?string
    {
        $parent = $this->canonicalParent($create);
        if ($parent === null) {
            return null;
        }

        $lexical = $this->expectedPath();

        if (! file_exists($lexical)) {
            if (! $create) {
                return null;
            }
            // Only ever the expected direct child; never a recursive/other path.
            if (! @mkdir($lexical, 0700) && ! is_dir($lexical)) {
                return null;
            }
        }

        // Re-verified AFTER any creation, not only before it.
        return $this->verifiedChild($lexical, $parent, self::DIRECTORY_NAME);
    }

    /**
     * The canonical `<storage>/app/private/signing` directory: it must be the
     * real direct child of the real `<storage>/app/private` tree, so `signing`
     * itself cannot be a junction out of the project.
     */
    private function canonicalParent(bool $create): ?string
    {
        $privateReal = realpath(storage_path('app'.DIRECTORY_SEPARATOR.'private'));
        if ($privateReal === false || ! is_dir($privateReal)) {
            return null;
        }

        $lexical = $this->expectedParentPath();

        if (! file_exists($lexical)) {
            if (! $create) {
                return null;
            }
            if (! @mkdir($lexical, 0700) && ! is_dir($lexical)) {
                return null;
            }
        }

        return $this->verifiedChild($lexical, $privateReal, self::PARENT_NAME);
    }

    /**
     * Verify one ALREADY-EXISTING path is the real, non-redirected direct child
     * $name of the canonical directory $canonicalParent. Creates nothing.
     *
     * Exposed so a caller walking a multi-segment subpath can apply the very
     * same structural rule to every segment instead of restating it.
     *
     * @return string|null the canonical child, or null when it is absent,
     *                     redirected, or not that direct child
     */
    public function verifyDirectChild(string $lexical, string $canonicalParent, string $name): ?string
    {
        return $this->verifiedChild($lexical, $canonicalParent, $name);
    }

    /**
     * Confirm $lexical is a real directory, not a symlink/junction/reparse
     * point, whose CANONICAL parent is exactly $canonicalParent and whose
     * basename is exactly $name.
     */
    private function verifiedChild(string $lexical, string $canonicalParent, string $name): ?string
    {
        if (! is_dir($lexical) || is_link($lexical)) {
            return null;
        }

        $real = realpath($lexical);
        if ($real === false || ! is_dir($real) || is_link($real)) {
            return null;
        }

        // The decisive check: a redirect moves the CANONICAL path out from under
        // the canonical parent, no matter what the lexical path looks like.
        // Independently sufficient — is_link() above is NOT relied upon, because
        // it can report false for a Windows junction.
        if ($this->normalize(dirname($real)) !== $this->normalize($canonicalParent)) {
            return null;
        }

        if ($this->normalize(basename($real)) !== $this->normalize($name)) {
            return null;
        }

        return $real;
    }

    public function isWithin(string $child, string $parent): bool
    {
        $child = $this->normalize($child);
        $parent = $this->normalize($parent);

        return $child === $parent || str_starts_with($child, $parent.'/');
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
