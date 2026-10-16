<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Exceptions\TransferException;
use App\Http\Controllers\Api\Agent\V1\Concerns\ResolvesAgentTransfer;
use App\Http\Controllers\Controller;
use App\Services\Transfers\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** GET /transfers/{id}/chunks/{n} (envio): bytes brutos do chunk + X-Chunk-Sha256 (hex minúsculo). */
class TransferChunkGetController extends Controller
{
    use ResolvesAgentTransfer;

    public function __invoke(Request $request, string $uuid, string $n, TransferService $transfers): JsonResponse|Response
    {
        $transfer = $this->runningTransfer($request, $transfers, $uuid);
        if ($transfer instanceof JsonResponse) {
            return $transfer;
        }

        try {
            $bytes = $transfers->chunkBytes($transfer, (int) $n);
        } catch (TransferException $e) {
            return $this->transferError($e);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Chunk-Sha256' => hash('sha256', $bytes),
            'Cache-Control' => 'no-store',
        ]);
    }
}
