<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Domain\CertificateRequests\IssuanceFailureCode;
use App\Exceptions\CertificateRequests\IssuanceException;
use App\Exceptions\CertificateRequests\TransientIssuanceException;
use App\Services\Signing\AttemptCertificateArtifact;
use App\Services\Signing\LocalSignerCertificateIssuanceService;
use App\Services\Signing\LocalSigningRoot;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * M14 Phase B — the shared leaf-issuance engine's worker-safe surface, with real
 * native OpenSSL: exclusive-create ownership (P2-2), create-race loser reuse,
 * ownership-scoped cleanup, per-file shared-material safety (P2-4), and the leaf
 * profile.
 */
final class LocalSignerIssuanceServiceTest extends IssuanceWorkerTestCase
{
    private function service(): LocalSignerCertificateIssuanceService
    {
        return app(LocalSignerCertificateIssuanceService::class);
    }

    private function attemptPath(int $requestId, string $attemptId): string
    {
        return $this->signingRoot.DIRECTORY_SEPARATOR.'issuance'.DIRECTORY_SEPARATOR.'req-'.$requestId.'-att-'.$attemptId.'.pem';
    }

    // --- fail-closed root -----------------------------------------------------

    public function test_worker_issuance_fails_closed_without_a_signing_root(): void
    {
        try {
            $this->service()->issueAttemptCertificate(1, (string) Str::uuid());
            $this->fail('Issuance must fail closed without a signing root.');
        } catch (IssuanceException $e) {
            $this->assertSame(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE, $e->failureCode());
        }
    }

    public function test_worker_never_recreates_missing_shared_material(): void
    {
        $this->bootstrapSigningRoot();
        @unlink($this->signingRoot.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_SIGNER_KEY);

        try {
            $this->service()->issueAttemptCertificate(5, (string) Str::uuid());
            $this->fail('The worker must not recreate shared material.');
        } catch (IssuanceException $e) {
            $this->assertSame(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE, $e->failureCode());
        }

        $this->assertFileDoesNotExist($this->signingRoot.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_SIGNER_KEY);
    }

    // --- P2-2 create-only ownership & race ------------------------------------

    public function test_first_invocation_is_the_create_winner(): void
    {
        $this->bootstrapSigningRoot();

        $artifact = $this->service()->issueAttemptCertificate(42, (string) Str::uuid());

        $this->assertInstanceOf(AttemptCertificateArtifact::class, $artifact);
        $this->assertTrue($artifact->createdByCurrentInvocation, 'the first invocation wins the exclusive create');
    }

    public function test_second_invocation_reuses_the_winner_artifact_without_recreating(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        $winner = $this->service()->issueAttemptCertificate(42, $attemptId);
        $bytes = file_get_contents($this->attemptPath(42, $attemptId));

        $loser = $this->service()->issueAttemptCertificate(42, $attemptId);

        $this->assertFalse($loser->createdByCurrentInvocation, 'a create-race loser must not claim ownership');
        $this->assertSame($winner->pem, $loser->pem, 'the loser reuses the winner certificate');
        $this->assertSame($bytes, file_get_contents($this->attemptPath(42, $attemptId)), 'the winner artefact is never regenerated');
    }

    public function test_a_loser_never_deletes_the_winner_artifact(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        $this->service()->issueAttemptCertificate(42, $attemptId); // winner writes the file
        $loser = $this->service()->issueAttemptCertificate(42, $attemptId); // created=false

        // A loser's cleanup attempt must be a pure no-op that never deletes.
        $this->assertTrue($this->service()->discardOwnedArtefact($loser));
        $this->assertFileExists($this->attemptPath(42, $attemptId), 'a loser must never delete the winner artefact');
    }

