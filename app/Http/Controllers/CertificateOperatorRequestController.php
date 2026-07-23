<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Http\Requests\RejectCertificateRequestRequest;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Services\CertificateRequests\CertificateRequestWorkflow;
use App\Support\CertificateRequests\CertificateRequestPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Operator inbox (M14 Phase B, Blade UI).
 *
 * Every route here is behind auth + role:certificate_operator middleware AND a
 * policy check; the workflow service then re-proves the operator role and the
 * self-review ban inside its locked transaction. Three independent layers, because
 * a hidden UI link is not authorization.
 *
 * The operator never supplies their own id, the subject id, a status or an attempt
 * id — the operator is the authenticated user and the rest is derived. The inbox
 * shows only the minimal decision data (subject id + lifecycle), never the note
 * text, e-mail, name, OIB, DN, serial, attempt UUID or path.
 */
class CertificateOperatorRequestController extends Controller
{
    public function __construct(private readonly CertificateRequestWorkflow $workflow) {}

    public function index(Request $request): View
    {
        $this->authorize('viewInbox', CertificateRequest::class);

        $query = CertificateRequest::query();

        // Allow-listed filter only: an unknown status is ignored, never
        // interpolated into the query.
        $status = $request->query('status');
        $activeFilter = is_string($status) && Status::isValid($status) ? $status : null;
        if ($activeFilter !== null) {
            $query->where('status', $activeFilter);
        }

        $requests = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return view('certificate-operator.requests.index', [
            'requests' => $requests,
            'statusFilter' => $activeFilter,
            'statuses' => Status::all(),
        ]);
    }

    public function show(Request $request, CertificateRequest $certificateRequest): View
    {
        $this->authorize('viewInbox', CertificateRequest::class);

        $certificate = $certificateRequest->certificate_id === null
            ? null
            : Certificate::query()->find($certificateRequest->certificate_id);

        return view('certificate-operator.requests.show', [
            'request' => $certificateRequest,
            'certificate' => $certificate,
            'canReview' => $certificateRequest->isPending()
                && (int) $certificateRequest->user_id !== (int) $request->user()->getKey(),
        ]);
    }

    public function approve(Request $request, CertificateRequest $certificateRequest): RedirectResponse
    {
        $this->authorize('review', $certificateRequest);

        try {
            $this->workflow->approve($certificateRequest, $request->user());
        } catch (WorkflowException $e) {
            return $this->refusal($certificateRequest, $e);
        }

        return redirect()
            ->route('certificate-operator.requests.show', $certificateRequest)
            ->with('success', 'Zahtjev je odobren i predan na izdavanje.');
    }

    public function reject(RejectCertificateRequestRequest $request, CertificateRequest $certificateRequest): RedirectResponse
    {
        $this->authorize('review', $certificateRequest);

        try {
            $this->workflow->reject($certificateRequest, $request->user(), $request->operatorNote());
        } catch (WorkflowException $e) {
            return $this->refusal($certificateRequest, $e);
        }

        return redirect()
            ->route('certificate-operator.requests.show', $certificateRequest)
            ->with('success', 'Zahtjev je odbijen.');
    }

    private function refusal(CertificateRequest $request, WorkflowException $e): RedirectResponse
    {
        return redirect()
            ->route('certificate-operator.requests.show', $request)
            ->with('error', CertificateRequestPresenter::refusalMessage($e->errorCode()));
    }
}
