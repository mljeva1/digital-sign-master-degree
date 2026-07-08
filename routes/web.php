<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

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
    Route::view('/dashboard', 'dashboard')
        ->name('dashboard');

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

    Route::post('/contracts/snapshot', [ContractController::class, 'storeSnapshot'])
        ->name('contracts.snapshot.store');

    Route::get('/contracts/vehicle-sales-preview', [ContractController::class, 'vehicleSalesPreview'])
        ->name('contracts.vehicle-sales-preview');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin', function () {
        return 'Admin zona radi.';
    })->name('admin.index');
});
