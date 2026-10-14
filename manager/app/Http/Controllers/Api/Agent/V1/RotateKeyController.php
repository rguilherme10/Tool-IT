<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\RotateKeyRequest;
use App\Models\AgentInstallation;
use App\Services\Agents\AgentKeyService;
use Illuminate\Http\JsonResponse;

class RotateKeyController extends Controller
{
    public function __invoke(RotateKeyRequest $request, AgentKeyService $keys): JsonResponse
    {
        /** @var AgentInstallation $installation */
        $installation = $request->attributes->get('agent_installation');

        $validUntil = $keys->rotate($installation, $request->validated('new_public_key'));

        return response()->json([
            'rotated' => true,
            'previous_key_valid_until' => $validUntil->utc()->toIso8601String(),
        ]);
    }
}
