<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Exceptions\TransferException;
use App\Http\Controllers\Api\Agent\V1\Concerns\ResolvesAgentTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\TransferBeginRequest;
use App\Services\Transfers\TransferService;
use Illuminate\Http\JsonResponse;

/**
 * POST /transfers/{id}/begin {size} (recuperação): o Agent abriu o arquivo e informa o tamanho; o Manager confere
 * max_bytes (acima: 422 transfer.too_large e a transferência falha) e fixa chunks_total.
 */
class TransferBeginController extends Controller
{
    use ResolvesAgentTransfer;

    public function __invoke(TransferBeginRequest $request, string $uuid, TransferService $transfers): JsonResponse
    {
        $transfer = $this->runningTransfer($request, $transfers, $uuid);
        if ($transfer instanceof JsonResponse) {
            return $transfer;
        }

        try {
            $transfers->begin($transfer, (int) $request->validated('size'));
        } catch (TransferException $e) {
            return $this->transferError($e);
        }

        $transfer->refresh();

        return response()->json([
            'status' => $transfer->status->value,
            'chunk_size' => (int) $transfer->chunk_size,
            'chunks_total' => (int) $transfer->chunks_total,
        ]);
    }
}
