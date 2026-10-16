<?php

use App\Http\Controllers\Api\Agent\V1\EnrollController;
use App\Http\Controllers\Api\Agent\V1\HeartbeatController;
use App\Http\Controllers\Api\Agent\V1\InventoryController;
use App\Http\Controllers\Api\Agent\V1\MetaController;
use App\Http\Controllers\Api\Agent\V1\MetricsController;
use App\Http\Controllers\Api\Agent\V1\RotateKeyController;
use App\Http\Controllers\Api\Agent\V1\TaskNextController;
use App\Http\Controllers\Api\Agent\V1\TaskProgressController;
use App\Http\Controllers\Api\Agent\V1\TaskResultController;
use App\Http\Controllers\Api\Agent\V1\TransferBeginController;
use App\Http\Controllers\Api\Agent\V1\TransferChunkGetController;
use App\Http\Controllers\Api\Agent\V1\TransferChunkPutController;
use App\Http\Controllers\Api\Agent\V1\TransferCompleteController;
use App\Http\Controllers\Api\Agent\V1\TransferShowController;
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

        // Tarefas remotas (task 008). A query de `next` entra na assinatura.
        Route::get('tasks/next', TaskNextController::class);
        Route::post('tasks/{uuid}/progress', TaskProgressController::class);
        Route::post('tasks/{uuid}/result', TaskResultController::class);

        // Transferência de arquivos (task 009): só do próprio computador e com a tarefa file.* em execução.
        Route::get('transfers/{uuid}', TransferShowController::class)->whereUuid('uuid');
        Route::get('transfers/{uuid}/chunks/{n}', TransferChunkGetController::class)->whereUuid('uuid')->where('n', '0|[1-9][0-9]{0,8}');
        Route::post('transfers/{uuid}/begin', TransferBeginController::class)->whereUuid('uuid');
        Route::post('transfers/{uuid}/complete', TransferCompleteController::class)->whereUuid('uuid');
    });

    // Chunk da recuperação (corpo binário bruto de até 2 MiB): teto próprio de 2,5 MiB, ainda ANTES da assinatura.
    Route::middleware(['agent.payload:2621440', 'agent.signed', 'throttle:agent-api'])->group(function () {
        Route::put('transfers/{uuid}/chunks/{n}', TransferChunkPutController::class)->whereUuid('uuid')->where('n', '0|[1-9][0-9]{0,8}');
    });

    // Inventário completo: teto maior (4 MiB), contrato em docs/api/agent-api-v1.md.
    Route::middleware(['agent.payload:4194304', 'agent.signed', 'throttle:agent-api'])->group(function () {
        Route::post('inventory', InventoryController::class);
    });
});
