<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Http\Requests\StoreCertificateRequestRequest;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Services\CertificateRequests\CertificateRequestWorkflow;
use App\Support\CertificateRequests\CertificateRequestPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Subject-facing certificate requests (M14 Phase B, Blade UI).
 *
 * Thin by design: it authorizes, hands the workflow service the model and the
 * authenticated user, and renders. It never decides a transition, never writes an
 * audit event, never opens a transaction, and never takes the subject, status,
 * attempt id or certificate id from the payload. GET renders a Blade view; a
 * successful POST/PATCH redirects with a flash; a refusal redirects back with a
 * neutral error flash (only stable codes are mapped to copy — never a raw error).
 */
class CertificateRequestController extends Controller
{
    public function __construct(private readonly CertificateRequestWorkflow $workflow) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CertificateRequest::class);

        $user = $request->user();

        $requests = CertificateRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(15);

        $hasBlockingCertificate = $this->workflow->hasBlockingCertificate((int) $user->id);
        $activeRequest = $this->workflow->activeRequestFor((int) $user->id);

        return view('certificate-requests.index', [
            'requests' => $requests,
            'activeCertificate' => $this->activeCertificateFor((int) $user->id),
            'hasBlockingCertificate' => $hasBlockingCertificate,
            'activeRequest' => $activeRequest,
            'canCreate' => ! $hasBlockingCertificate && $activeRequest === null,
        ]);
    }

    public function store(StoreCertificateRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', CertificateRequest::class);

        try {
            // The subject is ALWAYS the authenticated user.
            $this->workflow->create($request->user(), $request->requestNote());
        } catch (WorkflowException $e) {
            return redirect()
                ->route('certificate-requests.index')
                ->with('error', CertificateRequestPresenter::refusalMessage($e->errorCode()));
        }

        return redirect()
            ->route('certificate-requests.index')
            ->with('success', 'Zahtjev za certifikat je zaprimljen.');
    }

    public function show(Request $request, CertificateRequest $certificateRequest): View
    {
        $this->authorize('view', $certificateRequest);

        $certificate = $certificateRequest->certificate_id === null
            ? null
            : Certificate::query()->find($certificateRequest->certificate_id);

        return view('certificate-requests.show', [
            'request' => $certificateRequest,
            'certificate' => $certificate,
        ]);
    }

    public function cancel(Request $request, CertificateRequest $certificateRequest): RedirectResponse
    {
        $this->authorize('cancel', $certificateRequest);

        try {
            $this->workflow->cancel($certificateRequest, $request->user());
        } catch (WorkflowException $e) {
            return redirect()
                ->route('certificate-requests.index')
                ->with('error', CertificateRequestPresenter::refusalMessage($e->errorCode()));
        }

        return redirect()
            ->route('certificate-requests.index')
            ->with('success', 'Zahtjev je otkazan.');
    }

    private function activeCertificateFor(int $userId): ?Certificate
    {
        return Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $userId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }
}
