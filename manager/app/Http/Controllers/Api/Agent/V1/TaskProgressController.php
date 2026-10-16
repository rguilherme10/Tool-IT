<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Exceptions\TaskConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\TaskProgressRequest;
use App\Models\AgentInstallation;
use App\Models\RemoteTask;
use App\Models\RemoteTaskAttempt;
use App\Services\Tasks\TaskService;
use App\Support\Api\AgentApiError;
use Illuminate\Http\JsonResponse;

class TaskProgressController extends Controller
{
    public function __invoke(TaskProgressRequest $request, string $uuid, TaskService $tasks): JsonResponse
    {
        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');

        // Só tarefas do próprio computador: de outro (ou inexistente) é 404, sem revelar que existe.
        $task = RemoteTask::query()->where('uuid', $uuid)->where('endpoint_id', $installation->endpoint_id)->first();
        if ($task === null) {
            return AgentApiError::response('request.not_found', 'Recurso não encontrado.', 404);
        }

        $attemptUuid = $request->validated('attempt_id');

        try {
            $tasks->progress($task, $attemptUuid);
        } catch (TaskConflictException $e) {
            return AgentApiError::response('task.conflict', $e->getMessage(), 409);
        }

        $attempt = RemoteTaskAttempt::query()->where('remote_task_id', $task->id)->where('uuid', $attemptUuid)->firstOrFail();

        return response()->json(['lease_expires_at' => $attempt->lease_expires_at->toImmutable()->utc()->toIso8601String()]);
    }
}
