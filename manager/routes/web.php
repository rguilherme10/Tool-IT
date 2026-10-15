<?php

use App\Http\Controllers\Web\AgentRevocationController;
use App\Http\Controllers\Web\AlertController;
use App\Http\Controllers\Web\AlertRuleController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EndpointController;
use App\Http\Controllers\Web\EndpointManagementController;
use App\Http\Controllers\Web\EnrollmentTokenController;
use App\Http\Controllers\Web\GroupController;
use App\Http\Controllers\Web\SecurityController;
use App\Http\Controllers\Web\UserController;
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

        Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::post('/alerts/{uuid}/acknowledge', [AlertController::class, 'acknowledge'])->whereUuid('uuid')->name('alerts.acknowledge');
        Route::resource('alert-rules', AlertRuleController::class)->except('show')->parameters(['alert-rules' => 'rule']);

        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::post('/audit/verify', [AuditController::class, 'verify'])->name('audit.verify');

        Route::resource('groups', GroupController::class)->except('show')->parameters(['groups' => 'group']);
        Route::post('/endpoints/{uuid}/group', [EndpointManagementController::class, 'moveGroup'])->whereUuid('uuid')->name('endpoints.group');
        Route::post('/endpoints/{uuid}/status', [EndpointManagementController::class, 'setStatus'])->whereUuid('uuid')->name('endpoints.status');

        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::post('/users/{user}/active', [UserController::class, 'setActive'])->name('users.active');
        Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        Route::post('/users/{user}/roles', [UserController::class, 'assignRole'])->name('users.assignments.store');
        Route::delete('/users/{user}/roles/{assignment}', [UserController::class, 'removeAssignment'])->name('users.assignments.destroy');

        Route::get('/enrollment/create', [EnrollmentTokenController::class, 'create'])->name('enrollment.create');
        Route::post('/enrollment', [EnrollmentTokenController::class, 'store'])->name('enrollment.store');
        Route::get('/enrollment/token', [EnrollmentTokenController::class, 'show'])->name('enrollment.show');
    });
});