    public function test_owner_cleanup_removes_only_its_own_reverified_artifact(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        $winner = $this->service()->issueAttemptCertificate(7, $attemptId);
        $this->assertFileExists($this->attemptPath(7, $attemptId));

        $this->assertTrue($this->service()->discardOwnedArtefact($winner));
        $this->assertFileDoesNotExist($this->attemptPath(7, $attemptId));

        // Shared material is untouched.
        foreach ([
            LocalSignerCertificateIssuanceService::FILE_ROOT_CA,
            LocalSignerCertificateIssuanceService::FILE_ROOT_CA_KEY,
            LocalSignerCertificateIssuanceService::FILE_SIGNER_KEY,
            LocalSignerCertificateIssuanceService::FILE_PASSPHRASE,
        ] as $name) {
            $this->assertFileExists($this->signingRoot.DIRECTORY_SEPARATOR.$name);
        }
    }

    public function test_owner_cleanup_does_not_delete_a_changed_artifact(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();
        $winner = $this->service()->issueAttemptCertificate(7, $attemptId);

        // The bytes changed since creation → identity no longer matches → not ours.
        file_put_contents($this->attemptPath(7, $attemptId), 'tampered');

        $this->assertTrue($this->service()->discardOwnedArtefact($winner));
        $this->assertFileExists($this->attemptPath(7, $attemptId), 'a changed artefact is not deleted');
    }

    public function test_stably_truncated_existing_artifact_is_terminal_not_transient_without_unlink(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        // A STABLE truncated artefact with NO active writer (no lock held): this
        // must be a terminal, fail-closed failure — never a transient retry loop —
        // and must never be overwritten or deleted.
        @mkdir(dirname($this->attemptPath(1, $attemptId)), 0700, true);
        $truncated = '-----BEGIN CERTIFICATE-----';
        file_put_contents($this->attemptPath(1, $attemptId), $truncated);

        try {
            $this->service()->issueAttemptCertificate(1, $attemptId);
            $this->fail('A stable truncated artefact must fail closed terminally.');
        } catch (IssuanceException $e) {
            $this->assertSame(IssuanceFailureCode::CERTIFICATE_INVALID, $e->failureCode());
        }

        $this->assertSame($truncated, file_get_contents($this->attemptPath(1, $attemptId)), 'a stable invalid artefact is neither overwritten nor deleted');
    }

    public function test_active_writer_makes_a_loser_get_a_transient_retry_deterministically(): void
    {
        $this->bootstrapSigningRoot();
        $this->skipIfNoFlockIsolation();
        $attemptId = (string) Str::uuid();

        // A service whose write window re-enters a SECOND (loser) invocation for
        // the SAME attempt while the winner still holds the file open + LOCK_EX.
        $loserOutcome = ['value' => null];
        $service = new class(new LocalSigningRoot) extends LocalSignerCertificateIssuanceService
        {
            /** @var callable|null */
            public $onDuringExclusiveCreate = null;

            protected function duringExclusiveCreate(string $target): void
            {
                if ($this->onDuringExclusiveCreate !== null) {
                    ($this->onDuringExclusiveCreate)($target);
                }
            }
        };
        $service->onDuringExclusiveCreate = function () use ($service, $attemptId, &$loserOutcome): void {
            try {
                $service->issueAttemptCertificate(1, $attemptId); // loser, same attempt
                $loserOutcome['value'] = 'no-throw';
            } catch (TransientIssuanceException) {
                $loserOutcome['value'] = 'transient';
            } catch (\Throwable $e) {
                $loserOutcome['value'] = 'other:'.class_basename($e);
            }
        };

        $winner = $service->issueAttemptCertificate(1, $attemptId);

        $this->assertTrue($winner->createdByCurrentInvocation, 'the first invocation is the winner');
        $this->assertSame('transient', $loserOutcome['value'], 'a loser that sees an actively-written artefact must get a transient retry');
        $this->assertFileExists($this->attemptPath(1, $attemptId));
    }

    // --- P2-B: fail-closed winner lock / write / flush protocol ----------------

