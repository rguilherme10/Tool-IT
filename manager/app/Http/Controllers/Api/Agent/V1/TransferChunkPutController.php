<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Exceptions\TransferException;
use App\Http\Controllers\Api\Agent\V1\Concerns\ResolvesAgentTransfer;
use App\Http\Controllers\Controller;
use App\Services\Transfers\TransferService;
use App\Support\Api\AgentApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUT /transfers/{id}/chunks/{n} (recuperação): corpo BRUTO (application/octet-stream), nunca multipart/JSON.
 * O teto do corpo (agent.payload:2621440) e a assinatura sobre estes bytes já rodaram no middleware.
 * X-Chunk-Sha256 é obrigatório e conferido pelo serviço (422 transfer.chunk_hash; nada é gravado).
 */
class TransferChunkPutController extends Controller
{
    use ResolvesAgentTransfer;

    public function __invoke(Request $request, string $uuid, string $n, TransferService $transfers): JsonResponse
    {
        $transfer = $this->runningTransfer($request, $transfers, $uuid);
        if ($transfer instanceof JsonResponse) {
            return $transfer;
        }

        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
        if ($type !== 'application/octet-stream') {
            return AgentApiError::response('request.unsupported_media_type', 'O chunk deve ser enviado como application/octet-stream.', 415);
        }

        try {
            $transfers->storeAgentChunk($transfer, (int) $n, $request->getContent(), (string) $request->header('X-Chunk-Sha256', ''));
        } catch (TransferException $e) {
            return $this->transferError($e);
        }

        return response()->json(['chunks_received' => (int) $transfer->fresh()?->chunks_received]);
    }
}
