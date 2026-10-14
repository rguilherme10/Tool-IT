<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\EnrollRequest;
use App\Services\Agents\EnrollmentService;
use Illuminate\Http\JsonResponse;

class EnrollController extends Controller
{
    public function __invoke(EnrollRequest $request, EnrollmentService $enrollment): JsonResponse
    {
        [$installation, $endpoint] = $enrollment->enroll($request->validated());

        return response()->json([
            'agent_id' => $installation->uuid,
            'endpoint_id' => $endpoint->uuid,
            'server_time' => now()->utc()->toIso8601String(),
            'config' => config('toolit.agent.config'),
        ], 201);
    }
}
