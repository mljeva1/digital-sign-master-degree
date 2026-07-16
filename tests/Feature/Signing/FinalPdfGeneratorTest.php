<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\FinalPdfException;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\FinalPdfGenerator;
use App\Services\Contracts\PublicVerificationQrCode;
use App\Services\Signing\FinalPdfIntegrityVerifier;
use App\Services\Signing\FinalPdfVerificationBindingVerifier;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Create-only, immutable final-PDF generation and its filesystem/DB compensation.
 *
 * Uses the real DomPDF render, real filesystem bytes and real DB transactions —
 * failures are injected through narrow seams, never by mocking the final assertion
 * away. Every controlled failure must leave the PREVIOUS artefact (bytes,
 * StoredFile row, Contract binding) completely intact and leave no new orphan.
 */
final class FinalPdfGeneratorTest extends ContractSigningTestCase
{
    private function generator(): FinalPdfGenerator
    {
        return app(FinalPdfGenerator::class);
    }

    /**
     * A generator whose narrow seams can fail deterministically.
     *
     * @param  array<string, mixed>  $opts
     */
    private function seamGenerator(array $opts): FinalPdfGenerator
    {
        return new class(app(AuditLogger::class), app(PublicVerificationQrCode::class), app(FinalPdfIntegrityVerifier::class), app(FinalPdfVerificationBindingVerifier::class), $opts) extends FinalPdfGenerator
        {
            public string $collidedPath = '';

            /** @param array<string, mixed> $opts */
            public function __construct(AuditLogger $a, PublicVerificationQrCode $q, FinalPdfIntegrityVerifier $v, FinalPdfVerificationBindingVerifier $b, private readonly array $opts)
            {
                parent::__construct($a, $q, $v, $b);
            }

            protected function putPdf(Filesystem $disk, string $path, string $contents): bool
            {
                if (($this->opts['collision'] ?? false) === true) {
                    $this->collidedPath = $path;
                    $disk->put($path, (string) $this->opts['foreignBytes']);

                    return false;
                }
                if (($this->opts['throwPut'] ?? false) === true) {
                    throw new RuntimeException('injected write failure');
                }
                if (($this->opts['failPut'] ?? false) === true) {
                    return false;
                }

                return parent::putPdf($disk, $path, $contents);
            }

            protected function afterPdfWritten(Filesystem $disk, string $path): void
            {
                if (($this->opts['corrupt'] ?? false) === true) {
                    $disk->put($path, 'CORRUPTED-PDF-BYTES');
                }
                if (($this->opts['removeAfterWrite'] ?? false) === true) {
                    $disk->delete($path);
                }
            }

            protected function createStoredFile(Contract $locked, string $path, int $size, string $sha256, int $createdByUserId): StoredFile
            {
                if (($this->opts['failStoredFile'] ?? false) === true) {
                    throw new RuntimeException('injected StoredFile persistence failure');
                }

                return parent::createStoredFile($locked, $path, $size, $sha256, $createdByUserId);
            }

            protected function rebindContract(Contract $locked, int $storedFileId, string $sha256): void
            {
                if (($this->opts['failRebind'] ?? false) === true) {
                    throw new RuntimeException('injected contract update failure');
                }

                parent::rebindContract($locked, $storedFileId, $sha256);
            }

            protected function deleteProvisional(Filesystem $disk, string $path): bool
            {
                if (($this->opts['failCleanup'] ?? false) === true) {
                    return false;
                }

                return parent::deleteProvisional($disk, $path);
            }

            protected function recordGenerationAudit(Contract $locked, int $actorUserId, array $metadata): void
            {
                if (($this->opts['failAudit'] ?? false) === true) {
                    throw new RuntimeException('injected audit failure');
                }

                parent::recordGenerationAudit($locked, $actorUserId, $metadata);
            }

            protected function recordPublicVerificationAudit(Contract $locked, StoredFile $storedFile, int $actorUserId, bool $tokenCreated): void
            {
                if (($this->opts['failPublicVerificationAudit'] ?? false) === true) {
                    throw new RuntimeException('injected public-enable audit failure');
                }

                parent::recordPublicVerificationAudit($locked, $storedFile, $actorUserId, $tokenCreated);
            }

            protected function findContractForReconciliation(int $contractId): ?Contract
            {
                if (($this->opts['throwReconciliationLookup'] ?? false) === true) {
                    throw new RuntimeException('injected reconciliation DB lookup failure');
                }

                return parent::findContractForReconciliation($contractId);
            }

            protected function runInTransaction(\Closure $callback)
            {
                // LOST COMMIT ACK: the callback AND the COMMIT really succeed, then
                // the driver throws — the app cannot tell whether it committed.
                if (($this->opts['lostCommitAck'] ?? false) === true) {
                    parent::runInTransaction($callback);

                    throw new RuntimeException('injected lost commit acknowledgement');
                }

                // AMBIGUOUS BUT ROLLED BACK: the callback completes, then the
                // transaction rolls back. The app still cannot tell, so the artefact
                // must be kept and the outcome reported as unprovable.
                if (($this->opts['ambiguousRollback'] ?? false) === true) {
                    try {
                        parent::runInTransaction(function () use ($callback): void {
                            $callback();

                            throw new RuntimeException('force rollback after callback completed');
                        });
                    } catch (Throwable) {
                        // swallowed: emulate an opaque driver failure
                    }

                    throw new RuntimeException('injected ambiguous outcome');
                }

                return parent::runInTransaction($callback);
            }
        };
    }

