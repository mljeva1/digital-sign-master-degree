<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Services\Signing\SigningTempWorkspace;
use App\Services\Signing\SigningWorkspaceHandle;
use PHPUnit\Framework\TestCase;

/**
 * Cleanup containment + capability rules: the handle object identity is an
 * unforgeable capability (a same-path forgery, a foreign instance, or a replay
 * after discard are all rejected); the recorded canonical root is re-verified
 * before any traversal; and deletion never follows a directory symlink/junction
 * out of the workspace.
 */
final class SigningTempWorkspaceTest extends TestCase
{
    public function test_there_is_no_public_arbitrary_path_removal_api(): void
    {
        // The former public remove(string $dir) API must be gone; discard only
        // accepts a handle this instance issued.
        $this->assertFalse(method_exists(SigningTempWorkspace::class, 'remove'));

        $ref = new \ReflectionMethod(SigningTempWorkspace::class, 'discard');
        $param = $ref->getParameters()[0];
        $this->assertSame(SigningWorkspaceHandle::class, (string) $param->getType());
    }

    public function test_create_returns_a_canonical_named_workspace(): void
    {
        $workspace = new SigningTempWorkspace;
        $handle = $workspace->create();

        $this->assertDirectoryExists($handle->path());
        $this->assertMatchesRegularExpression('/m10-cms-[0-9a-f]{32}$/', $handle->path());
        $this->assertSame(realpath(sys_get_temp_dir()), dirname($handle->path()));

        $this->assertTrue($workspace->discard($handle));
        $this->assertDirectoryDoesNotExist($handle->path());
    }

    public function test_only_the_creating_instance_can_discard(): void
    {
        $creator = new SigningTempWorkspace;
        $handle = $creator->create();

        $foreign = new SigningTempWorkspace;
        $this->assertFalse($foreign->discard($handle));
        $this->assertDirectoryExists($handle->path()); // untouched

        $this->assertTrue($creator->discard($handle));
        $this->assertDirectoryDoesNotExist($handle->path());
    }

    public function test_forged_handle_with_same_path_is_rejected(): void
    {
        $workspace = new SigningTempWorkspace;
        $legit = $workspace->create();
        // A new handle object carrying the exact legitimate path is NOT the
        // capability — only the originally-issued object is accepted.
        $forged = new SigningWorkspaceHandle($legit->path());

        try {
            $this->assertFalse($workspace->discard($forged));
            $this->assertDirectoryExists($legit->path()); // untouched
        } finally {
            $this->assertTrue($workspace->discard($legit));
        }
    }

    public function test_replay_after_discard_fails_closed(): void
    {
        $workspace = new SigningTempWorkspace;
        $handle = $workspace->create();

        $this->assertTrue($workspace->discard($handle));
        // Capability revoked on success; re-using the same handle must fail.
        $this->assertFalse($workspace->discard($handle));
    }

    public function test_root_canonicalization_mismatch_aborts_before_any_traversal(): void
    {
        $workspace = new class extends SigningTempWorkspace
        {
            public bool $armed = false;

            protected function canonicalize(string $path): string|false
            {
                // Once armed, simulate the recorded root now resolving elsewhere
                // (as if replaced by a junction/reparse point) so discard must
                // fail closed BEFORE any scandir/unlink/rmdir.
                return $this->armed ? $path.'-REDIRECTED' : realpath($path);
            }
        };
        $handle = $workspace->create();
        $marker = $handle->path().DIRECTORY_SEPARATOR.'marker.txt';
        file_put_contents($marker, 'present');

        $workspace->armed = true;
        $this->assertFalse($workspace->discard($handle));

        // Deletion never started: the workspace and its marker are intact.
        $this->assertFileExists($marker);
        $this->assertDirectoryExists($handle->path());

        // Disarm and clean up for real.
        $workspace->armed = false;
        $this->assertTrue($workspace->discard($handle));
        $this->assertDirectoryDoesNotExist($handle->path());
    }