    /**
     * A worker service whose narrow production I/O seams (exclusive lock, chunked
     * write, flush) can be individually forced to fail. The seams are the real
     * production wrappers — the tested `completeWinnerArtifact` logic is never
     * mocked, so a green assertion proves the production control flow, not a mock.
     */
    private function injectableService()
    {
        return new class(new LocalSigningRoot) extends LocalSignerCertificateIssuanceService
        {
            public bool $failLock = false;

            public ?int $failWriteOnCall = null;

            public ?int $zeroWriteOnCall = null;

            public ?int $shortWriteOnFirstCall = null;

            public bool $failFlush = false;

            public int $writeCalls = 0;

            protected function acquireExclusiveLock($handle): bool
            {
                return $this->failLock ? false : parent::acquireExclusiveLock($handle);
            }

            protected function writeChunk($handle, string $bytes): int|false
            {
                $this->writeCalls++;

                if ($this->failWriteOnCall !== null && $this->writeCalls === $this->failWriteOnCall) {
                    return false;
                }

                if ($this->zeroWriteOnCall !== null && $this->writeCalls === $this->zeroWriteOnCall) {
                    return 0;
                }

                if ($this->shortWriteOnFirstCall !== null && $this->writeCalls === 1) {
                    $n = min($this->shortWriteOnFirstCall, strlen($bytes));

                    return parent::writeChunk($handle, substr($bytes, 0, $n));
                }

                return parent::writeChunk($handle, $bytes);
            }

            protected function flushHandle($handle): bool
            {
                return $this->failFlush ? false : parent::flushHandle($handle);
            }
        };
    }

    public function test_lock_acquisition_failure_writes_nothing_and_leaves_no_artefact(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        $service = $this->injectableService();
        $service->failLock = true;

        try {
            $service->issueAttemptCertificate(1, $attemptId);
            $this->fail('A winner that cannot take the exclusive lock must not proceed.');
        } catch (TransientIssuanceException $e) {
            // A neutral fixed sentinel — never a path/PEM or raw OS message.
            $this->assertSame(TransientIssuanceException::SENTINEL, $e->getMessage());
            $this->assertStringNotContainsString($this->signingRoot, $e->getMessage(), 'no absolute path may leak');
        }

        $this->assertSame(0, $service->writeCalls, 'not a single byte may be written without the lock');
        $this->assertFileDoesNotExist($this->attemptPath(1, $attemptId), 'no unlocked empty artefact may remain');
    }

    public function test_first_write_failure_fails_closed_without_a_partial_artefact(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        $service = $this->injectableService();
        $service->failWriteOnCall = 1;

        $this->assertIssuanceFailsClosed($service, 1, $attemptId, IssuanceFailureCode::ARTEFACT_UNSAFE);
        $this->assertFileDoesNotExist($this->attemptPath(1, $attemptId));
    }

    public function test_a_later_write_failure_cleans_up_the_partial_artefact(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        // The first call writes a real partial fragment; the second call fails.
        $service = $this->injectableService();
        $service->shortWriteOnFirstCall = 10;
        $service->failWriteOnCall = 2;

        $this->assertIssuanceFailsClosed($service, 1, $attemptId, IssuanceFailureCode::ARTEFACT_UNSAFE);
        $this->assertFileDoesNotExist($this->attemptPath(1, $attemptId), 'a partially written artefact must be removed');
    }

    public function test_a_short_write_that_completes_via_subsequent_writes_succeeds(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        // A legitimate short first write must be completed by the following writes.
        $service = $this->injectableService();
        $service->shortWriteOnFirstCall = 10;

        $artifact = $service->issueAttemptCertificate(1, $attemptId);

        $this->assertTrue($artifact->createdByCurrentInvocation);
        $this->assertGreaterThan(1, $service->writeCalls, 'a short write must be finished by further writes');
        $this->assertFileExists($this->attemptPath(1, $attemptId));
        $this->assertSame($artifact->pem, file_get_contents($this->attemptPath(1, $attemptId)), 'the full leaf is on disk');
        $this->assertNotFalse(openssl_x509_read($artifact->pem), 'the completed artefact is a valid certificate');
    }

