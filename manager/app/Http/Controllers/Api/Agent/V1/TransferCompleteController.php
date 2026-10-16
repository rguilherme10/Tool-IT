<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Exceptions\TransferException;
use App\Http\Controllers\Api\Agent\V1\Concerns\ResolvesAgentTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\TransferCompleteRequest;
use App\Services\Transfers\TransferService;
use Illuminate\Http\JsonResponse;

/**
 * POST /transfers/{id}/complete {sha256,size}: o Agent terminou. Responde 200 {status} com o estado final decidido
 * pelo serviço: hash/tamanho divergente NÃO é erro do pedido — a transferência vira `failed` (bytes descartados na
 * recuperação) e a resposta diz `failed`. Estado inválido ou parte faltando: 409 transfer.conflict.
 */
class TransferCompleteController extends Controller
{
    use ResolvesAgentTransfer;

    public function __invoke(TransferCompleteRequest $request, string $uuid, TransferService $transfers): JsonResponse
    {
        $transfer = $this->runningTransfer($request, $transfers, $uuid);
        if ($transfer instanceof JsonResponse) {
            return $transfer;
        }

        try {
            $done = $transfers->complete($transfer, (string) $request->validated('sha256'), (int) $request->validated('size'));
        } catch (TransferException $e) {
            return $this->transferError($e);
        }

        return response()->json(['status' => $done->status->value]);
    }
}
