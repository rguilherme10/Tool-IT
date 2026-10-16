<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Exceptions\TaskConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\TaskResultRequest;
use App\Models\AgentInstallation;
use App\Models\RemoteTask;
use App\Services\Tasks\TaskService;
use App\Support\Api\AgentApiError;
use Illuminate\Http\JsonResponse;

class TaskResultController extends Controller
{
    public function __invoke(TaskResultRequest $request, string $uuid, TaskService $tasks): JsonResponse
    {
        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');

        // Só tarefas do próprio computador: de outro (ou inexistente) é 404, sem revelar que existe.
        $task = RemoteTask::query()->where('uuid', $uuid)->where('endpoint_id', $installation->endpoint_id)->first();
        if ($task === null) {
            return AgentApiError::response('request.not_found', 'Recurso não encontrado.', 404);
        }

        $data = $request->validated();

        try {
            $task = $tasks->complete($task, $data['attempt_id'], $data);
        } catch (TaskConflictException $e) {
            return AgentApiError::response('task.conflict', $e->getMessage(), 409);
        }

        return response()->json(['task_id' => $task->uuid, 'state' => $task->state->value]);
    }
}