    public function test_a_zero_write_before_completion_fails_closed(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        // A no-progress (0-byte) write before completion is a stuck write.
        $service = $this->injectableService();
        $service->shortWriteOnFirstCall = 10;
        $service->zeroWriteOnCall = 2;

        $this->assertIssuanceFailsClosed($service, 1, $attemptId, IssuanceFailureCode::ARTEFACT_UNSAFE);
        $this->assertFileDoesNotExist($this->attemptPath(1, $attemptId));
    }

    public function test_flush_failure_fails_closed_without_a_partial_artefact(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        // The full content is written but the explicit flush does not succeed.
        $service = $this->injectableService();
        $service->failFlush = true;

        $this->assertIssuanceFailsClosed($service, 1, $attemptId, IssuanceFailureCode::ARTEFACT_UNSAFE);
        $this->assertFileDoesNotExist($this->attemptPath(1, $attemptId), 'an unflushed artefact must be removed');
    }

    private function assertIssuanceFailsClosed(
        LocalSignerCertificateIssuanceService $service,
        int $requestId,
        string $attemptId,
        string $expectedCode,
    ): void {
        try {
            $service->issueAttemptCertificate($requestId, $attemptId);
            $this->fail('Issuance must fail closed.');
        } catch (IssuanceException $e) {
            $this->assertSame($expectedCode, $e->failureCode());
            $this->assertSame($e->failureCode(), $e->getMessage(), 'the message is only the neutral code — no path/PEM leak');
            $this->assertStringNotContainsString($this->signingRoot, $e->getMessage(), 'no absolute path may leak');
        }
    }

    // --- D1: cleanup ownership / basename re-derivation ------------------------

    public function test_cleanup_refuses_to_delete_when_the_stored_path_disagrees_with_the_identity(): void
    {
        $this->bootstrapSigningRoot();

        // Winner B owns its own artefact.
        $attemptB = (string) Str::uuid();
        $winnerB = $this->service()->issueAttemptCertificate(2, $attemptB);
        $this->assertFileExists($this->attemptPath(2, $attemptB));

        // A tampered artefact claims attempt A's identity but stores attempt B's
        // path. Cleanup must re-derive the path from (A) and refuse to delete B.
        $attemptA = (string) Str::uuid();
        $tampered = new AttemptCertificateArtifact(
            $this->attemptPath(2, $attemptB),   // stored path points at B's file
            $winnerB->pem,
            true,
            5,                                   // requestId A
            $attemptA,                           // attemptId A
        );

        $this->assertTrue($this->service()->discardOwnedArtefact($tampered), 'a mismatched-identity artefact is a no-op, not a delete');
        $this->assertFileExists($this->attemptPath(2, $attemptB), 'attempt A cleanup must never delete attempt B\'s artefact');
    }

    public function test_cleanup_deletes_only_the_reverified_own_artifact(): void
    {
        $this->bootstrapSigningRoot();
        $attempt = (string) Str::uuid();
        $winner = $this->service()->issueAttemptCertificate(7, $attempt);
        $this->assertFileExists($this->attemptPath(7, $attempt));

        $this->assertTrue($this->service()->discardOwnedArtefact($winner));
        $this->assertFileDoesNotExist($this->attemptPath(7, $attempt));
    }

    public function test_cleanup_does_not_delete_a_changed_artifact(): void
    {
        $this->bootstrapSigningRoot();
        $attempt = (string) Str::uuid();
        $winner = $this->service()->issueAttemptCertificate(7, $attempt);

        file_put_contents($this->attemptPath(7, $attempt), 'tampered-after-creation');

        $this->assertTrue($this->service()->discardOwnedArtefact($winner));
        $this->assertFileExists($this->attemptPath(7, $attempt), 'a changed artefact is not deleted');
    }

    // --- D2: shared-material open-time identity guard --------------------------

