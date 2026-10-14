<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\MetricsRequest;
use App\Models\AgentInstallation;
use App\Services\Metrics\MetricsIngestService;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function __invoke(MetricsRequest $request, MetricsIngestService $metrics): JsonResponse
    {
        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');

        return response()->json($metrics->ingest($installation->endpoint, $request->validated('samples')), 202);
    }
}
