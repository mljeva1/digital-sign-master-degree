<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\Audit\AuditLogger;
use Illuminate\View\View;

final class PublicContractVerificationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function show(string $token): View
    {
        $contract = Contract::query()
            ->where('public_verification_token', $token)
            ->whereNotNull('public_verification_enabled_at')
            ->whereNull('public_verification_revoked_at')
            ->where('status', Contract::STATUS_FINALIZED)
            ->whereNotNull('locked_at')
            ->firstOrFail();

        $this->auditLogger->record('contract.public_verification_viewed', $contract, [
            'route_name' => 'public.contracts.verify.show',
            'viewed_at' => now()->toIso8601String(),
            'public' => true,
        ]);

        return view('public.contracts.verify.show', [
            'contract' => $contract,
        ]);
    }
}
