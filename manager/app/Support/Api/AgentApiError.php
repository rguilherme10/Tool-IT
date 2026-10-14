<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Formato único de erro da API do Agent (docs/api/agent-api-v1.md §2). */
class AgentApiError
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => (string) Str::uuid(),
            ],
        ], $status, $headers);
    }
}
