<?php

namespace App\Http\Controllers;

use App\Exceptions\Signing\ContractSigningException;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Services\Audit\AuditLogger;
use App\Services\Signing\ContractSigningFailurePresenter;
use App\Services\Signing\ContractSigningRequest;
use App\Services\Signing\ContractSigningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * User-facing M12 boundary over the M11 ContractSigningService: one POST that
 * starts a detached CMS signing run, and one owner-only download of the
 * persisted .p7s artefact.
 *
 * This controller deliberately opens NO database transaction — the signing
 * service and the final-PDF generator own their transactions — and it never
 * accepts an actor id from the request: the signing actor is always resolved
 * from the authenticated guard inside the service. Failure responses expose
 * only fixed Croatian messages mapped from stable error codes; no exception
 * message, path, SQL, token, or OpenSSL detail can reach the session.
 */
class ContractSignatureController extends Controller
{
    public function __construct(
        private readonly ContractSigningService $signingService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);

        try {
            $result = $this->signingService->sign(
                new ContractSigningRequest((int) $contract->id)
            );
        } catch (ContractSigningException $e) {
            return redirect()
                ->route('contracts.index')
                ->with('error', ContractSigningFailurePresenter::message($e->errorCode()));
        } catch (Throwable) {
            // Never let a raw message reach the user; neutral fallback only.
            return redirect()
                ->route('contracts.index')
                ->with('error', ContractSigningFailurePresenter::fallbackMessage());
        }

        return redirect()
            ->route('contracts.index')
            ->with('success', $result->idempotentExisting
                ? 'Dokument je već digitalno potpisan; postojeći potpis je ponovno potvrđen.'
                : 'Dokument je digitalno potpisan (lokalni testni CMS/PKCS#7 potpis).');
    }

    /**
     * Owner-only download of the persisted detached CMS (.p7s) artefact for the
     * contract's CURRENT final PDF. The bytes are verified against the CMS
     * StoredFile record (size + SHA-256) before anything is sent; a missing,
     * tampered or mismatched artefact fails closed as 404 without revealing a
     * path. Nothing is mutated or regenerated.
     */
    public function download(Request $request, Contract $contract): Response
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->isFinalized(), 403);
        abort_if($contract->final_pdf_file_id === null, 404);

        $signature = Signature::query()
            ->where('contract_id', $contract->id)
            ->where('status', Signature::STATUS_COMPLETED)
            ->where('source_file_id', (int) $contract->final_pdf_file_id)
            ->whereNotNull('signature_file_id')
            ->orderByDesc('id')
            ->first();
        abort_if($signature === null, 404);

        $cms = $signature->signatureFile;
        abort_unless(
            $cms !== null
                && $cms->purpose === StoredFile::PURPOSE_CMS_SIGNATURE
                && $cms->storage_disk === StoredFile::DISK_LOCAL
                && filled($cms->storage_path),
            404
        );

        $disk = Storage::disk(StoredFile::DISK_LOCAL);
        $path = (string) $cms->storage_path;

        try {
            abort_unless($disk->exists($path), 404);
            $bytes = $disk->get($path);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable) {
            abort(404);
        }

        abort_unless(
            is_string($bytes)
                && strlen($bytes) === (int) $cms->size_bytes
                && hash_equals(strtolower((string) $cms->sha256), hash('sha256', $bytes)),
            404
        );

        $this->auditLogger->record('contract.cms_signature_downloaded', $contract, [
            'file_id' => $cms->id,
            'signature_id' => $signature->id,
            'purpose' => $cms->purpose,
            'downloaded_at' => now()->toIso8601String(),
        ]);

        // Safe filename derived only from the numeric contract id.
        $filename = 'ugovor-'.$contract->id.'-potpis.p7s';

        return response($bytes, 200, [
            'Content-Type' => 'application/pkcs7-signature',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
