<?php

use App\Http\Controllers\Api\Agent\V1\EnrollController;
use App\Http\Controllers\Api\Agent\V1\HeartbeatController;
use App\Http\Controllers\Api\Agent\V1\MetaController;
use App\Http\Controllers\Api\Agent\V1\RotateKeyController;
use Illuminate\Support\Facades\Route;

/*
| API do Agent v1 — contrato em docs/api/agent-api-v1.md.
| /api/agent/v1/*  (o prefixo /api é aplicado pelo framework)
*/
Route::prefix('agent/v1')->middleware('throttle:agent-api-preauth')->group(function () {
    Route::get('meta', MetaController::class);
    Route::post('enroll', EnrollController::class)->middleware('throttle:agent-enroll');

    // Tudo abaixo exige request assinado (ADR-004). O throttle por instalação roda DEPOIS da assinatura.
    Route::middleware(['agent.signed', 'throttle:agent-api'])->group(function () {
        Route::post('heartbeat', HeartbeatController::class);
        Route::post('keys/rotate', RotateKeyController::class);
    });
});
