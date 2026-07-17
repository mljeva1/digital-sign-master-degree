<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\ContractSigningException;
use App\Models\Certificate;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Signing\ContractSigningFailurePresenter;
use App\Services\Signing\SignerCertificateStatus;
use App\Services\Signing\SignerCertificateStatusService;
use Illuminate\Support\Carbon;

/**
 * Owner-facing signer-certificate preflight: the user learns BEFORE clicking
 * whether signing is available, an unusable certificate is never shown as
 * active, and the server still fails closed when the UI hint is bypassed.
 */
final class SignerCertificatePreflightTest extends ContractSigningTestCase
{
    private function statusService(): SignerCertificateStatusService
    {
        return app(SignerCertificateStatusService::class);
    }

    private function indexHtml(): string
    {
        return $this->get(route('contracts.index'))->assertOk()->getContent();
    }

    /** Blade wraps prose across lines; compare on collapsed whitespace. */
    private function normalized(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    /** A finalized, publicly-verified contract whose ONLY missing piece may be the cert. */
    private function readyContractFor(User $user): void
    {
        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes('preflight'));
        $this->activatePublicVerification($contract, $finalPdf);
    }

    // --- no certificate --------------------------------------------------------

    public function test_user_without_certificate_sees_clear_message_and_no_sign_button(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->readyContractFor($user);

        $html = $this->indexHtml();

        $this->assertStringContainsString(
            'Potpisivanje trenutno nije dostupno jer na vašem računu nije registriran aktivni potpisni certifikat.',
            $this->normalized($html)
        );
        $this->assertStringContainsString('data-testid="signing-no-certificate"', $html);
        $this->assertStringNotContainsString('Potpiši dokument', $html);
        $this->assertStringNotContainsString(route('contracts.sign.store', 1), $html);
    }

    public function test_status_service_reports_missing_for_user_without_certificate(): void
    {
        $user = User::factory()->create();

        $status = $this->statusService()->forUser((int) $user->id);

        $this->assertSame(SignerCertificateStatus::STATE_MISSING, $status->state);
        $this->assertFalse($status->usable());
        $this->assertNull($status->fingerprint);
        $this->assertSame('Nije registriran', $status->label());
    }

    // --- active certificate ----------------------------------------------------

    public function test_user_with_active_certificate_sees_sign_button_and_safe_summary(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);
        $this->readyContractFor($ctx['user']);

        $html = $this->indexHtml();

