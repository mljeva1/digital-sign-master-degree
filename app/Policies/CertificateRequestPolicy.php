<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CertificateRequest;
use App\Models\User;
use App\Services\CertificateRequests\CertificateRequestWorkflow;

/**
 * M14 authorization.
 *
 * Two disjoint capabilities:
 *  - the SUBJECT may submit for themselves, view their own request and cancel
 *    their own still-pending request;
 *  - an EXACT certificate_operator may read the inbox and review OTHER people's
 *    pending requests.
 *
 * There is no admin bypass: `admin` (or `employee`) without the exact
 * certificate_operator role has no operator capability at all. Hiding a link in
 * the UI is never authorization — every operator route is additionally behind
 * the `role:certificate_operator` middleware, and the workflow service re-proves
 * the operator role inside its transaction.
 */
class CertificateRequestPolicy
{
    private function isOperator(User $user): bool
    {
        return $user->hasRole(CertificateRequestWorkflow::OPERATOR_ROLE);
    }

    /** Anyone authenticated may see their own request list. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** The subject sees their own; an operator sees any (inbox detail). */
    public function view(User $user, CertificateRequest $request): bool
    {
        return (int) $request->user_id === (int) $user->getKey()
            || $this->isOperator($user);
    }

    /** Submitting is always "for myself"; the subject is never taken from input. */
    public function create(User $user): bool
    {
        return true;
    }

    /** Only the subject, and only while still pending. */
    public function cancel(User $user, CertificateRequest $request): bool
    {
        return (int) $request->user_id === (int) $user->getKey()
            && $request->isPending();
    }

    public function viewInbox(User $user): bool
    {
        return $this->isOperator($user);
    }

    /**
     * Review (approve/reject) requires the exact operator role, a pending
     * request, and a subject who is NOT the operator themselves.
     */
    public function review(User $user, CertificateRequest $request): bool
    {
        return $this->isOperator($user)
            && $request->isPending()
            && (int) $request->user_id !== (int) $user->getKey();
    }
}
