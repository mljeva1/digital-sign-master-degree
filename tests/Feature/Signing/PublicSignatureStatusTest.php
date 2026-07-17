<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Models\Certificate;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Signing\ContractSignatureStatusService;
use App\Services\Signing\ContractSigningRequest;
use App\Services\Signing\ContractSigningService;
use Illuminate\Support\Facades\Storage;

/**
 * Persisted-signature verification on the PUBLIC verification page and in the
 * read-only ContractSignatureStatusService: separate integrity/crypto/trust
 * signals, fail-closed on every tamper/mismatch, and strictly no mutation.
 */
final class PublicSignatureStatusTest extends ContractSigningTestCase
{
    /**
     * @return array{user: User, contract: Contract, finalPdf: StoredFile, token: string, signature: Signature, cms: StoredFile}
     */
    private function signedFixture(): array
    {
        $ctx = $this->registerValidSigner();
        $user = $ctx['user'];
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes('public-status'));
        $token = $this->activatePublicVerification($contract, $finalPdf);

        app(ContractSigningService::class)->sign(new ContractSigningRequest((int) $contract->id));
        auth()->logout();

        $signature = Signature::query()->sole();
        $cms = StoredFile::query()->findOrFail($signature->signature_file_id);

        return ['user' => $user, 'contract' => $contract, 'finalPdf' => $finalPdf, 'token' => $token, 'signature' => $signature, 'cms' => $cms];
    }

    private function statusService(): ContractSignatureStatusService
    {
        return app(ContractSignatureStatusService::class);
    }

    private function showPublic(string $token)
    {
        return $this->get(route('public.contracts.verify.show', $token));
    }

    // --- unsigned --------------------------------------------------------------

    public function test_unsigned_final_pdf_shows_no_signature_yet_neutrally(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes('unsigned-public'));
        $token = $this->activatePublicVerification($contract, $finalPdf);
        auth()->logout();

        $html = $this->showPublic($token)->assertOk()->getContent();

        $this->assertStringContainsString('Dokument još nema dovršen digitalni potpis.', $html);
        // Not a verification failure: no failing signal is rendered.
        $this->assertStringNotContainsString('Neuspješna', $html);
    }

    // --- signed, valid -----------------------------------------------------------

    public function test_signed_contract_shows_separate_valid_signals(): void
    {
        $f = $this->signedFixture();

        $html = $this->showPublic($f['token'])->assertOk()->getContent();

        $this->assertStringContainsString('Integritet finalnog PDF-a', $html);
        $this->assertStringContainsString('Integritet potpisnog artefakta', $html);
        $this->assertStringContainsString('Kriptografska provjera potpisa', $html);
        $this->assertStringContainsString('Povjerenje (lokalni testni Root CA)', $html);
        $this->assertStringContainsString('Vremenska valjanost certifikata', $html);
        $this->assertStringContainsString('class="signal-ok"', $html);
        $this->assertStringNotContainsString('class="signal-fail"', $html);
        $this->assertStringContainsString('nije PAdES, eIDAS niti', $html);

        // No secret/path/DER leaks into public HTML.
        $this->assertStringNotContainsString($f['cms']->storage_path, $html);
        $this->assertStringNotContainsString((string) $f['finalPdf']->storage_path, $html);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $html);
    }

    public function test_service_reports_every_signal_true_for_a_valid_signature(): void
    {
        $f = $this->signedFixture();

        $status = $this->statusService()->status($f['contract']->fresh());

        $this->assertTrue($status->signaturePresent);
        $this->assertFalse($status->verificationUnavailable);
        $this->assertTrue($status->pdfIntegrityValid);
        $this->assertTrue($status->cmsIntegrityValid);
        $this->assertTrue($status->cryptographicValid);
        $this->assertTrue($status->trustValid);
        $this->assertTrue($status->certificateTimeValid);
        $this->assertTrue($status->certificateActive);
        $this->assertTrue($status->signerFingerprintMatches);
        $this->assertTrue($status->sourceHashMatches);
        $this->assertTrue($status->overall);
        $this->assertNotNull($status->signedAtIso);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $status->certificateFingerprint);
    }

    // --- fail closed -------------------------------------------------------------

    public function test_tampered_final_pdf_fails_closed(): void
    {
        $f = $this->signedFixture();
        Storage::disk(StoredFile::DISK_LOCAL)->put($f['finalPdf']->storage_path, '%PDF tampered');

        $status = $this->statusService()->status($f['contract']->fresh());
        $this->assertTrue($status->signaturePresent);
        $this->assertFalse($status->pdfIntegrityValid);
        $this->assertFalse($status->overall);

        $html = $this->showPublic($f['token'])->assertOk()->getContent();
        $this->assertStringContainsString('class="signal-fail"', $html);
    }

    public function test_tampered_cms_artefact_fails_closed(): void
    {
        $f = $this->signedFixture();
        Storage::disk(StoredFile::DISK_LOCAL)->put($f['cms']->storage_path, "\x30\x82TAMPERED");

        $status = $this->statusService()->status($f['contract']->fresh());

        $this->assertTrue($status->signaturePresent);
        $this->assertTrue($status->pdfIntegrityValid);
        $this->assertFalse($status->cmsIntegrityValid);
        $this->assertFalse($status->overall);
    }

    public function test_missing_cms_artefact_fails_closed(): void
    {
        $f = $this->signedFixture();
        Storage::disk(StoredFile::DISK_LOCAL)->delete($f['cms']->storage_path);

        $status = $this->statusService()->status($f['contract']->fresh());

        $this->assertTrue($status->signaturePresent);
        $this->assertFalse($status->cmsIntegrityValid);
        $this->assertFalse($status->overall);
    }

    public function test_deactivated_certificate_is_not_a_trusted_success(): void
    {
        $f = $this->signedFixture();
        Certificate::query()->whereKey($f['signature']->certificate_id)->update(['is_active' => false]);

        $status = $this->statusService()->status($f['contract']->fresh());

        $this->assertTrue($status->cryptographicValid, 'crypto stays independently reported');
        $this->assertFalse($status->certificateActive);
        $this->assertFalse($status->overall);
    }

    public function test_wrong_signer_fingerprint_fails_closed(): void
    {
        $f = $this->signedFixture();
        Certificate::query()->whereKey($f['signature']->certificate_id)
            ->update(['thumbprint_sha256' => str_repeat('a', 64)]);

        $status = $this->statusService()->status($f['contract']->fresh());

        $this->assertFalse($status->signerFingerprintMatches);
        $this->assertFalse($status->overall);
    }

    public function test_foreign_cms_binding_gives_no_success(): void
    {
        $f = $this->signedFixture();
        // Bind the signature to a DIFFERENT (foreign) artefact record.
        $foreignDer = "\x30\x82".random_bytes(24);
        $path = 'contracts/'.$f['contract']->id.'/signatures/foreign-'.bin2hex(random_bytes(4)).'.p7s';
        Storage::disk(StoredFile::DISK_LOCAL)->put($path, $foreignDer);
        $foreign = StoredFile::create([
            'purpose' => StoredFile::PURPOSE_CMS_SIGNATURE, 'storage_disk' => StoredFile::DISK_LOCAL, 'storage_path' => $path,
            'original_filename' => 'foreign.p7s', 'mime_type' => 'application/pkcs7-signature',
            'size_bytes' => strlen($foreignDer), 'sha256' => hash('sha256', $foreignDer), 'created_by_user_id' => $f['user']->id,
        ]);
        Signature::query()->whereKey($f['signature']->id)->update(['signature_file_id' => $foreign->id]);

        $status = $this->statusService()->status($f['contract']->fresh());

        // The foreign DER is intact per its own record but cannot verify the PDF.
        $this->assertFalse($status->cryptographicValid);
        $this->assertFalse($status->overall);
    }

    public function test_signature_bound_to_older_replaced_pdf_is_not_presented(): void
    {
        $f = $this->signedFixture();
        $this->actingAs($f['user']);
        $newPdf = $this->attachFinalPdf($f['contract'], $f['user'], $this->pdfBytes('replaced'));
        auth()->logout();
        $this->assertNotSame((int) $f['finalPdf']->id, (int) $newPdf->id);

        $status = $this->statusService()->status($f['contract']->fresh());

        $this->assertFalse($status->signaturePresent, 'the CURRENT final PDF has no signature');
    }

    public function test_unconfigured_trust_anchor_is_unavailable_never_valid(): void
    {
        $f = $this->signedFixture();
        config(['signing.root_ca_path' => null]);

        $status = $this->statusService()->status($f['contract']->fresh());
        $this->assertTrue($status->signaturePresent);
        $this->assertTrue($status->verificationUnavailable);
        $this->assertFalse($status->overall);

        $html = $this->showPublic($f['token'])->assertOk()->getContent();
        $this->assertStringContainsString('provjeru potpisa trenutno nije', $html);
        $this->assertStringNotContainsString('class="signal-ok"', $html);
    }

    // --- read-only guarantee -------------------------------------------------------

    public function test_public_view_and_service_mutate_nothing(): void
    {
        $f = $this->signedFixture();
        $contractState = static fn (Contract $c): array => [
            'final_pdf_file_id' => (int) $c->final_pdf_file_id,
            'final_pdf_sha256' => (string) $c->final_pdf_sha256,
            'public_verification_token' => (string) $c->public_verification_token,
            'public_verification_enabled_at' => $c->public_verification_enabled_at?->toIso8601String(),
            'public_verification_revoked_at' => $c->public_verification_revoked_at?->toIso8601String(),
        ];
        $before = [
            'contract' => $contractState($f['contract']->fresh()),
            'signature' => $f['signature']->fresh()->only(['id', 'status', 'source_file_id', 'signature_file_id']),
            'files' => StoredFile::query()->count(),
            'signatures' => Signature::query()->count(),
            'p7s' => $this->p7sCount(),
            'pdf' => $this->finalPdfCount(),
        ];

        $this->showPublic($f['token'])->assertOk();
        $this->statusService()->status($f['contract']->fresh());

        $this->assertSame($before['contract'], $contractState($f['contract']->fresh()));
        $this->assertSame($before['signature'], $f['signature']->fresh()->only(['id', 'status', 'source_file_id', 'signature_file_id']));
        $this->assertSame($before['files'], StoredFile::query()->count());
        $this->assertSame($before['signatures'], Signature::query()->count());
        $this->assertSame($before['p7s'], $this->p7sCount());
        $this->assertSame($before['pdf'], $this->finalPdfCount());
    }

    public function test_invalid_token_still_returns_404_without_signature_details(): void
    {
        $this->signedFixture();

        $this->get(route('public.contracts.verify.show', str_repeat('x', 64)))->assertNotFound();
    }
}