        $this->assertStringContainsString('Potpiši dokument', $html);
        $this->assertStringContainsString('data-testid="signer-certificate-panel"', $html);
        $this->assertStringContainsString('Aktivan', $html);
        $this->assertStringContainsString('Nositelj (CN)', $html);
        $this->assertStringContainsString('Izdavatelj (CN)', $html);
        $this->assertStringNotContainsString('data-testid="signing-no-certificate"', $html);
    }

    public function test_status_service_reports_active_with_public_metadata_only(): void
    {
        $ctx = $this->registerValidSigner();

        $status = $this->statusService()->forUser((int) $ctx['user']->id);

        $this->assertSame(SignerCertificateStatus::STATE_ACTIVE, $status->state);
        $this->assertTrue($status->usable());
        $this->assertSame('Aktivan', $status->label());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $status->fingerprint);
        $this->assertSame('M10 Test Signer', $status->subjectCommonName);
        $this->assertSame('M10 Test Root CA', $status->issuerCommonName);
        $this->assertNotNull($status->validFrom);
        $this->assertNotNull($status->validTo);
    }

    public function test_certificate_panel_never_exposes_secret_material(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);
        $this->readyContractFor($ctx['user']);

        $html = $this->indexHtml();
        $certFile = StoredFile::query()->where('purpose', StoredFile::PURPOSE_CERTIFICATE)->sole();

        $this->assertStringNotContainsString($certFile->storage_path, $html);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $html);
        $this->assertStringNotContainsString('BEGIN ENCRYPTED PRIVATE KEY', $html);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $html);
        $this->assertStringNotContainsString(self::PASSPHRASE, $html);
        $this->assertStringNotContainsString($this->tempDir, $html);
        $this->assertStringNotContainsString('signing/certificates/', $html);
    }

    // --- unusable states -------------------------------------------------------

    public function test_deactivated_certificate_is_not_presented_as_active(): void
    {
        $ctx = $this->registerValidSigner();
        Certificate::query()->whereKey($ctx['certificate']->id)->update(['is_active' => false]);
        $this->actingAs($ctx['user']);
        $this->readyContractFor($ctx['user']);

        $status = $this->statusService()->forUser((int) $ctx['user']->id);
        $this->assertSame(SignerCertificateStatus::STATE_INACTIVE, $status->state);
        $this->assertFalse($status->usable());
        $this->assertSame('Opozvan ili deaktiviran', $status->label());

        $html = $this->indexHtml();
        $this->assertStringNotContainsString('Potpiši dokument', $html);
        $this->assertStringContainsString('data-testid="signing-no-certificate"', $html);
    }

    public function test_expired_certificate_is_not_presented_as_active(): void
    {
        $ctx = $this->registerValidSigner();
        Certificate::query()->whereKey($ctx['certificate']->id)->update([
            'valid_from' => Carbon::now()->subYears(3),
            'valid_to' => Carbon::now()->subDay(),
        ]);
        $this->actingAs($ctx['user']);
        $this->readyContractFor($ctx['user']);

        $status = $this->statusService()->forUser((int) $ctx['user']->id);
        $this->assertSame(SignerCertificateStatus::STATE_EXPIRED, $status->state);
        $this->assertFalse($status->usable());
        $this->assertSame('Istekao', $status->label());

        $this->assertStringNotContainsString('Potpiši dokument', $this->indexHtml());
    }

    // --- multiple active certificates ------------------------------------------

    /**
     * Issue a SECOND active certificate for the same owner, reusing the first
     * record's shape so it is a genuine candidate for the real selector.
     */
    private function duplicateActiveCertificate(Certificate $source): Certificate
    {
        $clone = $source->replicate();
        $clone->thumbprint_sha256 = hash('sha256', 'duplicate-'.$source->id.'-'.uniqid('', true));
        $clone->is_active = true;
        $clone->save();

        return $clone;
    }

    public function test_two_active_certificates_are_reported_as_ambiguous_without_picking_one(): void
    {
        $ctx = $this->registerValidSigner();
        $second = $this->duplicateActiveCertificate($ctx['certificate']);

        $status = $this->statusService()->forUser((int) $ctx['user']->id);

        $this->assertSame(SignerCertificateStatus::STATE_AMBIGUOUS, $status->state);
        $this->assertFalse($status->usable(), 'canSign must be false');
        $this->assertSame('Više aktivnih certifikata', $status->label());

        // No candidate's metadata may be presented as the selected one.
        $this->assertNull($status->fingerprint);
        $this->assertNull($status->subjectCommonName);
        $this->assertNull($status->issuerCommonName);
        $this->assertNull($status->validFrom);
        $this->assertNull($status->validTo);
        $this->assertNull($status->label);

        $this->assertSame(2, Certificate::query()->where('is_active', true)->count());
        $this->assertNotSame($ctx['certificate']->thumbprint_sha256, $second->thumbprint_sha256);
    }

    public function test_ui_hides_the_sign_button_and_shows_a_safe_ambiguity_message(): void
    {
        $ctx = $this->registerValidSigner();
        $first = $ctx['certificate'];
        $second = $this->duplicateActiveCertificate($first);
        $this->actingAs($ctx['user']);
        $this->readyContractFor($ctx['user']);

        $html = $this->indexHtml();

        $this->assertStringContainsString(
            'Potpisivanje trenutno nije dostupno jer je na vašem računu registrirano više aktivnih potpisnih certifikata.',
            $this->normalized($html)
        );
        $this->assertStringContainsString('data-testid="signing-ambiguous-certificate"', $html);
        $this->assertStringNotContainsString('data-testid="signing-no-certificate"', $html);

        // No button, and no arbitrarily chosen candidate's identity on screen.
        $this->assertStringNotContainsString('Potpiši dokument', $html);
        $this->assertStringNotContainsString((string) $first->thumbprint_sha256, $html);
        $this->assertStringNotContainsString((string) $second->thumbprint_sha256, $html);
        $this->assertStringNotContainsString('Nositelj (CN)', $html);
        $this->assertStringNotContainsString('Izdavatelj (CN)', $html);

        // Internal record ids are never exposed.
        $this->assertStringNotContainsString('certificate-id', $html);
    }

    public function test_direct_post_with_two_active_certificates_fails_closed_with_a_safe_message(): void
    {
        $ctx = $this->registerValidSigner();
        $user = $ctx['user'];
        $this->duplicateActiveCertificate($ctx['certificate']);
        $this->actingAs($user);

        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes('ambiguous'));
        $this->activatePublicVerification($contract, $finalPdf);

        // The real M11 service is the authority when the UI is bypassed.
        $this->post(route('contracts.sign.store', $contract))
            ->assertRedirect(route('contracts.index'))
            ->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertSame(
            ContractSigningFailurePresenter::message(
                ContractSigningException::SIGNER_CERTIFICATE_AMBIGUOUS
            ),
            $error
        );

        // Nothing was signed, and the message leaks no internals.
        $this->assertSame(0, Signature::query()->count());
        $this->assertSame(0, $this->p7sCount());
        $this->assertStringNotContainsString('SIGNER_CERTIFICATE_AMBIGUOUS', $error);
        $this->assertStringNotContainsString($this->tempDir, $error);
        $this->assertStringNotContainsString('openssl', strtolower($error));
        $this->assertStringNotContainsString('Exception', $error);
    }

    /**
     * The UI selector must not be STRICTER than the real one: a deactivated
     * certificate is not a candidate for ContractSigningService, so it must not
     * manufacture ambiguity in the UI either.
     */
    public function test_deactivated_certificate_alongside_one_active_is_not_ambiguous(): void
    {
        $ctx = $this->registerValidSigner();
        $stale = $this->duplicateActiveCertificate($ctx['certificate']);
        Certificate::query()->whereKey($stale->id)->update(['is_active' => false]);
        $this->actingAs($ctx['user']);
        $this->readyContractFor($ctx['user']);

        $status = $this->statusService()->forUser((int) $ctx['user']->id);

        $this->assertSame(SignerCertificateStatus::STATE_ACTIVE, $status->state);
        $this->assertTrue($status->usable());

        $html = $this->indexHtml();
        $this->assertStringContainsString('Potpiši dokument', $html);
        $this->assertStringNotContainsString('data-testid="signing-ambiguous-certificate"', $html);
    }

    /**
     * ...and it must not be LOOSER either. ContractSigningService's candidate
     * query filters on is_active ONLY — it applies no validity filter — so an
     * expired-but-active certificate IS a second candidate there and really does
     * make signing fail closed. The preflight must agree rather than invent a
     * friendlier rule and offer a button the server refuses.
     */
    public function test_expired_but_active_certificate_alongside_one_active_is_ambiguous_like_the_service(): void
    {
        $ctx = $this->registerValidSigner();
        $expired = $this->duplicateActiveCertificate($ctx['certificate']);
        Certificate::query()->whereKey($expired->id)->update([
            'valid_from' => Carbon::now()->subYears(3),
            'valid_to' => Carbon::now()->subDay(),
        ]);
        $this->actingAs($ctx['user']);
        $contract = $this->seedContract($ctx['user']);
        $finalPdf = $this->attachFinalPdf($contract, $ctx['user'], $this->pdfBytes('expired-pair'));
        $this->activatePublicVerification($contract, $finalPdf);

        // Preflight says ambiguous...
        $this->assertSame(
            SignerCertificateStatus::STATE_AMBIGUOUS,
            $this->statusService()->forUser((int) $ctx['user']->id)->state
        );
        $this->assertStringNotContainsString('Potpiši dokument', $this->indexHtml());

        // ...and the real service agrees, which is the point of the assertion.
        $this->post(route('contracts.sign.store', $contract))->assertSessionHas('error');
        $this->assertSame(
            ContractSigningFailurePresenter::message(
                ContractSigningException::SIGNER_CERTIFICATE_AMBIGUOUS
            ),
            session('error')
        );
        $this->assertSame(0, Signature::query()->count());
    }

    public function test_certificate_status_is_scoped_to_the_owner(): void
    {
        $ctx = $this->registerValidSigner();
        $stranger = User::factory()->create();

        // A stranger sees no certificate metadata of another user.
        $status = $this->statusService()->forUser((int) $stranger->id);
        $this->assertSame(SignerCertificateStatus::STATE_MISSING, $status->state);
        $this->assertNull($status->fingerprint);

        $this->actingAs($stranger);
        $this->readyContractFor($stranger);
        $html = $this->indexHtml();

        $this->assertStringNotContainsString((string) $ctx['certificate']->thumbprint_sha256, $html);
        $this->assertStringNotContainsString('M10 Test Signer', $html);
        $this->assertStringContainsString('data-testid="signing-no-certificate"', $html);

        // The owner's own duplicate does not affect the stranger's status.
        $this->duplicateActiveCertificate($ctx['certificate']);
        $this->assertSame(
            SignerCertificateStatus::STATE_MISSING,
            $this->statusService()->forUser((int) $stranger->id)->state
        );
    }

    // --- the UI hint is never the authority ------------------------------------

    public function test_server_still_fails_closed_when_the_ui_hint_is_bypassed(): void
    {
        $ctx = $this->registerValidSigner();
        $user = $ctx['user'];
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes('bypass'));
        $this->activatePublicVerification($contract, $finalPdf);

        // Certificate removed AFTER the page would have rendered a button.
        Certificate::query()->whereKey($ctx['certificate']->id)->update(['is_active' => false]);

        // Posting straight to the route ignores the UI entirely.
        $this->post(route('contracts.sign.store', $contract))
            ->assertRedirect(route('contracts.index'))
            ->assertSessionHas('error');

        $this->assertSame(
            ContractSigningFailurePresenter::message(
                ContractSigningException::SIGNER_CERTIFICATE_MISSING
            ),
            session('error')
        );
        $this->assertSame(0, Signature::query()->count());
        $this->assertSame(0, $this->p7sCount());
    }
}
