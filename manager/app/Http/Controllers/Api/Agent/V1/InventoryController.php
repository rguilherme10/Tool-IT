<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\InventoryRequest;
use App\Models\AgentInstallation;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __invoke(InventoryRequest $request, InventoryIngestService $inventory): JsonResponse
    {
        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');

        $result = $inventory->ingest($installation->endpoint, $request->validated());

        // 202 = processado agora; 200 = snapshot já recebido antes (reenvio idempotente).
        return response()->json(
            ['accepted' => true, 'snapshot_id' => $request->validated('snapshot_id')],
            $result['duplicate'] ? 200 : 202,
        );
    }
}
