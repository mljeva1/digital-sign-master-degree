<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use SplObjectStorage;
use Throwable;

/**
 * Creates and tears down random, canonical OS-temp workspaces for native CMS
 * operations that require real filenames.
 *
 * Capability model: `create()` returns a handle whose OBJECT IDENTITY is the
 * capability. The instance records the exact handle object (plus the canonical
 * root captured at creation) in a private SplObjectStorage registry. `discard()`
 * accepts only a handle this instance issued — a forged handle with the same
 * path, or a handle from another instance, is rejected. Path equality is never
 * sufficient. There is NO public API that removes an arbitrary path. After a
 * successful discard the capability is revoked, so replay fails closed.
 *
 * Root-junction cleanup boundary: immediately before the first scandir(), the
 * recorded canonical root is re-resolved with realpath() and must be byte-for-
 * byte identical to the root captured at creation. A mismatch, a missing root, a
 * junction/reparse redirect, or a canonicalization failure fails closed BEFORE
 * any scandir/chmod/unlink/rmdir. The captured canonical root is then the
 * immutable containment base for the entire cleanup: before every recursive
 * scandir the current directory is re-canonicalized and re-confirmed to belong
 * to that base. A directory entry whose realpath differs from its lexical path
 * (a junction/reparse point) or escapes the base is removed in place and never
 * descended into, so an external target/marker is never modified — this does not
 * rely on is_link() alone (a Windows junction need not report as a symlink).
 *
 * A process crash can still leave a residual temp directory that the OS or a
 * later sweep reclaims; this phase creates no persistence or reconciliation for
 * that residual.
 *
 * Non-final only so a test can override the canonicalization seam to prove the
 * root-mismatch guard fails closed before traversal.
 */
class SigningTempWorkspace
{
    private const NAME_PREFIX = 'm10-cms-';

    private const NAME_PATTERN = '/^m10-cms-[0-9a-f]{32}$/';

    /** @var SplObjectStorage<SigningWorkspaceHandle, string> handle → canonical root */
    private SplObjectStorage $capabilities;

    public function __construct()
    {
        $this->capabilities = new SplObjectStorage;
    }

    public function create(): SigningWorkspaceHandle
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false || ! is_dir($base)) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
        }

        $dir = $base.DIRECTORY_SEPARATOR.self::NAME_PREFIX.bin2hex(random_bytes(16));

        try {
            if (! mkdir($dir, 0700, true) || ! is_dir($dir)) {
                throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
            }
            @chmod($dir, 0700);
        } catch (DetachedCmsException $e) {
            throw $e;
        } catch (Throwable) {
            throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
        }

        $real = realpath($dir);
        if ($real === false || ! $this->isOwnableWorkspace($real, $base)) {
            $this->bestEffortRawRemove($dir);

            throw DetachedCmsException::of(DetachedCmsException::CMS_TEMP_WORKSPACE_FAILED);
        }

        $handle = new SigningWorkspaceHandle($real);
        // Capability = exact handle object identity; value = canonical root.
        $this->capabilities->attach($handle, $real);

        return $handle;
    }

    /**
     * Remove ONLY a workspace this exact instance issued to this exact handle.
     * Returns true only when the directory is confirmed gone.
     */
    public function discard(SigningWorkspaceHandle $handle): bool
    {
        // Capability check: exact object identity, not path equality.
        if (! $this->capabilities->contains($handle)) {
            return false;
        }

        $recordedRoot = $this->capabilities[$handle];

        $base = realpath(sys_get_temp_dir());
        if ($base === false || ! $this->isOwnableWorkspace($recordedRoot, $base)) {
            return false;
        }

        // Root-junction guard: re-resolve the recorded root right before any
        // filesystem traversal. It must still canonicalize to itself.
        $currentRoot = $this->canonicalize($recordedRoot);
        if ($currentRoot === false
            || $currentRoot !== $recordedRoot
            || ! is_dir($recordedRoot)
            || is_link($recordedRoot)) {
            return false; // missing / junction redirect / canonicalization change
        }

        try {
            // The recorded canonical root is the immutable containment base.
            $this->deleteContained($recordedRoot, $recordedRoot);
        } catch (Throwable) {
            // fall through to the confirmation check
        }

        $gone = ! is_dir($recordedRoot) && ! is_file($recordedRoot);
        if ($gone) {
            $this->capabilities->detach($handle); // revoke; replay now fails closed
        }

        return $gone;
    }

    /**
     * Canonicalization seam (realpath). Overridable only for tests that prove the
     * root-mismatch guard aborts before traversal.
     */
    protected function canonicalize(string $path): string|false
    {
        return realpath($path);
    }

    private function isOwnableWorkspace(string $path, string $base): bool
    {
        $parent = dirname($path);
        if ($this->normalize($parent) !== $this->normalize($base)) {
            return false;
        }

        if (preg_match(self::NAME_PATTERN, basename($path)) !== 1) {
            return false;
        }

        return is_dir($path) && ! is_link($path);
    }

    /**
     * Depth-first removal strictly inside the immutable canonical $base. Before
     * touching $dir it is re-canonicalized and re-confirmed within $base; a
     * directory entry that resolves elsewhere (junction/reparse/symlink) is
     * removed in place and never descended into.
     */
    private function deleteContained(string $base, string $dir): void
    {
        $canonical = $this->canonicalize($dir);
        if ($canonical === false
            || $canonical !== $dir
            || is_link($canonical)
            || ! $this->isWithin($canonical, $base)) {
            return; // never scandir/chmod/unlink/rmdir outside the base
        }

        foreach (scandir($canonical) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $canonical.DIRECTORY_SEPARATOR.$entry;

            // A symlink or a Windows junction/reparse point is NEVER followed.
            // is_link() (and even is_dir()) can BOTH report false for a junction,
            // so the authoritative signal is a realpath that diverges from the
            // lexical child path — or realpath failing outright.
            $realChild = $this->canonicalize($child);
            $isReparse = is_link($child) || $realChild === false || $realChild !== $child;

            if ($isReparse) {
                @rmdir($child);  // removes a junction / directory-symlink in place
                @unlink($child); // removes a file symlink; target is never touched

                continue;
            }

            // Genuine in-place entry ($realChild === $child), guaranteed within
            // the immutable base since $canonical is.
            if (is_dir($child)) {
                $this->deleteContained($base, $child);

                continue;
            }

            @chmod($child, 0600); // clear a possible read-only bit before unlink
            @unlink($child);
        }

        @rmdir($canonical);
    }

    private function bestEffortRawRemove(string $dir): void
    {
        // The directory was just created by us and is empty at this point.
        @unlink($dir);
        @rmdir($dir);
    }

    private function isWithin(string $child, string $parent): bool
    {
        $child = $this->normalize($child);
        $parent = $this->normalize($parent);

        return $child === $parent || str_starts_with($child, $parent.'/');
    }

    private function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }
}
