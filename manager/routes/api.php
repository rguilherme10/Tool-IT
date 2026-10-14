<?php

use App\Http\Controllers\Api\Agent\V1\EnrollController;
use App\Http\Controllers\Api\Agent\V1\HeartbeatController;
use App\Http\Controllers\Api\Agent\V1\InventoryController;
use App\Http\Controllers\Api\Agent\V1\MetaController;
use App\Http\Controllers\Api\Agent\V1\MetricsController;
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
    // O teto de payload roda ANTES da assinatura (corpos gigantes nunca são lidos nem hasheados).
    Route::middleware(['agent.payload', 'agent.signed', 'throttle:agent-api'])->group(function () {
        Route::post('heartbeat', HeartbeatController::class);
        Route::post('keys/rotate', RotateKeyController::class);
        Route::post('metrics', MetricsController::class);
    });

    // Inventário completo: teto maior (4 MiB), contrato em docs/api/agent-api-v1.md.
    Route::middleware(['agent.payload:4194304', 'agent.signed', 'throttle:agent-api'])->group(function () {
        Route::post('inventory', InventoryController::class);
    });
});
