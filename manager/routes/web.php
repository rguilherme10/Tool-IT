<?php

use App\Http\Controllers\Web\AgentRevocationController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EndpointController;
use App\Http\Controllers\Web\EnrollmentTokenController;
use App\Http\Controllers\Web\SecurityController;
use Illuminate\Support\Facades\Route;

// Login, logout, 2FA e senha: rotas do Fortify (config/fortify.php).

Route::middleware(['auth', 'active'])->group(function () {
    // Fora do grupo mfa.admin: é onde o administrador ativa o MFA obrigatório.
    Route::get('/security', SecurityController::class)->name('security.show');

    Route::middleware('mfa.admin')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/endpoints', [EndpointController::class, 'index'])->name('endpoints.index');
        Route::get('/endpoints/{uuid}', [EndpointController::class, 'show'])->whereUuid('uuid')->name('endpoints.show');
        Route::post('/endpoints/{uuid}/revoke', AgentRevocationController::class)->whereUuid('uuid')->name('endpoints.revoke');

        Route::get('/enrollment/create', [EnrollmentTokenController::class, 'create'])->name('enrollment.create');
        Route::post('/enrollment', [EnrollmentTokenController::class, 'store'])->name('enrollment.store');
        Route::get('/enrollment/token', [EnrollmentTokenController::class, 'show'])->name('enrollment.show');
    });
});