    public function test_shared_material_swap_between_check_and_open_fails_closed(): void
    {
        $this->bootstrapSigningRoot();
        $this->skipIfNoInodeIdentity();

        $rootCa = $this->signingRoot.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_ROOT_CA;
        $original = file_get_contents($rootCa);

        // A service that swaps the Root CA file for a DIFFERENT-inode regular file
        // between the pre-open safety checks and fopen().
        $service = new class(new LocalSigningRoot) extends LocalSignerCertificateIssuanceService
        {
            /** @var callable|null */
            public $onBeforeOpen = null;

            protected function beforeContainedFileOpen(string $real): void
            {
                if ($this->onBeforeOpen !== null) {
                    ($this->onBeforeOpen)($real);
                }
            }
        };
        $service->onBeforeOpen = function (string $real) use ($rootCa, $original): void {
            if ($real === realpath($rootCa) || $real === $rootCa) {
                @unlink($real);
                file_put_contents($real, $original.'SWAPPED'); // new inode, same path
            }
        };

        try {
            $service->issueAttemptCertificate(9, (string) Str::uuid());
            $this->fail('A swap between the pre-open check and fopen must fail closed.');
        } catch (IssuanceException $e) {
            $this->assertContains($e->failureCode(), [IssuanceFailureCode::SIGNING_ROOT_INVALID, IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE]);
            $this->assertSame($e->failureCode(), $e->getMessage(), 'no path leaks in the message');
        }
    }

    private function skipIfNoFlockIsolation(): void
    {
        $probe = $this->signingRoot.DIRECTORY_SEPARATOR.'flock-probe-'.bin2hex(random_bytes(4));
        file_put_contents($probe, 'x');
        $a = fopen($probe, 'rb');
        $b = fopen($probe, 'rb');
        $ex = @flock($a, LOCK_EX);
        $wb = 0;
        $isolated = $ex && ! @flock($b, LOCK_SH | LOCK_NB, $wb);
        @flock($a, LOCK_UN);
        fclose($a);
        fclose($b);
        @unlink($probe);

        if (! $isolated) {
            $this->markTestSkipped('This platform does not provide flock isolation between two handles.');
        }
    }

    private function skipIfNoInodeIdentity(): void
    {
        $probe = $this->signingRoot.DIRECTORY_SEPARATOR.'ino-probe-'.bin2hex(random_bytes(4));
        file_put_contents($probe, 'x');
        $s = @stat($probe);
        @unlink($probe);

        if (! is_array($s) || ($s['ino'] ?? 0) === 0) {
            $this->markTestSkipped('This platform does not expose a stable inode identity.');
        }
    }

    public function test_invalid_existing_artifact_is_neither_overwritten_nor_deleted(): void
    {
        $this->bootstrapSigningRoot();
        $attemptId = (string) Str::uuid();

        // A parsable but untrusted / key-mismatched certificate at the attempt path.
        @mkdir(dirname($this->attemptPath(1, $attemptId)), 0700, true);
        $foreign = $this->selfSignedPem();
        file_put_contents($this->attemptPath(1, $attemptId), $foreign);

        try {
            $this->service()->issueAttemptCertificate(1, $attemptId);
            $this->fail('An invalid existing artefact must fail closed.');
        } catch (IssuanceException $e) {
            $this->assertSame(IssuanceFailureCode::CERTIFICATE_INVALID, $e->failureCode());
        }

        $this->assertSame($foreign, file_get_contents($this->attemptPath(1, $attemptId)), 'an invalid artefact is neither overwritten nor deleted');
    }

    // --- P2-4 per-file shared-material safety ----------------------------------

    /** @return array<string, array{0:string}> */
    public static function sharedFileProvider(): array
    {
        return [
            'root ca cert' => [LocalSignerCertificateIssuanceService::FILE_ROOT_CA],
            'root ca key' => [LocalSignerCertificateIssuanceService::FILE_ROOT_CA_KEY],
            'signer key' => [LocalSignerCertificateIssuanceService::FILE_SIGNER_KEY],
            'passphrase' => [LocalSignerCertificateIssuanceService::FILE_PASSPHRASE],
        ];
    }

