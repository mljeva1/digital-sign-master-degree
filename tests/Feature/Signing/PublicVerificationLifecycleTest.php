<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\FinalPdfGenerator;
use App\Services\Contracts\PublicVerificationQrCode;
use App\Services\Signing\ContractSigningRequest;
use App\Services\Signing\ContractSigningService;
use App\Services\Signing\FinalPdfIntegrityVerifier;
use App\Services\Signing\FinalPdfVerificationBindingVerifier;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Route-level public-verification / QR lifecycle against the real endpoint,
 * middleware and auth.
 *
 * Enforces the freeze-before-sign order: public verification + QR must be embedded
 * BEFORE signing. A signed final PDF is immutable, so a token that was never
 * embedded in the signed bytes can never be activated afterwards.
 */
final class PublicVerificationLifecycleTest extends ContractSigningTestCase
{
    /**
     * @return array{user: User, contract: Contract}
     */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return ['user' => $user, 'contract' => $this->seedContract($user)];
    }

    private function enable(Contract $contract)
    {
        return $this->post(route('contracts.public-verification.enable', $contract));
    }

    // --- unsigned -----------------------------------------------------------

    public function test_enabling_on_unsigned_contract_creates_token_and_regenerates_pdf_with_qr(): void
    {
        $f = $this->fixture();
        $first = app(FinalPdfGenerator::class)->generate($f['contract'], (int) $f['user']->id);
        $firstSize = (int) $first->size_bytes;

        $this->enable($f['contract'])->assertRedirect()->assertSessionHas('success');

        $fresh = $f['contract']->fresh();
        $this->assertNotNull($fresh->public_verification_token);
        $this->assertNotNull($fresh->public_verification_enabled_at);
        $this->assertNull($fresh->public_verification_revoked_at);

        // A NEW create-only artefact was produced and the binding moved to it.
        $this->assertNotSame((int) $first->id, (int) $fresh->final_pdf_file_id);
        $new = StoredFile::findOrFail($fresh->final_pdf_file_id);
        $this->assertNotSame($first->storage_path, $new->storage_path);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($new->storage_path));

        // The regenerated PDF embeds the QR (it is larger than the token-less one)
        // and was generated at/after the token activation.
        $this->assertGreaterThan($firstSize, (int) $new->size_bytes);
        $this->assertTrue($new->created_at->gte($fresh->public_verification_enabled_at));

        // The old artefact is untouched.
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($first->storage_path));

        $audit = AuditEvent::query()->where('action', 'contract.public_verification_enabled')->latest('id')->firstOrFail();
        $this->assertTrue($audit->metadata['final_pdf_regenerated']);
        $this->assertSame((int) $f['user']->id, (int) $audit->actor_user_id);
    }

    public function test_generation_failure_rolls_back_the_token_and_keeps_the_old_binding(): void
    {
        $f = $this->fixture();
        $first = app(FinalPdfGenerator::class)->generate($f['contract'], (int) $f['user']->id);
        $beforeBytes = Storage::disk(StoredFile::DISK_LOCAL)->get($first->storage_path);

        // Force the regeneration inside the enable flow to fail.
        $this->app->bind(FinalPdfGenerator::class, fn () => new class(app(AuditLogger::class), app(PublicVerificationQrCode::class), app(FinalPdfIntegrityVerifier::class), app(FinalPdfVerificationBindingVerifier::class)) extends FinalPdfGenerator
        {
            protected function putPdf(Filesystem $disk, string $path, string $contents): bool
            {
                return false;
            }
        });

        $this->enable($f['contract'])->assertRedirect()->assertSessionHas('error');
        $this->assertFalse(session()->has('success'));

        // Token/enabled state rolled back; old binding + bytes intact.
        $fresh = $f['contract']->fresh();
        $this->assertNull($fresh->public_verification_token);
        $this->assertNull($fresh->public_verification_enabled_at);
        $this->assertSame((int) $first->id, (int) $fresh->final_pdf_file_id);
        $this->assertSame($beforeBytes, Storage::disk(StoredFile::DISK_LOCAL)->get($first->storage_path));
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.public_verification_enabled')->count());
    }

    public function test_real_top_level_commit_with_lost_ack_reconciles_the_entire_public_workflow(): void
    {
        $f = $this->fixture();
        $first = app(FinalPdfGenerator::class)->generate($f['contract'], (int) $f['user']->id);

        $this->app->bind(FinalPdfGenerator::class, fn () => new class(app(AuditLogger::class), app(PublicVerificationQrCode::class), app(FinalPdfIntegrityVerifier::class), app(FinalPdfVerificationBindingVerifier::class)) extends FinalPdfGenerator
        {
            protected function runInTransaction(\Closure $callback)
            {
                parent::runInTransaction($callback);

                throw new RuntimeException('injected lost top-level commit acknowledgement');
            }
        });

        $this->enable($f['contract'])->assertRedirect()->assertSessionHas('success');

        $fresh = $f['contract']->fresh();
        $this->assertNotNull($fresh->public_verification_token);
        $this->assertNotNull($fresh->public_verification_enabled_at);
        $this->assertNull($fresh->public_verification_revoked_at);
        $this->assertNotSame((int) $first->id, (int) $fresh->final_pdf_file_id);
        $committed = StoredFile::findOrFail($fresh->final_pdf_file_id);
        $this->assertTrue(Storage::disk(StoredFile::DISK_LOCAL)->exists($committed->storage_path));
        $this->assertSame(1, AuditEvent::query()->where('action', 'contract.public_verification_enabled')->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'contract.final_pdf_generated')->count());
    }

    // --- signed -------------------------------------------------------------

    /**
     * @return array{user: User, contract: Contract, finalPdf: StoredFile}
     */
    private function signedFixture(bool $enableBeforeSigning): array
    {
        $ctx = $this->registerValidSigner();
        $user = $ctx['user'];
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        app(FinalPdfGenerator::class)->generate($contract, (int) $user->id);

        if ($enableBeforeSigning) {
            $this->enable($contract)->assertSessionHas('success');
            app(ContractSigningService::class)->sign(new ContractSigningRequest((int) $contract->id));
        } else {
            // Legacy/imported signed state used only to prove the route cannot add a
            // token to immutable bytes. The current signing service correctly
            // rejects creating this state without active public verification.
            $pdf = $contract->fresh()->finalPdfFile;
            Signature::query()->create([
                'contract_id' => $contract->id,
                'contract_party_id' => null,
                'certificate_id' => $ctx['certificate']->id,
                'signed_user_id' => $user->id,
                'signed_customer_id' => null,
                'source_file_id' => $pdf->id,
                'signature_file_id' => null,
                'type' => Signature::TYPE_DIGITAL,
                'status' => Signature::STATUS_COMPLETED,
                'signed_at' => now(),
                'document_hash_before' => $pdf->sha256,
                'document_hash_after' => $pdf->sha256,
            ]);
        }

        $fresh = $contract->fresh();

        return ['user' => $user, 'contract' => $fresh, 'finalPdf' => StoredFile::findOrFail($fresh->final_pdf_file_id)];
    }

    public function test_signed_pdf_without_a_previously_embedded_token_cannot_enable_verification(): void
    {
        $f = $this->signedFixture(enableBeforeSigning: false);
        $before = [
            'bindingId' => (int) $f['contract']->final_pdf_file_id,
            'bindingSha' => (string) $f['contract']->final_pdf_sha256,
            'bytes' => Storage::disk(StoredFile::DISK_LOCAL)->get($f['finalPdf']->storage_path),
            'file' => $f['finalPdf']->only(['storage_path', 'size_bytes', 'sha256']),
        ];
        $signature = Signature::query()->where('status', Signature::STATUS_COMPLETED)->firstOrFail();

        $this->enable($f['contract'])->assertRedirect()->assertSessionHas('error');

        // Fails closed: no token activated, nothing about the signed artefact moved.
        $fresh = $f['contract']->fresh();
        $this->assertNull($fresh->public_verification_token);
        $this->assertNull($fresh->public_verification_enabled_at);
        $this->assertSame($before['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertSame($before['bindingSha'], (string) $fresh->final_pdf_sha256);
        $this->assertSame($before['file'], StoredFile::findOrFail($before['bindingId'])->only(['storage_path', 'size_bytes', 'sha256']));
        $this->assertSame($before['bytes'], Storage::disk(StoredFile::DISK_LOCAL)->get($f['finalPdf']->storage_path));
        $this->assertSame($signature->only(['id', 'source_file_id', 'signature_file_id', 'status']), Signature::findOrFail($signature->id)->only(['id', 'source_file_id', 'signature_file_id', 'status']));
        $this->assertSame(0, AuditEvent::query()->where('action', 'contract.public_verification_enabled')->count());
    }

    public function test_signed_pdf_with_an_embedded_token_reuses_it_idempotently(): void
    {
        $f = $this->signedFixture(enableBeforeSigning: true);
        $token = $f['contract']->public_verification_token;
        $before = [
            'bindingId' => (int) $f['contract']->final_pdf_file_id,
            'bytes' => Storage::disk(StoredFile::DISK_LOCAL)->get($f['finalPdf']->storage_path),
            'file' => $f['finalPdf']->only(['storage_path', 'size_bytes', 'sha256']),
        ];
        $signature = Signature::query()->where('status', Signature::STATUS_COMPLETED)->firstOrFail();
        $pdfCountBefore = $this->finalPdfCount();

        // Re-enabling the already-embedded token is idempotent: no regeneration.
        $this->enable($f['contract'])->assertRedirect()->assertSessionHas('success');

        $fresh = $f['contract']->fresh();
        $this->assertSame($token, $fresh->public_verification_token);
        $this->assertSame($before['bindingId'], (int) $fresh->final_pdf_file_id);
        $this->assertSame($before['file'], StoredFile::findOrFail($before['bindingId'])->only(['storage_path', 'size_bytes', 'sha256']));
        $this->assertSame($before['bytes'], Storage::disk(StoredFile::DISK_LOCAL)->get($f['finalPdf']->storage_path));
        $this->assertSame($pdfCountBefore, $this->finalPdfCount(), 'no new final PDF may be produced');
        $this->assertSame($signature->only(['id', 'source_file_id', 'signature_file_id', 'status']), Signature::findOrFail($signature->id)->only(['id', 'source_file_id', 'signature_file_id', 'status']));

        $audit = AuditEvent::query()->where('action', 'contract.public_verification_enabled')->latest('id')->firstOrFail();
        $this->assertFalse($audit->metadata['final_pdf_regenerated']);
    }

    public function test_non_owner_cannot_enable_public_verification(): void
    {
        $f = $this->fixture();
        app(FinalPdfGenerator::class)->generate($f['contract'], (int) $f['user']->id);
        $this->actingAs(User::factory()->create());

        $this->enable($f['contract'])->assertForbidden();
        $this->assertNull($f['contract']->fresh()->public_verification_token);
    }
}
