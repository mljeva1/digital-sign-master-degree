<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Signing\ContractSigningRequest;
use App\Services\Signing\ContractSigningService;
use Illuminate\Support\Facades\Storage;

/**
 * Owner-only .p7s download: exact bytes, verified integrity, safe headers, and
 * fail-closed behaviour for every missing/tampered/mismatched artefact state.
 */
final class CmsSignatureDownloadTest extends ContractSigningTestCase
{
    /**
     * @return array{user: User, contract: Contract, finalPdf: StoredFile, cms: StoredFile, signature: Signature}
     */
    private function signedFixture(): array
    {
        $ctx = $this->registerValidSigner();
        $user = $ctx['user'];
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes('download'));
        $this->activatePublicVerification($contract, $finalPdf);

        app(ContractSigningService::class)->sign(new ContractSigningRequest((int) $contract->id));

        $signature = Signature::query()->sole();
        $cms = StoredFile::query()->findOrFail($signature->signature_file_id);

        return ['user' => $user, 'contract' => $contract, 'finalPdf' => $finalPdf, 'cms' => $cms, 'signature' => $signature];
    }

    private function download(Contract $contract)
    {
        return $this->get(route('contracts.signature.download', $contract));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $f = $this->signedFixture();
        auth()->logout();

        $this->download($f['contract'])->assertRedirect(route('login'));
    }

    public function test_foreign_user_is_forbidden(): void
    {
        $f = $this->signedFixture();
        $this->actingAs(User::factory()->create());

        $this->download($f['contract'])->assertForbidden();
    }

    public function test_owner_downloads_exact_cms_bytes_with_safe_headers(): void
    {
        $f = $this->signedFixture();
        $expected = Storage::disk(StoredFile::DISK_LOCAL)->get($f['cms']->storage_path);

        $response = $this->download($f['contract'])->assertOk();

        $this->assertSame($expected, $response->getContent());
        $response->assertHeader('Content-Type', 'application/pkcs7-signature');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="ugovor-'.$f['contract']->id.'-potpis.p7s"'
        );
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        // No private path anywhere in the response.
        $this->assertStringNotContainsString($f['cms']->storage_path, implode(' ', array_map(
            static fn (array $values): string => implode(' ', $values),
            $response->headers->all(),
        )));
    }

    public function test_download_mutates_nothing_and_regenerates_nothing(): void
    {
        $f = $this->signedFixture();
        $before = [
            'signature' => $f['signature']->fresh()->only(['id', 'status', 'source_file_id', 'signature_file_id', 'document_hash_before', 'document_hash_after']),
            'p7s' => $this->p7sCount(),
            'pdf' => $this->finalPdfCount(),
            'files' => StoredFile::query()->count(),
        ];

        $this->download($f['contract'])->assertOk();

        $this->assertSame($before['signature'], $f['signature']->fresh()->only(['id', 'status', 'source_file_id', 'signature_file_id', 'document_hash_before', 'document_hash_after']));
        $this->assertSame($before['p7s'], $this->p7sCount());
        $this->assertSame($before['pdf'], $this->finalPdfCount());
        $this->assertSame($before['files'], StoredFile::query()->count());
    }

    public function test_unsigned_contract_returns_404(): void
    {
        $ctx = $this->registerValidSigner();
        $this->actingAs($ctx['user']);
        $contract = $this->seedContract($ctx['user']);
        $this->attachFinalPdf($contract, $ctx['user'], $this->pdfBytes('unsigned'));

        $this->download($contract)->assertNotFound();
    }

    public function test_missing_physical_cms_fails_closed(): void
    {
        $f = $this->signedFixture();
        Storage::disk(StoredFile::DISK_LOCAL)->delete($f['cms']->storage_path);

        $this->download($f['contract'])->assertNotFound();
    }

    public function test_tampered_cms_fails_closed(): void
    {
        $f = $this->signedFixture();
        Storage::disk(StoredFile::DISK_LOCAL)->put($f['cms']->storage_path, 'TAMPERED-CMS-BYTES');

        $this->download($f['contract'])->assertNotFound();
    }

    public function test_wrong_purpose_on_cms_record_fails_closed(): void
    {
        $f = $this->signedFixture();
        $f['cms']->purpose = StoredFile::PURPOSE_USER_UPLOAD;
        $f['cms']->save();

        $this->download($f['contract'])->assertNotFound();
    }

    public function test_signature_for_replaced_final_pdf_binding_fails_closed(): void
    {
        $f = $this->signedFixture();
        // The contract now points at a DIFFERENT final PDF than the one signed.
        $other = $this->attachFinalPdf($f['contract'], $f['user'], $this->pdfBytes('other-binding'));
        $this->assertNotSame((int) $f['finalPdf']->id, (int) $other->id);

        $this->download($f['contract'])->assertNotFound();
    }

    public function test_missing_final_pdf_binding_fails_closed(): void
    {
        $f = $this->signedFixture();
        $f['contract']->final_pdf_file_id = null;
        $f['contract']->final_pdf_sha256 = null;
        $f['contract']->save();

        $this->download($f['contract'])->assertNotFound();
    }

    public function test_download_is_audited_without_path(): void
    {
        $f = $this->signedFixture();

        $this->download($f['contract'])->assertOk();

        $event = AuditEvent::query()
            ->where('action', 'contract.cms_signature_downloaded')
            ->latest('id')
            ->firstOrFail();
        $json = json_encode($event->metadata, JSON_THROW_ON_ERROR);
        $this->assertSame((int) $f['cms']->id, (int) $event->metadata['file_id']);
        $this->assertStringNotContainsString($f['cms']->storage_path, $json);
    }
}
