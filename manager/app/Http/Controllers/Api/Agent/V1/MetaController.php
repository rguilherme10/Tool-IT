<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'protocol' => config('toolit.agent.protocol'),
            'min_agent_version' => config('toolit.agent.min_version'),
            'server_time' => now()->utc()->toIso8601String(),
        ]);
    }
}
