<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Exceptions\TransferException;
use App\Http\Controllers\Controller;
use App\Models\Endpoint;
use App\Models\FileTransfer;
use App\Services\Transfers\TransferService;
use App\Support\Authorization\AccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Transferência de arquivos pelo navegador (task 009). Fino de propósito: as regras (política, PathGuard, estados,
 * hash, auditoria) estão em TransferService. Aqui:
 *  - o recurso é resolvido DENTRO do escopo do usuário (fora do escopo ou inexistente = 404, mesma resposta);
 *  - AuthorizationException do serviço vira 403; TransferException vira {message, code} com o status dela;
 *  - os chunks do upload chegam como corpo BRUTO (application/octet-stream), nunca multipart.
 * Upload, status e conclusão são chamados pelo JS do painel (sempre JSON); recuperação e cancelamento aceitam
 * formulário (redireciona) ou JSON.
 */
class TransferController extends Controller
{
    public function __construct(private readonly AccessResolver $access, private readonly TransferService $transfers) {}

    /** POST /endpoints/{uuid}/transfers — inicia um envio (`uploading`). */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $endpoint = EndpointController::resolveVisible($this->access, $request, $uuid);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'dir' => ['required', 'string', 'max:260'],
            'sub_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $t = $this->transfers->createSend($request->user(), $endpoint, $data['name'], (int) $data['size'], $data['dir'], $data['sub_name'] ?? null);
        } catch (TransferException $e) {
            return $this->transferError($e);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'transfer_id' => $t->uuid,
            'status' => $t->status->value,
            'chunk_size' => (int) $t->chunk_size,
            'chunks_total' => (int) $t->chunks_total,
        ], 201);
    }

    /** PUT /transfers/{transfer}/upload/{n} — chunk n (corpo bruto, idempotente, ordem livre). */
    public function upload(Request $request, string $transfer, string $n): JsonResponse
    {
        // Teto antes de qualquer outra coisa: o corpo nunca passa de um chunk do navegador (sem folga).
        $max = max(1, (int) config('toolit.transfers.browser_chunk_bytes', 1048576));
        if ((int) $request->header('Content-Length', '0') > $max) {
            return $this->tooLarge($max);
        }

        $t = $this->findVisible($request, $transfer);
        $this->authorizeUploader($request, $t);

        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
        if ($type !== 'application/octet-stream') {
            return response()->json([
                'message' => 'O chunk deve ser enviado como application/octet-stream.', 'code' => 'request.unsupported_media_type',
            ], 415);
        }

        $bytes = $request->getContent();
        if (strlen($bytes) > $max) {
            return $this->tooLarge($max); // Content-Length ausente ou mentiroso
        }

        try {
            $this->transfers->storeUploadChunk($t, (int) $n, $bytes);
        } catch (TransferException $e) {
            return $this->transferError($e);
        }

        return response()->json(['received_chunks' => $this->transfers->receivedUploadChunks($t)]);
    }

    /** GET /transfers/{transfer}/upload — chunks já recebidos (para retomar). */
    public function uploadStatus(Request $request, string $transfer): JsonResponse
    {
        $t = $this->findVisible($request, $transfer);
        $this->authorizeUploader($request, $t);

        if ($t->direction !== TransferDirection::Send) {
            return $this->transferError(TransferException::conflict('Esta transferência não recebe arquivo do navegador.'));
        }

        return response()->json([
            'transfer_id' => $t->uuid,
            'status' => $t->status->value,
            'chunk_size' => (int) $t->chunk_size,
            'chunks_total' => (int) $t->chunks_total,
            'received_chunks' => $t->status === TransferStatus::Uploading ? $this->transfers->receivedUploadChunks($t) : [],
        ]);
    }

    /** POST /transfers/{transfer}/upload/complete — monta, calcula o SHA-256 e cria a tarefa file.send. */
    public function completeUpload(Request $request, string $transfer): JsonResponse
    {
        $t = $this->findVisible($request, $transfer);
        $this->authorizeUploader($request, $t);

        // Parte faltando é erro do pedido (422) e diz quais faltam, para o navegador reenviar só essas.
        if ($t->direction === TransferDirection::Send && $t->status === TransferStatus::Uploading) {
            $missing = array_values(array_diff(range(0, max(0, (int) $t->chunks_total - 1)), $this->transfers->receivedUploadChunks($t)));
            if ($missing !== []) {
                return response()->json([
                    'message' => 'Faltam '.count($missing).' parte(s) do arquivo.',
                    'code' => 'transfer.incomplete',
                    'missing_chunks' => $missing,
                ], 422);
            }
        }

        try {
            $ready = $this->transfers->completeUpload($request->user(), $t);
        } catch (TransferException $e) {
            return $this->transferError($e);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['transfer_id' => $ready->uuid, 'status' => $ready->status->value, 'sha256' => $ready->sha256]);
    }

    /** POST /endpoints/{uuid}/transfers/fetch — recuperação de um arquivo do computador. */
    public function fetch(Request $request, string $uuid): JsonResponse|RedirectResponse
    {
        $endpoint = EndpointController::resolveVisible($this->access, $request, $uuid);
        $data = $request->validate(['path' => ['required', 'string', 'max:400']]);

        try {
            $t = $this->transfers->createFetch($request->user(), $endpoint, $data['path']);
        } catch (\DomainException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->back()->withInput()->withErrors(['transfer' => $e->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['transfer_id' => $t->uuid, 'status' => $t->status->value], 201)
            : redirect()->route('endpoints.show', ['uuid' => $endpoint->uuid, 'tab' => 'files'])
                ->with('status', 'Recuperação criada. O agente envia o arquivo no próximo contato.');
    }

    /** POST /transfers/{transfer}/cancel */
    public function cancel(Request $request, string $transfer): JsonResponse|RedirectResponse
    {
        $t = $this->findVisible($request, $transfer);

        try {
            $t = $this->transfers->cancel($request->user(), $t);
        } catch (TransferException $e) {
            return $request->expectsJson()
                ? $this->transferError($e)
                : redirect()->back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['transfer_id' => $t->uuid, 'status' => $t->status->value])
            : redirect()->back(fallback: route('endpoints.show', ['uuid' => $t->endpoint->uuid, 'tab' => 'files']))
                ->with('status', 'Transferência cancelada.');
    }

    /** GET /transfers/{transfer}/download — só recuperação concluída; auditado (transfer.downloaded). */
    public function download(Request $request, string $transfer): BinaryFileResponse
    {
        $t = $this->findVisible($request, $transfer);

        try {
            $path = $this->transfers->downloadPath($request->user(), $t);
        } catch (TransferException $e) {
            abort($e->status, $e->getMessage());
        }

        [$ascii, $utf8] = self::downloadNames((string) $t->original_name);

        // Sem ETag/Last-Modified e sem cache: o arquivo é pessoal e o link não deve ser reaproveitado.
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ], false, null, false, false);
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($utf8));

        return $response;
    }

    /**
     * Nome sugerido no download a partir do original_name (só metadado, pode ser hostil): sem controles nem
     * caracteres de formatação (CR/LF, RTL override, zero-width), só o último segmento (nada de pastas), sem
     * caracteres que quebram o cabeçalho ou o nome no Windows, sem pontos/espaços nas pontas.
     *
     * @return array{0: string, 1: string} [fallback ASCII para filename="", nome UTF-8 para filename*]
     */
    public static function downloadNames(string $original): array
    {
        $name = mb_scrub($original, 'UTF-8');
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);
        $segments = preg_split('/[\/\\\\]/', $name) ?: [''];
        $name = (string) end($segments);
        $name = (string) preg_replace('/["*:<>?|;]/', '_', $name);
        $name = trim(mb_substr(trim($name, " .\t"), 0, 200), " .\t");
        if ($name === '') {
            $name = 'arquivo';
        }

        $ascii = (string) preg_replace('/[^\x20-\x7E]/u', '_', $name);
        $ascii = str_replace('%', '_', $ascii);

        return [$ascii, $name];
    }

    /** Transferência só entre computadores que o usuário enxerga; qualquer outra (ou inexistente) = 404. */
    private function findVisible(Request $request, string $uuid): FileTransfer
    {
        $visible = $this->access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::InventoryView)->select('endpoints.id');
        $t = FileTransfer::query()->whereIn('endpoint_id', $visible)->where('uuid', strtolower($uuid))->with('endpoint')->first();
        abort_if($t === null, 404);

        return $t;
    }

    /**
     * Só quem criou o envio manda e consulta as partes, e só enquanto ainda tiver tasks.create + files.transfer_send
     * no computador (o serviço não recebe usuário em storeUploadChunk/receivedUploadChunks).
     */
    private function authorizeUploader(Request $request, FileTransfer $t): void
    {
        $user = $request->user();
        abort_unless(
            (int) $t->created_by === (int) $user->id
                && $this->access->can($user, Permission::TasksCreate, $t->endpoint)
                && $this->access->can($user, Permission::FilesTransferSend, $t->endpoint),
            403,
        );
    }

    private function transferError(TransferException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
    }

    private function tooLarge(int $max): JsonResponse
    {
        return response()->json(['message' => 'Parte grande demais.', 'code' => 'payload.too_large', 'max_bytes' => $max], 413);
    }
}
