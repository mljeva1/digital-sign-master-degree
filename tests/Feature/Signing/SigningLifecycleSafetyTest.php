<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use RuntimeException;

final class SigningLifecycleSafetyTest extends PhpUnitTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_rebound_file_database_blocks_drop_but_still_runs_temp_and_parent_cleanup(): void
    {
        $probe = new SigningLifecycleProbe('placeholder');
        $unsafeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m10-lifecycle-'.bin2hex(random_bytes(8));
        if (! mkdir($unsafeDirectory, 0700, true) && ! is_dir($unsafeDirectory)) {
            throw new RuntimeException('Failed to create lifecycle-test directory.');
        }
        $databasePath = $unsafeDirectory.DIRECTORY_SEPARATOR.'unsafe.sqlite';
        if (file_put_contents($databasePath, '') !== 0) {
            throw new RuntimeException('Failed to create lifecycle-test database.');
        }

        try {
            $probe->bootProbe();
            $probeTempDirectory = $probe->tempDirectory();

            config([
                'database.default' => 'unsafe-signing-lifecycle',
                'database.connections.unsafe-signing-lifecycle' => [
                    'driver' => 'sqlite',
                    'database' => $databasePath,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
            ]);
            DB::purge('unsafe-signing-lifecycle');
            DB::setDefaultConnection('unsafe-signing-lifecycle');
            DB::statement('CREATE TABLE sentinel (id INTEGER PRIMARY KEY)');

            try {
                $probe->shutdownProbe();
                $this->fail('Expected fail-closed teardown after connection rebind.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('database is not :memory:', $e->getMessage());
            }

            $this->assertTrue($probe->parentTearDownAttempted);
            $this->assertDirectoryDoesNotExist($probeTempDirectory);

            $pdo = new PDO('sqlite:'.$databasePath);
            $sentinelCount = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sentinel'")?->fetchColumn();
            $this->assertSame(1, (int) $sentinelCount);
            $pdo = null;
        } finally {
            if (is_file($databasePath) && ! unlink($databasePath)) {
                throw new RuntimeException('Failed to remove lifecycle-test database.');
            }
            if (is_dir($unsafeDirectory) && ! rmdir($unsafeDirectory)) {
                throw new RuntimeException('Failed to remove lifecycle-test directory.');
            }
        }
    }
}

final class SigningLifecycleProbe extends SigningTestCase
{
    public bool $parentTearDownAttempted = false;

    public function placeholder(): void {}

    public function bootProbe(): void
    {
        parent::setUp();
    }

    public function shutdownProbe(): void
    {
        parent::tearDown();
    }

    public function tempDirectory(): string
    {
        return $this->tempDir;
    }

    protected function tearDownParent(): void
    {
        $this->parentTearDownAttempted = true;
        DB::purge('unsafe-signing-lifecycle');
        parent::tearDownParent();
    }
}