    /**
     * @return array{user: User, contract: Contract}
     */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return ['user' => $user, 'contract' => $this->seedContract($user)];
    }

    /**
     * Snapshot the current artefact so a failure can be proven non-destructive.
     *
     * @return array{file: array<string, mixed>, bytes: string, bindingId: int, bindingSha: string}
     */
    private function snapshotCurrent(Contract $contract): array
    {
        $fresh = $contract->fresh();
        $file = StoredFile::findOrFail($fresh->final_pdf_file_id);

        return [
            'file' => $file->only(['id', 'storage_path', 'size_bytes', 'sha256']),
            'bytes' => Storage::disk(StoredFile::DISK_LOCAL)->get($file->storage_path),
            'bindingId' => (int) $fresh->final_pdf_file_id,
            'bindingSha' => (string) $fresh->final_pdf_sha256,
        ];
    }

    /**
     * @param  array{file: array<string, mixed>, bytes: string, bindingId: int, bindingSha: string}  $before
     */
    private function assertPreviousArtefactIntact(Contract $contract, array $before): void
    {
        $fresh = $contract->fresh();
        $this->assertSame($before['bindingId'], (int) $fresh->final_pdf_file_id, 'binding must not move');
        $this->assertSame($before['bindingSha'], (string) $fresh->final_pdf_sha256, 'binding hash must not change');
        $this->assertSame($before['file'], StoredFile::findOrFail($before['bindingId'])->only(['id', 'storage_path', 'size_bytes', 'sha256']));
        $this->assertSame($before['bytes'], Storage::disk(StoredFile::DISK_LOCAL)->get($before['file']['storage_path']), 'previous bytes must be untouched');
        $this->assertSame(1, $this->finalPdfCount(), 'no new orphan final PDF may remain');
    }

    // --- create-only immutable generation -----------------------------------

    public function test_generation_creates_a_new_unpredictable_private_path(): void
    {
        $f = $this->fixture();

        $file = $this->generator()->generate($f['contract'], (int) $f['user']->id);

        $this->assertSame(StoredFile::PURPOSE_FINAL_PDF, $file->purpose);
        $this->assertSame(StoredFile::DISK_LOCAL, $file->storage_disk);
        $this->assertMatchesRegularExpression(
            '#^contracts/'.$f['contract']->id.'/final-pdfs/final-[0-9a-f-]{36}\.pdf$#',
            $file->storage_path
        );
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($file->storage_path));

        // size/sha are the PHYSICAL bytes.
        $bytes = Storage::disk(StoredFile::DISK_LOCAL)->get($file->storage_path);
        $this->assertSame(strlen($bytes), (int) $file->size_bytes);
        $this->assertSame(hash('sha256', $bytes), $file->sha256);

        // binding + caller model updated only after commit
        $this->assertSame((int) $file->id, (int) $f['contract']->fresh()->final_pdf_file_id);
        $this->assertSame($file->sha256, $f['contract']->fresh()->final_pdf_sha256);
        $this->assertSame((int) $file->id, (int) $f['contract']->final_pdf_file_id);
    }

    public function test_regeneration_is_create_only_and_leaves_the_old_artefact_intact(): void
    {
        $f = $this->fixture();
        $first = $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $firstBytes = Storage::disk(StoredFile::DISK_LOCAL)->get($first->storage_path);

        $second = $this->generator()->generate($f['contract']->fresh(), (int) $f['user']->id);

        // NEW row + NEW path — the old StoredFile is never mutated or overwritten.
        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertNotSame($first->storage_path, $second->storage_path);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($first->storage_path));
        $this->assertSame($firstBytes, Storage::disk(StoredFile::DISK_LOCAL)->get($first->storage_path));
        $this->assertSame($first->only(['storage_path', 'size_bytes', 'sha256']), StoredFile::findOrFail($first->id)->only(['storage_path', 'size_bytes', 'sha256']));

        // binding moves to the new artefact
        $this->assertSame((int) $second->id, (int) $f['contract']->fresh()->final_pdf_file_id);
    }

    public function test_pending_and_completed_signatures_block_regeneration(): void
    {
        foreach ([Signature::STATUS_PENDING, Signature::STATUS_COMPLETED] as $status) {
            $f = $this->fixture();
            $file = $this->generator()->generate($f['contract'], (int) $f['user']->id);
            $before = $this->snapshotCurrent($f['contract']);

            Signature::create([
                'contract_id' => $f['contract']->id, 'certificate_id' => null, 'signed_user_id' => $f['user']->id,
                'source_file_id' => $file->id, 'signature_file_id' => null, 'type' => Signature::TYPE_DIGITAL,
                'status' => $status, 'signed_at' => now(),
                'document_hash_before' => $file->sha256, 'document_hash_after' => $file->sha256,
            ]);

            try {
                $this->generator()->generate($f['contract']->fresh(), (int) $f['user']->id);
                $this->fail("Expected {$status} signature to block regeneration.");
            } catch (FinalPdfException $e) {
                $this->assertSame(FinalPdfException::FINAL_PDF_ACTIVELY_SIGNED, $e->errorCode());
            }

            $this->assertPreviousArtefactIntact($f['contract'], $before);
            // reset for the next status iteration
            Signature::query()->delete();
            Contract::query()->delete();
            StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->delete();
            Storage::disk(StoredFile::DISK_LOCAL)->deleteDirectory('contracts');
        }
    }

    // --- P1: ambiguous commit outcome ---------------------------------------

    public function test_lost_commit_acknowledgement_does_not_delete_the_committed_pdf(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $previous = $this->snapshotCurrent($f['contract']);

        // The COMMIT really lands; only the acknowledgement is lost.
        $stored = $this->seamGenerator(['lostCommitAck' => true])->generate($f['contract']->fresh(), (int) $f['user']->id);

        // Reconciliation proved the commit: success, artefact KEPT, state consistent.
        $fresh = $f['contract']->fresh();
        $this->assertSame((int) $stored->id, (int) $fresh->final_pdf_file_id);
        $this->assertNotSame($previous['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($stored->storage_path), 'the committed PDF must NOT be deleted');
        $bytes = Storage::disk(StoredFile::DISK_LOCAL)->get($stored->storage_path);
        $this->assertSame(hash('sha256', $bytes), $fresh->final_pdf_sha256);
        $this->assertSame(strlen($bytes), (int) $stored->size_bytes);
        $this->assertTrue($this->generationAuditFor((int) $stored->id));
        // The previous artefact is still intact too.
        $this->assertSame($previous['bytes'], Storage::disk(StoredFile::DISK_LOCAL)->get($previous['file']['storage_path']));
    }

    public function test_reconciliation_lookup_exception_keeps_committed_pdf_and_flags_incomplete(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $previous = $this->snapshotCurrent($f['contract']);

        try {
            $this->seamGenerator([
                'lostCommitAck' => true,
                'throwReconciliationLookup' => true,
            ])->generate($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected unprovable reconciliation to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED, $e->errorCode());
            $this->assertTrue($e->compensationIncomplete());
        }

        $fresh = $f['contract']->fresh();
        $this->assertNotSame($previous['bindingId'], (int) $fresh->final_pdf_file_id, 'the real commit landed');
        $committed = StoredFile::findOrFail($fresh->final_pdf_file_id);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($committed->storage_path));
        $this->assertSame(2, $this->finalPdfCount());
    }

    public function test_generator_refuses_nested_transaction_instead_of_treating_savepoint_as_commit(): void
    {
        $f = $this->fixture();

        try {
            DB::transaction(fn () => $this->generator()->generate($f['contract'], (int) $f['user']->id));
            $this->fail('Expected nested generation to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED, $e->errorCode());
        }

        $this->assertNull($f['contract']->fresh()->final_pdf_file_id);
        $this->assertSame(0, $this->finalPdfCount());
        $this->assertSame(0, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
    }

    public function test_public_workflow_failure_after_pdf_persistence_rolls_back_everything_and_cleans_owned_pdf(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $before = $this->snapshotCurrent($f['contract']);

        try {
            $this->seamGenerator(['failPublicVerificationAudit' => true])
                ->generateForPublicVerification($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected the outer workflow callback to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED, $e->errorCode());
            $this->assertFalse($e->compensationIncomplete());
        }

        $fresh = $f['contract']->fresh();
        $this->assertNull($fresh->public_verification_token);
        $this->assertNull($fresh->public_verification_enabled_at);
        $this->assertSame($before['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertSame($before['bindingSha'], (string) $fresh->final_pdf_sha256);
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'contract.final_pdf_generated')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.public_verification_enabled')->count());
        $this->assertSame(1, $this->finalPdfCount());
    }

    public function test_unprovable_ambiguous_outcome_keeps_the_artefact_and_flags_incomplete(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $previous = $this->snapshotCurrent($f['contract']);

        try {
            $this->seamGenerator(['ambiguousRollback' => true])->generate($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected an unprovable ambiguous outcome to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED, $e->errorCode());
            // An orphan is safer than deleting a possibly-committed artefact.
            $this->assertTrue($e->compensationIncomplete());
            $this->assertStringNotContainsString($this->tempDir, $e->getMessage());
            $this->assertStringNotContainsString('.pdf', $e->getMessage());
        }

        // Previous binding/StoredFile/bytes untouched; the new file was KEPT (orphan).
        $fresh = $f['contract']->fresh();
        $this->assertSame($previous['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertSame($previous['bytes'], Storage::disk(StoredFile::DISK_LOCAL)->get($previous['file']['storage_path']));
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
        $this->assertSame(2, $this->finalPdfCount(), 'the ambiguous artefact is deliberately retained');
    }

    private function generationAuditFor(int $fileId): bool
    {
        foreach (AuditEvent::query()->where('action', 'contract.final_pdf_generated')->get() as $e) {
            if ((int) ($e->metadata['file_id'] ?? 0) === $fileId) {
                return true;
            }
        }

        return false;
    }

    // --- fresh render inputs -------------------------------------------------

    public function test_render_uses_fresh_locked_db_state_not_the_stale_caller_model(): void
    {
        $f = $this->fixture();
        $stale = $f['contract'];              // caller's model, loaded now
        $this->generator()->generate($stale, (int) $f['user']->id);

        // The DB gains a verification token AFTER the caller model was loaded.
        $token = Str::random(64);
        Contract::query()->whereKey($stale->id)->update([
            'public_verification_token' => $token,
            'public_verification_enabled_at' => now(),
        ]);
        $this->assertNull($stale->public_verification_token, 'caller model is deliberately stale');

        // Generating with the STALE model must still embed the CURRENT token's QR.
        $file = $this->generator()->generate($stale, (int) $f['user']->id);
        $bytes = Storage::disk(StoredFile::DISK_LOCAL)->get($file->storage_path);

        // A QR/verification URL is only rendered when a token exists, so a larger
        // artefact than the token-less one proves the fresh token was used.
        $tokenless = StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->orderBy('id')->first();
        $this->assertGreaterThan((int) $tokenless->size_bytes, strlen($bytes), 'fresh token QR must be embedded');
    }

    // --- filesystem / DB compensation ---------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function failureBranchProvider(): array
    {
        return [
            'put returns false' => [['failPut' => true], FinalPdfException::FINAL_PDF_STORAGE_FAILED],
            'put throws' => [['throwPut' => true], FinalPdfException::FINAL_PDF_STORAGE_FAILED],
            'file removed after write' => [['removeAfterWrite' => true], FinalPdfException::FINAL_PDF_STORAGE_FAILED],
            'StoredFile insert fails' => [['failStoredFile' => true], FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED],
            'contract rebind fails' => [['failRebind' => true], FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED],
        ];
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    #[DataProvider('failureBranchProvider')]
    public function test_failure_after_write_compensates_and_preserves_the_previous_artefact(array $opts, string $expectedCode): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id); // establish a valid artefact
        $before = $this->snapshotCurrent($f['contract']);

        try {
            $this->seamGenerator($opts)->generate($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected the generation to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame($expectedCode, $e->errorCode());
            $this->assertFalse($e->compensationIncomplete());
            $this->assertStringNotContainsString($this->tempDir, $e->getMessage());
            $this->assertStringNotContainsString('contracts/', $e->getMessage());
        }

        $this->assertPreviousArtefactIntact($f['contract'], $before);
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
    }

    public function test_unproven_readback_mismatch_is_retained_and_flagged_incomplete(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $before = $this->snapshotCurrent($f['contract']);

        try {
            $this->seamGenerator(['corrupt' => true])->generate($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected the readback mismatch to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_STORAGE_FAILED, $e->errorCode());
            $this->assertTrue($e->compensationIncomplete());
        }

        $fresh = $f['contract']->fresh();
        $this->assertSame($before['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertSame($before['bytes'], Storage::disk(StoredFile::DISK_LOCAL)->get($before['file']['storage_path']));
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
        $this->assertSame(2, $this->finalPdfCount(), 'unowned mismatched bytes are retained, never deleted');
    }

    public function test_foreign_pdf_collision_is_never_overwritten_or_deleted(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $before = $this->snapshotCurrent($f['contract']);
        $foreign = "%FOREIGN-PDF-DO-NOT-TOUCH\n";
        $generator = $this->seamGenerator(['collision' => true, 'foreignBytes' => $foreign]);

        try {
            $generator->generate($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected the collision to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_STORAGE_FAILED, $e->errorCode());
            $this->assertTrue($e->compensationIncomplete());
            $this->assertStringNotContainsString('.pdf', $e->getMessage());
        }

        $this->assertNotSame('', $generator->collidedPath);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($generator->collidedPath));
        $this->assertSame($foreign, Storage::disk(StoredFile::DISK_LOCAL)->get($generator->collidedPath));
        $fresh = $f['contract']->fresh();
        $this->assertSame($before['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertSame($before['bindingSha'], (string) $fresh->final_pdf_sha256);
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'contract.final_pdf_generated')->count());
    }

    public function test_audit_failure_after_write_rolls_back_and_compensates(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $before = $this->snapshotCurrent($f['contract']);

        try {
            $this->seamGenerator(['failAudit' => true])->generate($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected the audit failure to abort generation.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED, $e->errorCode());
        }

        $this->assertPreviousArtefactIntact($f['contract'], $before);
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
    }

    public function test_cleanup_failure_flags_compensation_incomplete_without_leaking_a_path(): void
    {
        $f = $this->fixture();
        $this->generator()->generate($f['contract'], (int) $f['user']->id);
        $before = $this->snapshotCurrent($f['contract']);

        try {
            $this->seamGenerator(['failStoredFile' => true, 'failCleanup' => true])->generate($f['contract']->fresh(), (int) $f['user']->id);
            $this->fail('Expected the generation to fail.');
        } catch (FinalPdfException $e) {
            $this->assertSame(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED, $e->errorCode());
            $this->assertTrue($e->compensationIncomplete());
            $this->assertStringNotContainsString($this->tempDir, $e->getMessage());
            $this->assertStringNotContainsString('.pdf', $e->getMessage());
        }

        // DB state and the previous artefact are still fully intact; only the new
        // orphan file remains (that is exactly what compensationIncomplete reports).
        $fresh = $f['contract']->fresh();
        $this->assertSame($before['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertSame($before['bytes'], Storage::disk(StoredFile::DISK_LOCAL)->get($before['file']['storage_path']));
        $this->assertSame(1, StoredFile::query()->where('purpose', StoredFile::PURPOSE_FINAL_PDF)->count());
    }
}
