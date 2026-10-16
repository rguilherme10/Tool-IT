<?php

namespace App\Http\Controllers\Api\Agent\V1;

use App\Exceptions\TransferException;
use App\Http\Controllers\Api\Agent\V1\Concerns\ResolvesAgentTransfer;
use App\Http\Controllers\Controller;
use App\Services\Transfers\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /transfers/{id}: metadados para o Agent (contrato em tests/contracts/transfer-metadata.json).
 * O Agent nunca recebe caminho pelos parâmetros da tarefa: tudo vem daqui, revalidado contra a política atual.
 */
class TransferShowController extends Controller
{
    use ResolvesAgentTransfer;

    public function __invoke(Request $request, string $uuid, TransferService $transfers): JsonResponse
    {
        $transfer = $this->runningTransfer($request, $transfers, $uuid);
        if ($transfer instanceof JsonResponse) {
            return $transfer;
        }

        try {
            $meta = $transfers->metadataFor($transfer);
        } catch (TransferException $e) {
            return $this->transferError($e);
        }

        // Mesmas chaves nas duas direções (o Agent desserializa um único tipo).
        return response()->json([
            'transfer_id' => $meta['id'],
            // uuid do computador, o mesmo endpoint_id devolvido no enroll: o Agent confere endpoint e direção.
            'endpoint_id' => $meta['endpoint_id'],
            'direction' => $meta['direction'],
            'size' => $meta['size'],
            'sha256' => $meta['sha256'],
            'chunk_size' => $meta['chunk_size'],
            'chunks_total' => $meta['chunks_total'],
            'remote_path' => $meta['remote_path'],
            'overwrite' => $meta['overwrite'],
            'received_chunks' => array_values($meta['chunks_received'] ?? []),
        ]);
    }
}
