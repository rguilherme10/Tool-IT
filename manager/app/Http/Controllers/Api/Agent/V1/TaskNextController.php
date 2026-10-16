<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentInstallation;
use App\Services\Tasks\TaskService;
use App\Support\Tasks\TaskCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TaskNextController extends Controller
{
    public function __invoke(Request $request, TaskService $tasks): JsonResponse|Response
    {
        // `wait` existe no contrato por compatibilidade, mas a resposta é sempre imediata (poll curto, sem long-poll).
        $request->validate(['wait' => ['sometimes', 'integer', 'between:0,30']]);

        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');

        $claimed = $tasks->claim($installation->endpoint);
        if ($claimed === null) {
            return response()->noContent();
        }

        ['task' => $task, 'attempt' => $attempt] = $claimed;

        return response()->json([
            'task_id' => $task->uuid,
            'attempt_id' => $attempt->uuid,
            'type' => $task->type,
            // Objeto JSON mesmo vazio: o Agent desserializa `params` como objeto.
            'params' => empty($task->params) ? new \stdClass : $task->params,
            'timeout_seconds' => TaskCatalog::definition($task->type)->timeoutSeconds,
            'lease_expires_at' => $attempt->lease_expires_at->toImmutable()->utc()->toIso8601String(),
            'risk' => $task->risk->value,
        ]);
    }
}
