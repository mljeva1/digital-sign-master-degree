<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateOperatorRequestController;
use App\Http\Controllers\CertificateRequestController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractSignatureController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PublicContractVerificationController;
use App\Http\Controllers\UserContractProfileController;
use App\Http\Controllers\VehicleCatalogController;
use App\Models\Contract;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Unauthenticated, but each view runs a real detached-CMS verification (two
// openssl_cms_verify calls + a temp workspace), so it is rate-limited to keep an
// anyone-with-a-token request from amplifying into unbounded crypto work. 20/min
// per IP is far above real human use (a visitor loads the page once, then hashes
// their PDF entirely in the browser) while capping automated hammering. The
// limiter keys on the IP only — the bearer-style token is never used as a key,
// so it cannot reach the cache store or any log.
Route::get('/verify/contracts/{token}', [PublicContractVerificationController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('public.contracts.verify.show');

Route::get('/login', function () {
    return redirect()
        ->route('home')
        ->with('auth_modal', 'login');
})->name('login');

Route::get('/register', function () {
    return redirect()
        ->route('home')
        ->with('auth_modal', 'register');
})->name('register');

Route::middleware('guest')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('register.store');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        $user = request()->user();
        $profile = $user->contractProfile;
        $autofillFields = [
            $profile?->first_name,
            $profile?->last_name,
            $profile?->oib,
            $profile?->address_line1,
            $profile?->postal_code,
            $profile?->city,
        ];
        $contractCounts = Contract::query()
            ->where('created_by_user_id', $user->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('dashboard', [
            'user' => $user,
            'profileReady' => collect($autofillFields)->every(fn ($value) => filled($value)),
            'draftCount' => (int) ($contractCounts[Contract::STATUS_DRAFT] ?? 0),
            'finalizedCount' => (int) ($contractCounts[Contract::STATUS_FINALIZED] ?? 0),
        ]);
    })->name('dashboard');

    Route::get('/documents', [DocumentController::class, 'index'])
        ->name('documents.index');

    Route::get('/documents/create', [DocumentController::class, 'create'])
        ->name('documents.create');

    Route::post('/documents', [DocumentController::class, 'store'])
        ->name('documents.store');

    Route::get('/documents/{file}', [DocumentController::class, 'show'])
        ->name('documents.show');

    Route::get('/documents/{file}/download', [DocumentController::class, 'download'])
        ->name('documents.download');

    Route::post('/documents/{file}/verify', [DocumentController::class, 'verify'])
        ->name('documents.verify');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');

    Route::get('/profile', [UserContractProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [UserContractProfileController::class, 'update'])
        ->name('profile.update');

    Route::get('/contracts', [ContractController::class, 'index'])
        ->name('contracts.index');

    Route::get('/contracts/create', [ContractController::class, 'create'])
        ->name('contracts.create');

    Route::get('/contracts/{contract}/builder', [ContractController::class, 'editBuilder'])
        ->name('contracts.builder.edit');

    Route::get('/contracts/{contract}/audit', [ContractController::class, 'audit'])
        ->name('contracts.audit.index');

    Route::patch('/contracts/{contract}/archive', [ContractController::class, 'archive'])
        ->name('contracts.archive');

    Route::post('/contracts/{contract}/draft-pdf', [ContractController::class, 'generateDraftPdf'])
        ->name('contracts.draft-pdf.store');

    Route::get('/contracts/{contract}/draft-pdf', [ContractController::class, 'showDraftPdf'])
        ->name('contracts.draft-pdf.show');

    Route::get('/contracts/{contract}/draft-pdf/verify', [ContractController::class, 'verifyDraftPdf'])
        ->name('contracts.draft-pdf.verify');

    Route::post('/contracts/{contract}/final-pdf', [ContractController::class, 'generateFinalPdf'])
        ->name('contracts.final-pdf.store');

    Route::get('/contracts/{contract}/final-pdf', [ContractController::class, 'showFinalPdf'])
        ->name('contracts.final-pdf.show');

    Route::get('/contracts/{contract}/final-pdf/verify', [ContractController::class, 'verifyFinalPdf'])
        ->name('contracts.final-pdf.verify');

    Route::post('/contracts/{contract}/public-verification', [ContractController::class, 'enablePublicVerification'])
        ->name('contracts.public-verification.enable');

    Route::post('/contracts/{contract}/sign', [ContractSignatureController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('contracts.sign.store');

    Route::get('/contracts/{contract}/signature/download', [ContractSignatureController::class, 'download'])
        ->name('contracts.signature.download');

    Route::get('/contracts/{contract}/validate-required-fields', [ContractController::class, 'validateRequiredFields'])
        ->name('contracts.required-fields.validate');

    Route::post('/contracts/{contract}/finalize', [ContractController::class, 'finalize'])
        ->name('contracts.finalize.store');

    Route::post('/contracts/snapshot', [ContractController::class, 'storeSnapshot'])
        ->name('contracts.snapshot.store');

    Route::get('/contracts/vehicle-sales-preview', [ContractController::class, 'vehicleSalesPreview'])
        ->name('contracts.vehicle-sales-preview');

    Route::get('/vehicle-catalog/search', [VehicleCatalogController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('vehicle-catalog.search');
});

// M14 — certificate requests (subject-facing). Every action is additionally
// authorized by CertificateRequestPolicy; the subject is always the
// authenticated user and is never taken from the payload.
Route::middleware('auth')->group(function () {
    Route::get('/certificate-requests', [CertificateRequestController::class, 'index'])
        ->name('certificate-requests.index');

    Route::post('/certificate-requests', [CertificateRequestController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('certificate-requests.store');

    Route::get('/certificate-requests/{certificateRequest}', [CertificateRequestController::class, 'show'])
        ->name('certificate-requests.show');

    Route::patch('/certificate-requests/{certificateRequest}/cancel', [CertificateRequestController::class, 'cancel'])
        ->name('certificate-requests.cancel');
});

// M14 — operator inbox. Role middleware is the first gate, the policy the
// second, and the workflow service re-proves the operator role under its lock.
// `admin` alone grants nothing here.
Route::middleware(['auth', 'role:certificate_operator'])->group(function () {
    Route::get('/certificate-operator/requests', [CertificateOperatorRequestController::class, 'index'])
        ->name('certificate-operator.requests.index');

    Route::get('/certificate-operator/requests/{certificateRequest}', [CertificateOperatorRequestController::class, 'show'])
        ->name('certificate-operator.requests.show');

    Route::post('/certificate-operator/requests/{certificateRequest}/approve', [CertificateOperatorRequestController::class, 'approve'])
        ->middleware('throttle:12,1')
        ->name('certificate-operator.requests.approve');

    Route::post('/certificate-operator/requests/{certificateRequest}/reject', [CertificateOperatorRequestController::class, 'reject'])
        ->middleware('throttle:12,1')
        ->name('certificate-operator.requests.reject');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin', function () {
        return 'Admin zona radi.';
    })->name('admin.index');
});
