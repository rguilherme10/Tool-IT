<?php

namespace App\Http\Controllers\Api\Agent\V1\Concerns;

use App\Enums\TaskState;
use App\Exceptions\TransferException;
use App\Models\AgentInstallation;
use App\Models\FileTransfer;
use App\Models\RemoteTask;
use App\Services\Transfers\TransferService;
use App\Support\Api\AgentApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resolução comum das rotas de transferência do Agent.
 *
 * Escopo: só transferências do computador da instalação autenticada (de outro computador ou inexistente = 404
 * request.not_found, sem revelar que existe). Além do estado/direção conferidos pelo TransferService, exige que a
 * tarefa file.* DESTA transferência esteja `running` para este computador: tarefa ainda não pega, cancelada,
 * expirada ou concluída não move bytes (409 transfer.conflict).
 */
trait ResolvesAgentTransfer
{
    private function runningTransfer(Request $request, TransferService $transfers, string $uuid): FileTransfer|JsonResponse
    {
        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');

        $transfer = $installation->endpoint === null ? null : $transfers->findForEndpoint($installation->endpoint, $uuid);
        if ($transfer === null) {
            return AgentApiError::response('request.not_found', 'Recurso não encontrado.', 404);
        }

        $task = $transfer->remote_task_id === null ? null : RemoteTask::query()
            ->whereKey($transfer->remote_task_id)
            ->where('endpoint_id', $installation->endpoint_id)
            ->first();

        $running = $task !== null
            && $task->state === TaskState::Running
            && $task->type === $transfer->direction->taskType()
            && ($task->params['transfer_id'] ?? null) === $transfer->uuid;

        if (! $running) {
            return AgentApiError::response('transfer.conflict', 'A tarefa desta transferência não está em execução.', 409);
        }

        return $transfer;
    }

    private function transferError(TransferException $e): JsonResponse
    {
        return AgentApiError::response($e->errorCode, $e->getMessage(), $e->status);
    }
}