    public function test_redacted_handle_never_exposes_the_path(): void
    {
        $workspace = new SigningTempWorkspace;
        $handle = $workspace->create();

        try {
            $json = json_encode($handle);
            $this->assertIsString($json);
            $this->assertStringNotContainsString($handle->path(), $json);
            $this->assertStringNotContainsString('m10-cms-', $json);

            $debug = print_r($handle, true);
            $this->assertStringNotContainsString(basename($handle->path()), $debug);

            // Direct var_export must not expose the path either (sidecar state).
            $this->assertStringNotContainsString(basename($handle->path()), var_export($handle, true));

            // Serialization is refused with a neutral message.
            try {
                serialize($handle);
                $this->fail('Expected serialization to be refused.');
            } catch (\Throwable $e) {
                $this->assertStringNotContainsString(basename($handle->path()), $e->getMessage());
            }
        } finally {
            $workspace->discard($handle);
        }
    }

    public function test_cleanup_does_not_follow_a_windows_junction(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Junctions are a Windows-only mechanism.');
        }

        $workspace = new SigningTempWorkspace;
        $handle = $workspace->create();

        $external = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m10-ext-'.bin2hex(random_bytes(8));
        mkdir($external, 0700, true);
        $externalFile = $external.DIRECTORY_SEPARATOR.'keep.txt';
        file_put_contents($externalFile, 'must survive');

        $link = $handle->path().DIRECTORY_SEPARATOR.'junction';
        $out = [];
        $code = 1;
        @exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($external).' 2>&1', $out, $code);

        if ($code !== 0 || realpath($link) === false || realpath($link) !== realpath($external)) {
            $workspace->discard($handle);
            @rmdir($link);
            @unlink($externalFile);
            @rmdir($external);
            $this->markTestSkipped('A Windows junction could not be created in this environment.');
        }

        try {
            // The junction really exists and redirects to the external target.
            $this->assertSame(realpath($external), realpath($link), 'junction was not created');
            // Pre-cleanup: the external marker and its content are present.
            $this->assertFileExists($externalFile);
            $this->assertSame('must survive', file_get_contents($externalFile));

            $this->assertTrue($workspace->discard($handle));

            // Post-cleanup: the workspace and the junction entry are gone.
            $this->assertDirectoryDoesNotExist($handle->path());
            $this->assertFalse(file_exists($link), 'junction entry still exists after cleanup');
            // Post-cleanup: the junction TARGET and its marker are untouched.
            $this->assertDirectoryExists($external);
            $this->assertFileExists($externalFile);
            $this->assertSame('must survive', file_get_contents($externalFile));
        } finally {
            @rmdir($link);
            @unlink($externalFile);
            @rmdir($external);
        }
    }

    public function test_cleanup_does_not_follow_a_directory_symlink(): void
    {
        $workspace = new SigningTempWorkspace;
        $handle = $workspace->create();

        // External target OUTSIDE the workspace, holding a file that must survive.
        $external = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m10-ext-'.bin2hex(random_bytes(8));
        mkdir($external, 0700, true);
        $externalFile = $external.DIRECTORY_SEPARATOR.'keep.txt';
        file_put_contents($externalFile, 'must survive');

        $link = $handle->path().DIRECTORY_SEPARATOR.'link-to-external';
        $linked = @symlink($external, $link);

        if ($linked !== true) {
            // Symlink creation is unprivileged-restricted on this platform.
            $workspace->discard($handle);
            @unlink($externalFile);
            @rmdir($external);
            $this->markTestSkipped('Symbolic links are not creatable in this environment.');
        }

        try {
            $this->assertTrue($workspace->discard($handle));
            $this->assertDirectoryDoesNotExist($handle->path());
            // The symlink target and its file must be untouched.
            $this->assertFileExists($externalFile);
            $this->assertSame('must survive', file_get_contents($externalFile));
        } finally {
            @unlink($externalFile);
            @rmdir($external);
        }
    }
}