    #[DataProvider('sharedFileProvider')]
    public function test_symlinked_shared_material_file_fails_closed(string $name): void
    {
        $this->bootstrapSigningRoot();
        $path = $this->signingRoot.DIRECTORY_SEPARATOR.$name;

        // Replace the real file with a symlink to a copy elsewhere.
        $decoy = $this->makeDecoyCopy($path, $name);
        @unlink($path);
        if (@symlink($decoy, $path) === false || ! is_link($path)) {
            $this->markTestSkipped('This platform cannot create a file symlink without elevation.');
        }

        try {
            $this->service()->issueAttemptCertificate(3, (string) Str::uuid());
            $this->fail('A symlinked shared-material file must fail closed.');
        } catch (IssuanceException $e) {
            $this->assertSame(IssuanceFailureCode::SIGNING_ROOT_INVALID, $e->failureCode());
            $this->assertStringNotContainsString($this->signingRoot, $e->getMessage(), 'no absolute path may leak');
            $this->assertSame($e->failureCode(), $e->getMessage(), 'the message is only the neutral code');
        }

        // The suspicious symlink is never deleted.
        $this->assertTrue(is_link($path));
    }

    public function test_directory_in_place_of_a_shared_file_fails_closed(): void
    {
        $this->bootstrapSigningRoot();
        $path = $this->signingRoot.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_PASSPHRASE;
        @unlink($path);
        @mkdir($path, 0700);

        try {
            $this->service()->issueAttemptCertificate(3, (string) Str::uuid());
            $this->fail('A directory in place of a shared file must fail closed.');
        } catch (IssuanceException $e) {
            $this->assertContains($e->failureCode(), [IssuanceFailureCode::SIGNING_ROOT_INVALID, IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE]);
        }
    }

    public function test_safe_regular_shared_files_pass_and_issue_a_leaf(): void
    {
        $this->bootstrapSigningRoot();

        $artifact = $this->service()->issueAttemptCertificate(11, (string) Str::uuid());

        $this->assertTrue($artifact->createdByCurrentInvocation);
        $this->assertNotSame('', $artifact->pem);
    }

    // --- leaf profile ---------------------------------------------------------

    public function test_leaf_profile_is_end_entity_signing_without_pii(): void
    {
        $this->bootstrapSigningRoot();
        $artifact = $this->service()->issueAttemptCertificate(9, (string) Str::uuid());

        $parsed = openssl_x509_parse($artifact->pem);
        $this->assertIsArray($parsed);
        $this->assertMatchesRegularExpression('/CA\s*:\s*FALSE/i', (string) ($parsed['extensions']['basicConstraints'] ?? ''));
        $this->assertStringContainsStringIgnoringCase('Digital Signature', (string) ($parsed['extensions']['keyUsage'] ?? ''));

        $subject = strtolower(json_encode($parsed['subject'] ?? []));
        $this->assertStringNotContainsString('@', $subject);
        $this->assertStringNotContainsString('oib', $subject);
    }

    // --- helpers --------------------------------------------------------------

    private function makeDecoyCopy(string $path, string $name): string
    {
        $decoy = $this->signingRoot.DIRECTORY_SEPARATOR.'decoy-'.$name;
        @copy($path, $decoy);

        return $decoy;
    }

    /** A parsable X.509 that is neither key-matched to the shared signer nor trusted. */
    private function selfSignedPem(): string
    {
        $cnf = $this->signingRoot.DIRECTORY_SEPARATOR.'foreign-openssl.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\nprompt = no\n\n[dn]\nCN = Foreign\n");
        $opts = ['config' => $cnf, 'digest_alg' => 'sha256'];

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
        $csr = openssl_csr_new(['commonName' => 'Foreign'], $key, $opts);
        $cert = openssl_csr_sign($csr, null, $key, 365, $opts, random_int(1, PHP_INT_MAX));
        $pem = '';
        openssl_x509_export($cert, $pem);
        @unlink($cnf);

        return $pem;
    }
}
