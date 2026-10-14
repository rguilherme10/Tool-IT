<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\HeartbeatRequest;
use App\Models\AgentInstallation;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    public function __invoke(HeartbeatRequest $request): JsonResponse
    {
        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');
        $data = $request->validated();

        $installation->forceFill([
            'last_seen_at' => now(),
            'agent_version' => $data['agent_version'] ?? $installation->agent_version,
        ])->save();

        $installation->endpoint->forceFill(array_filter([
            'last_seen_at' => now(),
            'agent_version' => $data['agent_version'] ?? null,
            'current_user' => $data['current_user'] ?? null,
            'ip_addresses' => $data['ip_addresses'] ?? null,
        ], fn ($v) => $v !== null))->save();

        // Tarefas, atualização e config_version ainda não existem (tasks 008/012): respostas honestas.
        return response()->json([
            'server_time' => now()->utc()->toIso8601String(),
            'config_version' => 1,
            'tasks_available' => false,
            'cancel_task_ids' => [],
            'update_available' => null,
        ]);
    }
}
