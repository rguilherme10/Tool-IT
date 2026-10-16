<?php

namespace App\Services\Transfers;

use App\Enums\EndpointStatus;
use App\Enums\Permission;
use App\Enums\TaskState;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Exceptions\TransferException;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\FileTransfer;
use App\Models\FileTransferPolicy;
use App\Models\RemoteTask;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tasks\TaskService;
use App\Support\Authorization\AccessResolver;
use App\Support\Transfers\PathGuard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Transferência de arquivos (docs/superpowers/specs/2026-10-16-transferencia-de-arquivos-design.md).
 *
 * Toda mudança de estado passa por aqui e é auditada; transições usam UPDATE condicional (mesmo padrão de
 * TaskService::move). Os bytes ficam no disco privado `local` (storage/app/private, fora do webroot), em
 * transfers/{uuid}/: up/{n} (chunks do navegador), in/{n} (chunks do Agent) e file.bin (arquivo montado).
 * Nenhum nome de arquivo vindo do cliente entra num caminho do disco do Manager: original_name é só metadado.
 *
 * Segurança: a política efetiva é lida na criação e de novo quando o Agent pede os metadados (metadataFor); o
 * caminho de destino é sempre montado com PathGuard::join() e conferido com PathGuard::isInside().
 * Nunca depende de TaskService no sentido contrário: TaskService avisa o fim das tarefas pelo evento
 * RemoteTaskFinished, tratado em onTaskFinished().
 */
class TransferService
{
    /** Prazo para o navegador concluir o upload; depois a transferência expira (transfers:prune). */
    private const UPLOAD_TTL_HOURS = 24;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuditLogger $audit,
        private readonly AccessResolver $access,
        private readonly TransferPolicyResolver $policies,
    ) {}

    // --- operador (web) --------------------------------------------------------------------------

    /**
     * Cria um envio em `uploading`. $dir deve ser EXATAMENTE um dos send_dirs da política efetiva; o nome de
     * destino é $subName (se informado) ou $originalName, que precisa ser um nome simples válido e com extensão
     * permitida e não bloqueada.
     *
     * @throws AuthorizationException sem tasks.create + files.transfer_send no endpoint
     * @throws ValidationException política ausente, diretório/nome/extensão/tamanho inválidos
     * @throws \DomainException computador inativo
     */
    public function createSend(User $by, Endpoint $e, string $originalName, int $size, string $dir, ?string $subName): FileTransfer
    {
        $this->authorize($by, $e, TransferDirection::Send);
        $this->ensureEndpointActive($e);

        $policy = $this->policies->forEndpoint($e);
        $sendDirs = $this->dirList($policy?->send_dirs);
        if ($policy === null || $sendDirs === []) {
            throw ValidationException::withMessages(['policy' => 'Envio de arquivos desligado: este computador não tem política de envio.']);
        }

        $targetDir = $this->exactPolicyDir($dir, $sendDirs)
            ?? throw ValidationException::withMessages(['dir' => 'O diretório de destino não é um dos permitidos pela política.']);

        $name = $this->validSendName($subName ?? $originalName, $policy);

        $max = $this->maxBytes($policy);
        if ($size < 1 || $size > $max) {
            throw ValidationException::withMessages(['size' => "O arquivo deve ter entre 1 byte e {$max} bytes."]);
        }

        try {
            $remotePath = PathGuard::join($targetDir, $name);
        } catch (InvalidArgumentException $ex) {
            throw ValidationException::withMessages(['name' => $ex->getMessage()]);
        }

        $this->ensureDiskSpace($size);
        $chunk = $this->browserChunk();

        return DB::transaction(function () use ($by, $e, $originalName, $remotePath, $size, $chunk, $policy, $targetDir) {
            $t = new FileTransfer;
            $t->forceFill([
                'endpoint_id' => $e->id,
                'direction' => TransferDirection::Send,
                'created_by' => $by->id,
                'original_name' => $this->metadataName($originalName),
                'remote_path' => $remotePath,
                'size' => $size,
                'chunk_size' => $chunk,
                'chunks_total' => intdiv($size + $chunk - 1, $chunk),
                'chunks_received' => 0,
                'overwrite' => (bool) $policy->allow_overwrite,
                'status' => TransferStatus::Uploading,
                'expires_at' => now()->addHours(self::UPLOAD_TTL_HOURS),
            ])->save();

            $this->audit->log('transfer.created', $by, $t, $e->id, $this->meta($t, ['directory' => $targetDir]));

            return $t->refresh();
        });
    }

    /**
     * Guarda o chunk $n do upload (idempotente: o mesmo conteúdo de novo é aceito; outro conteúdo é conflito).
     * Todo chunk tem exatamente chunk_size bytes, exceto o último (o resto).
     * A autorização do usuário fica com quem chama (rota escopada à transferência).
     *
     * @throws TransferException
     */
    public function storeUploadChunk(FileTransfer $t, int $n, string $bytes): void
    {
        $t = $this->reload($t);
        if ($t->direction !== TransferDirection::Send || $t->status !== TransferStatus::Uploading) {
            throw TransferException::conflict('A transferência não está recebendo o arquivo.');
        }
        $this->checkChunk($n, strlen($bytes), (int) $t->size, (int) $t->chunk_size);

        $this->writeChunk($t, 'up', $n, $bytes);

        FileTransfer::query()->whereKey($t->id)->where('status', TransferStatus::Uploading->value)
            ->update(['chunks_received' => count($this->chunkIndexes($t, 'up'))]);
    }

    /** @return list<int> índices dos chunks de upload já recebidos (para retomar) */
    public function receivedUploadChunks(FileTransfer $t): array
    {
        return $this->chunkIndexes($t, 'up');
    }

    /**
     * Monta o arquivo, calcula o SHA-256, passa a `ready` e cria a tarefa file.send (chave de idempotência = uuid).
     * Só quem criou o envio conclui o upload.
     *
     * @throws AuthorizationException
     * @throws TransferException falta chunk, estado inválido, tamanho divergente
     * @throws ValidationException política não permite mais o envio
     * @throws \DomainException computador inativo (vindo de TaskService::create)
     */
    public function completeUpload(User $by, FileTransfer $t): FileTransfer
    {
        $t = $this->reload($t);
        $endpoint = $this->endpointOf($t);
        if ((int) $by->id !== (int) $t->created_by || $endpoint === null) {
            throw new AuthorizationException('Só quem iniciou o envio pode concluí-lo.');
        }
        $this->authorize($by, $endpoint, TransferDirection::Send);

        if ($t->direction !== TransferDirection::Send || $t->status !== TransferStatus::Uploading) {
            throw TransferException::conflict('A transferência não está recebendo o arquivo.');
        }
        $missing = array_diff(range(0, (int) $t->chunks_total - 1), $this->chunkIndexes($t, 'up'));
        if ($missing !== []) {
            throw TransferException::conflict('Faltam '.count($missing).' parte(s) do arquivo.');
        }
        try {
            $this->revalidate($t, $endpoint);
        } catch (TransferException $ex) {
            throw ValidationException::withMessages(['policy' => $ex->getMessage()]);
        }

        // Monta num temporário: só vira file.bin se a transição para ready vencer.
        $temp = $this->assemble($t, 'up', (int) $t->chunks_total);
        $disk = $this->disk();
        if (filesize($disk->path($temp)) !== (int) $t->size) {
            $disk->delete($temp);
            throw TransferException::conflict('O tamanho do arquivo montado não confere com o informado.');
        }
        $sha = (string) hash_file('sha256', $disk->path($temp));

        try {
            $ready = DB::transaction(function () use ($by, $t, $endpoint, $sha, $temp, $disk) {
                $this->move($t, [TransferStatus::Uploading], TransferStatus::Ready, ['sha256' => $sha, 'expires_at' => null]);

                $task = $this->tasks->create($by, $endpoint, 'file.send', ['transfer_id' => $t->uuid], $t->uuid);
                FileTransfer::query()->whereKey($t->id)->update(['remote_task_id' => $task->id]);

                if (! @rename($disk->path($temp), $disk->path($this->blob($t)))) {
                    throw new \RuntimeException('Não foi possível gravar o arquivo montado.');
                }

                $this->audit->log('transfer.upload_completed', $by, $t, $t->endpoint_id, $this->meta($t, [
                    'sha256' => $sha, 'task' => $task->uuid,
                ]));

                return $t->refresh();
            });
        } catch (\Throwable $ex) {
            $disk->delete($temp);
            throw $ex;
        }

        // Partes temporárias só saem depois do commit (uma nova tentativa ainda precisaria delas).
        $disk->deleteDirectory($this->dir($t).'/up');

        return $ready;
    }

    /**
     * Cria uma recuperação `ready` e a tarefa file.fetch. O caminho é de ARQUIVO: a pasta precisa estar dentro de
     * um dos fetch_dirs (subpastas valem) e o nome precisa ser válido. Qualquer extensão é aceita: ler não executa.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws \DomainException computador inativo
     */
    public function createFetch(User $by, Endpoint $e, string $remotePath): FileTransfer
    {
        $this->authorize($by, $e, TransferDirection::Fetch);
        $this->ensureEndpointActive($e);

        $policy = $this->policies->forEndpoint($e);
        $fetchDirs = $this->dirList($policy?->fetch_dirs);
        if ($policy === null || $fetchDirs === []) {
            throw ValidationException::withMessages(['policy' => 'Recuperação de arquivos desligada: este computador não tem política de recuperação.']);
        }

        [$dir, $name] = $this->splitFilePath($remotePath)
            ?? throw ValidationException::withMessages(['path' => 'Informe o caminho completo de um arquivo, como C:\\Pasta\\arquivo.log.']);
        if (! PathGuard::isInside($dir, $fetchDirs)) {
            throw ValidationException::withMessages(['path' => 'O arquivo não está em um diretório permitido pela política.']);
        }
        $path = PathGuard::join($dir, $name);

        return DB::transaction(function () use ($by, $e, $path, $name, $dir) {
            $t = new FileTransfer;
            $t->forceFill([
                'endpoint_id' => $e->id,
                'direction' => TransferDirection::Fetch,
                'created_by' => $by->id,
                'original_name' => PathGuard::normalizeName($name),
                'remote_path' => $path,
                'size' => null,
                'chunk_size' => $this->agentChunk(),
                'chunks_total' => null,
                'chunks_received' => 0,
                'overwrite' => false,
                'status' => TransferStatus::Ready,
            ])->save();

            $task = $this->tasks->create($by, $e, 'file.fetch', ['transfer_id' => $t->uuid], $t->uuid);
            FileTransfer::query()->whereKey($t->id)->update(['remote_task_id' => $task->id]);

            $this->audit->log('transfer.created', $by, $t, $e->id, $this->meta($t, ['directory' => $dir, 'task' => $task->uuid]));

            return $t->refresh();
        });
    }

    /**
     * Cancela a transferência e, se houver, a tarefa (em execução: o Agent recebe o pedido e as rotas passam a
     * responder conflito).
     *
     * @throws AuthorizationException sem a permissão da direção + tasks.cancel no endpoint
     * @throws TransferException já encerrada
     */
    public function cancel(User $by, FileTransfer $t): FileTransfer
    {
        $t = $this->reload($t);
        $endpoint = $this->endpointOf($t);
        if ($endpoint === null || ! $this->access->can($by, $t->direction->permission(), $endpoint)
            || ! $this->access->can($by, Permission::TasksCancel, $endpoint)) {
            throw new AuthorizationException('Você não tem permissão para cancelar esta transferência.');
        }

        return DB::transaction(function () use ($by, $t) {
            $previous = $t->status;
            $this->move($t, [$previous], TransferStatus::Cancelled, ['error' => 'Cancelada pelo operador.']);
            $this->audit->log('transfer.cancelled', $by, $t, $t->endpoint_id, $this->meta($t, ['previous_status' => $previous->value]));

            $task = $t->remote_task_id === null ? null : RemoteTask::query()->find($t->remote_task_id);
            if ($task !== null && ! $task->state->isFinal()) {
                $this->tasks->cancel($by, $task);
            }

            return $t->refresh();
        });
    }

    /**
     * Caminho absoluto do arquivo recuperado para download (só leitura; a resposta HTTP define os cabeçalhos).
     * Auditado como transfer.downloaded.
     *
     * @throws AuthorizationException sem files.transfer_fetch no endpoint
     * @throws TransferException não concluída ou já apagada pela retenção
     */
    public function downloadPath(User $by, FileTransfer $t): string
    {
        $t = $this->reload($t);
        $endpoint = $this->endpointOf($t);
        if ($endpoint === null || ! $this->access->can($by, Permission::FilesTransferFetch, $endpoint)) {
            throw new AuthorizationException('Você não tem permissão para baixar arquivos deste computador.');
        }
        if ($t->direction !== TransferDirection::Fetch || $t->status !== TransferStatus::Completed
            || $t->purged_at !== null || ! $this->disk()->exists($this->blob($t))) {
            throw TransferException::conflict('O arquivo não está disponível para download.');
        }

        $this->audit->log('transfer.downloaded', $by, $t, $t->endpoint_id, $this->meta($t, ['sha256' => $t->sha256]));

        return $this->disk()->path($this->blob($t));
    }

    // --- Agent -----------------------------------------------------------------------------------

    /** Transferência do endpoint (a de outro endpoint nunca é devolvida: a rota responde 404). */
    public function findForEndpoint(Endpoint $e, string $uuid): ?FileTransfer
    {
        if (! Str::isUuid($uuid)) {
            return null;
        }

        return FileTransfer::query()->where('uuid', strtolower($uuid))->where('endpoint_id', $e->id)->first();
    }

    /**
     * Metadados para o Agent. Revalida contra a política ATUAL: se ela não permitir mais (política removida,
     * diretório fora da lista, extensão, tamanho), lança conflito e o Agent recusa a tarefa.
     *
     * @return array<string, mixed>
     *
     * @throws TransferException
     */
    public function metadataFor(FileTransfer $t): array
    {
        $t = $this->reload($t);
        if (! in_array($t->status, [TransferStatus::Ready, TransferStatus::Transferring], true)) {
            throw TransferException::conflict('A transferência não está disponível para o agente.');
        }
        $endpoint = $this->endpointOf($t) ?? throw TransferException::conflict('Computador inexistente.');
        $policy = $this->revalidate($t, $endpoint);
        $agentChunk = $this->agentChunk();

        $base = [
            'id' => $t->uuid,
            'direction' => $t->direction->value,
            'endpoint_id' => $endpoint->uuid,
            'status' => $t->status->value,
            'remote_path' => $t->remote_path,
            'chunk_size' => $agentChunk,
        ];

        if ($t->direction === TransferDirection::Send) {
            return $base + [
                'size' => (int) $t->size,
                'sha256' => $t->sha256,
                'chunks_total' => intdiv((int) $t->size + $agentChunk - 1, $agentChunk),
                'overwrite' => $t->overwrite && (bool) $policy->allow_overwrite,
            ];
        }

        return $base + [
            'size' => $t->size,
            'sha256' => null,
            'chunks_total' => $t->chunks_total,
            'chunks_received' => $this->chunkIndexes($t, 'in'),
            'max_bytes' => $this->maxBytes($policy),
            'overwrite' => false,
        ];
    }

    /**
     * Recuperação: o Agent abriu o arquivo e informa o tamanho. Fixa size/chunks_total e passa a `transferring`.
     * Repetir com o mesmo tamanho mantém o que já chegou; com outro tamanho, recomeça.
     *
     * @throws TransferException conflito de estado/direção; too_large (a transferência falha) ou tamanho inválido
     */
    public function begin(FileTransfer $t, int $size): void
    {
        $t = $this->reload($t);
        if ($t->direction !== TransferDirection::Fetch
            || ! in_array($t->status, [TransferStatus::Ready, TransferStatus::Transferring], true)) {
            throw TransferException::conflict('A transferência não pode ser iniciada neste estado.');
        }
        if ($size < 0) {
            throw TransferException::invalid('Tamanho inválido.');
        }
        $endpoint = $this->endpointOf($t) ?? throw TransferException::conflict('Computador inexistente.');
        $policy = $this->revalidate($t, $endpoint);

        if ($size > $this->maxBytes($policy)) {
            $this->fail($t, 'Arquivo maior que o limite da política ('.$size.' bytes).', $this->agentOf($t));

            throw TransferException::tooLarge();
        }

        if ($t->status === TransferStatus::Transferring && (int) $t->size === $size) {
            return; // reinício do Agent: continua do que já chegou
        }

        $chunk = $this->agentChunk();
        DB::transaction(function () use ($t, $size, $chunk) {
            if ($t->status === TransferStatus::Transferring) {
                $this->move($t, [TransferStatus::Transferring], TransferStatus::Ready);
                $this->disk()->deleteDirectory($this->dir($t).'/in');
            }
            $this->move($t, [TransferStatus::Ready], TransferStatus::Transferring, [
                'size' => $size,
                'chunk_size' => $chunk,
                'chunks_total' => intdiv($size + $chunk - 1, $chunk),
                'chunks_received' => 0,
            ]);
        });
    }

    /**
     * Envio: bytes do chunk $n (de agent_chunk_bytes) do arquivo montado, lidos por faixa (sem carregar o arquivo).
     * A primeira leitura passa a transferência a `transferring`.
     *
     * @throws TransferException
     */
    public function chunkBytes(FileTransfer $t, int $n): string
    {
        $t = $this->reload($t);
        if ($t->direction !== TransferDirection::Send
            || ! in_array($t->status, [TransferStatus::Ready, TransferStatus::Transferring], true)) {
            throw TransferException::conflict('A transferência não está disponível para leitura.');
        }
        $chunk = $this->agentChunk();
        $size = (int) $t->size;
        $total = intdiv($size + $chunk - 1, $chunk);
        if ($n < 0 || $n >= $total) {
            throw TransferException::invalid('Parte fora do intervalo.');
        }

        if ($t->status === TransferStatus::Ready) {
            try {
                $this->move($t, [TransferStatus::Ready], TransferStatus::Transferring);
            } catch (TransferException $ex) {
                // corrida com outra leitura: aceitável só se ela já passou a transferring
                if ($this->reload($t)->status !== TransferStatus::Transferring) {
                    throw $ex;
                }
            }
        }

        $length = min($chunk, $size - $n * $chunk);
        $handle = fopen($this->disk()->path($this->blob($t)), 'rb');
        if ($handle === false) {
            throw TransferException::conflict('O arquivo da transferência não está disponível.');
        }
        try {
            if (fseek($handle, $n * $chunk) !== 0) {
                throw TransferException::conflict('Falha ao ler o arquivo da transferência.');
            }
            $data = '';
            while (strlen($data) < $length && ! feof($handle)) {
                $read = fread($handle, $length - strlen($data));
                if ($read === false) {
                    break;
                }
                $data .= $read;
            }
        } finally {
            fclose($handle);
        }

        if (strlen($data) !== $length) {
            throw TransferException::conflict('O arquivo da transferência está incompleto.');
        }

        return $data;
    }

    /**
     * Recuperação: guarda o chunk $n enviado pelo Agent, conferindo o SHA-256 informado e o tamanho exato.
     *
     * @throws TransferException chunk_hash, invalid ou conflict
     */
    public function storeAgentChunk(FileTransfer $t, int $n, string $bytes, string $sha256): void
    {
        $t = $this->reload($t);
        if ($t->direction !== TransferDirection::Fetch || $t->status !== TransferStatus::Transferring) {
            throw TransferException::conflict('A transferência não está recebendo partes.');
        }
        $sha256 = strtolower($sha256);
        if (preg_match('/^[0-9a-f]{64}\z/', $sha256) !== 1 || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw TransferException::chunkHash();
        }
        $this->checkChunk($n, strlen($bytes), (int) $t->size, (int) $t->chunk_size);

        $this->writeChunk($t, 'in', $n, $bytes);

        FileTransfer::query()->whereKey($t->id)->where('status', TransferStatus::Transferring->value)
            ->update(['chunks_received' => count($this->chunkIndexes($t, 'in'))]);
    }

    /**
     * O Agent terminou. Envio: confere hash/tamanho com o calculado no Manager. Recuperação: monta, confere tamanho
     * e hash; divergência descarta os bytes e falha. Reenvio idêntico de uma transferência concluída é aceito.
     *
     * @throws TransferException formato inválido, estado inválido ou parte faltando (recuperação)
     */
    public function complete(FileTransfer $t, string $sha256, int $size): FileTransfer
    {
        $t = $this->reload($t);
        $sha256 = strtolower($sha256);
        if (preg_match('/^[0-9a-f]{64}\z/', $sha256) !== 1 || $size < 0) {
            throw TransferException::invalid('SHA-256 ou tamanho inválido.');
        }
        if ($t->status === TransferStatus::Completed && $t->sha256 === $sha256 && (int) $t->size === $size) {
            return $t;
        }

        return $t->direction === TransferDirection::Send
            ? $this->completeSend($t, $sha256, $size)
            : $this->completeFetch($t, $sha256, $size);
    }

    // --- ciclo de vida ---------------------------------------------------------------------------

    /**
     * Listener de RemoteTaskFinished (na transação da tarefa). Tarefa file.* encerrada com a transferência ainda
     * aberta: completed sem confirmação do Agent -> failed; failed -> failed; cancelled -> cancelled;
     * expired -> expired. Não apaga nada (os blobs ficam até a retenção).
     */
    public function onTaskFinished(RemoteTask $task): void
    {
        if (! in_array($task->type, ['file.send', 'file.fetch'], true)) {
            return;
        }

        $t = FileTransfer::query()->where('remote_task_id', $task->id)->first();
        if ($t === null || $t->status->isFinal()) {
            return;
        }

        $state = $task->state;
        [$to, $error] = match ($state) {
            TaskState::Completed => [TransferStatus::Failed, 'Tarefa concluída sem confirmação do agente.'],
            TaskState::Cancelled => [TransferStatus::Cancelled, 'Tarefa cancelada.'],
            TaskState::Expired => [TransferStatus::Expired, 'Tarefa expirou sem ser executada.'],
            default => [TransferStatus::Failed, mb_substr('Tarefa falhou: '.((string) ($task->result_summary['error'] ?? 'sem detalhe')), 0, 255)],
        };
        if (! $t->status->canTransitionTo($to)) {
            $to = TransferStatus::Failed; // verifying só sai para completed/failed
        }

        $this->move($t, [$t->status], $to, ['error' => $error]);
        $this->audit->log($to === TransferStatus::Cancelled ? 'transfer.cancelled' : 'transfer.failed', null, $t, $t->endpoint_id,
            $this->meta($t, ['status' => $to->value, 'task' => $task->uuid, 'task_state' => $state->value, 'error' => $error]));
    }

    /**
     * Retenção: expira uploads abandonados e apaga os blobs de transferências encerradas há mais de $days dias
     * (metadados ficam, com purged_at). Transferências abertas nunca são tocadas.
     *
     * @return int transferências cujos blobs foram apagados
     */
    public function prune(?int $days = null): int
    {
        $days ??= (int) config('toolit.transfers.retention_days', 7);

        $abandoned = FileTransfer::query()->where('status', TransferStatus::Uploading->value)
            ->where('expires_at', '<=', now())->get();
        foreach ($abandoned as $t) {
            DB::transaction(function () use ($t) {
                try {
                    $this->move($t, [TransferStatus::Uploading], TransferStatus::Expired, ['error' => 'Upload não concluído no prazo.']);
                } catch (TransferException) {
                    return; // concluído/cancelado entre a consulta e a atualização
                }
                $this->audit->log('transfer.failed', null, $t, $t->endpoint_id, $this->meta($t, ['status' => 'expired', 'error' => 'upload_abandoned']));
            });
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $count = 0;
        FileTransfer::query()->whereIn('status', TransferStatus::finalValues())->whereNull('purged_at')
            ->where('updated_at', '<', $cutoff)
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $t) {
                    $this->disk()->deleteDirectory($this->dir($t));
                    FileTransfer::query()->whereKey($t->id)->update(['purged_at' => now()]);
                    $count++;
                }
            });

        return $count;
    }

    // ---------------------------------------------------------------------------------------------

    private function completeSend(FileTransfer $t, string $sha256, int $size): FileTransfer
    {
        if (! in_array($t->status, [TransferStatus::Ready, TransferStatus::Transferring], true)) {
            throw TransferException::conflict('A transferência não pode ser concluída neste estado.');
        }
        $agent = $this->agentOf($t);

        return DB::transaction(function () use ($t, $sha256, $size, $agent) {
            if ($t->status === TransferStatus::Ready) {
                // Destino já existia com o mesmo hash: o Agent concluiu sem ler nenhuma parte.
                $this->move($t, [TransferStatus::Ready], TransferStatus::Transferring);
            }

            if (! hash_equals((string) $t->sha256, $sha256) || (int) $t->size !== $size) {
                $this->move($t, [TransferStatus::Transferring], TransferStatus::Failed, ['error' => 'O agente informou hash ou tamanho divergente.']);
                $this->audit->log('transfer.failed', $agent, $t, $t->endpoint_id, $this->meta($t, [
                    'reported_sha256' => $sha256, 'reported_size' => $size, 'error' => 'hash_mismatch',
                ]));

                return $t->refresh();
            }

            $this->move($t, [TransferStatus::Transferring], TransferStatus::Completed, ['error' => null]);
            $this->audit->log('transfer.completed', $agent, $t, $t->endpoint_id, $this->meta($t, ['sha256' => $t->sha256]));

            return $t->refresh();
        });
    }

    private function completeFetch(FileTransfer $t, string $sha256, int $size): FileTransfer
    {
        if ($t->status !== TransferStatus::Transferring) {
            throw TransferException::conflict('A transferência não pode ser concluída neste estado.');
        }
        $agent = $this->agentOf($t);
        $disk = $this->disk();

        if ($size !== (int) $t->size) {
            return $this->discardAndFail($t, $agent, 'O agente informou tamanho diferente do declarado no início.', ['reported_size' => $size]);
        }
        $missing = array_diff($t->chunks_total > 0 ? range(0, (int) $t->chunks_total - 1) : [], $this->chunkIndexes($t, 'in'));
        if ($missing !== []) {
            throw TransferException::conflict('Faltam '.count($missing).' parte(s) do arquivo.');
        }

        $this->move($t, [TransferStatus::Transferring], TransferStatus::Verifying);

        try {
            $temp = $this->assemble($t, 'in', (int) $t->chunks_total);
            $actualSize = filesize($disk->path($temp));
            $actualSha = (string) hash_file('sha256', $disk->path($temp));
        } catch (\Throwable $ex) {
            $this->discardAndFail($t, $agent, 'Falha ao montar o arquivo recebido.', []);
            throw $ex;
        }

        if ($actualSize !== $size || ! hash_equals($actualSha, $sha256)) {
            $disk->delete($temp);

            return $this->discardAndFail($t, $agent, 'O SHA-256 do arquivo recebido não confere com o informado pelo agente.', [
                'reported_sha256' => $sha256, 'computed_sha256' => $actualSha,
            ]);
        }

        if (! @rename($disk->path($temp), $disk->path($this->blob($t)))) {
            $disk->delete($temp);

            return $this->discardAndFail($t, $agent, 'Não foi possível gravar o arquivo recebido.', []);
        }

        DB::transaction(function () use ($t, $actualSha, $agent) {
            $this->move($t, [TransferStatus::Verifying], TransferStatus::Completed, ['sha256' => $actualSha, 'error' => null]);
            $this->audit->log('transfer.completed', $agent, $t, $t->endpoint_id, $this->meta($t, ['sha256' => $actualSha]));
        });
        $disk->deleteDirectory($this->dir($t).'/in');

        return $t->refresh();
    }

    /**
     * Falha a transferência (estado aberto atual) e descarta os bytes recebidos.
     *
     * @param  array<string, mixed>  $extra
     */
    private function discardAndFail(FileTransfer $t, ?AgentInstallation $agent, string $error, array $extra): FileTransfer
    {
        $this->fail($t, $error, $agent, $extra);
        $this->disk()->deleteDirectory($this->dir($t));

        return $t->refresh();
    }

    /** @param array<string, mixed> $extra */
    private function fail(FileTransfer $t, string $error, ?AgentInstallation $agent, array $extra = []): void
    {
        DB::transaction(function () use ($t, $error, $agent, $extra) {
            $current = $this->reload($t)->status;
            $this->move($t, [$current], TransferStatus::Failed, ['error' => mb_substr($error, 0, 255)]);
            $this->audit->log('transfer.failed', $agent, $t, $t->endpoint_id, $this->meta($t, ['error' => $error] + $extra));
        });
    }

    /**
     * Revalida a transferência contra a política efetiva ATUAL do endpoint.
     *
     * @throws TransferException
     */
    private function revalidate(FileTransfer $t, Endpoint $endpoint): FileTransferPolicy
    {
        $policy = $this->policies->forEndpoint($endpoint)
            ?? throw TransferException::conflict('Transferências desligadas: o computador não tem mais política de transferência.');

        $split = $this->splitFilePath($t->remote_path);
        if ($split === null) {
            throw TransferException::conflict('Caminho da transferência inválido.');
        }
        [$dir, $name] = $split;

        if ($t->direction === TransferDirection::Send) {
            if ($this->exactPolicyDir($dir, $this->dirList($policy->send_dirs)) === null) {
                throw TransferException::conflict('O diretório de destino não é mais permitido pela política.');
            }
            if (PathGuard::hasBlockedExtension($name) || ! in_array($this->extensionOf($name), $this->extensions($policy), true)) {
                throw TransferException::conflict('A extensão do arquivo não é mais permitida pela política.');
            }
        } elseif (! PathGuard::isInside($dir, $this->dirList($policy->fetch_dirs))) {
            throw TransferException::conflict('O diretório de origem não é mais permitido pela política.');
        }

        if ($t->size !== null && (int) $t->size > $this->maxBytes($policy)) {
            throw TransferException::conflict('O arquivo é maior que o limite atual da política.');
        }

        return $policy;
    }

    /**
     * Transição atômica: só aplica se o estado atual ainda for um dos esperados.
     *
     * @param  list<TransferStatus>  $from
     * @param  array<string, mixed>  $extra
     *
     * @throws TransferException estado atual não permite a transição
     */
    private function move(FileTransfer $t, array $from, TransferStatus $to, array $extra = []): void
    {
        foreach ($from as $status) {
            if (! $status->canTransitionTo($to)) {
                throw TransferException::conflict("Transferência no estado '{$status->value}' não pode passar para '{$to->value}'.");
            }
        }

        $affected = FileTransfer::query()->whereKey($t->id)
            ->whereIn('status', array_map(fn (TransferStatus $s) => $s->value, $from))
            ->update(['status' => $to->value] + $extra);

        if ($affected !== 1) {
            $current = FileTransfer::query()->whereKey($t->id)->toBase()->value('status');
            throw TransferException::conflict("Transferência no estado '{$current}' não pode passar para '{$to->value}'.");
        }

        $t->refresh();
    }

    private function authorize(User $by, Endpoint $e, TransferDirection $direction): void
    {
        foreach ([Permission::TasksCreate, $direction->permission()] as $permission) {
            if (! $this->access->can($by, $permission, $e)) {
                throw new AuthorizationException('Você não tem permissão para transferir arquivos neste computador.');
            }
        }
    }

    private function ensureEndpointActive(Endpoint $e): void
    {
        if ($e->status !== EndpointStatus::Active || $e->trashed() || ! $e->activeInstallation()->exists()) {
            throw new \DomainException('O computador não está ativo ou não tem agente ativo.');
        }
    }

    /**
     * O diretório da política que é IGUAL a $dir (após NFC e dobra ASCII), ou null. Subpastas não valem no envio.
     *
     * @param  list<string>  $dirs
     */
    private function exactPolicyDir(string $dir, array $dirs): ?string
    {
        if (PathGuard::directoryReason($dir) !== null) {
            return null;
        }
        foreach ($dirs as $policyDir) {
            if (PathGuard::isInside($dir, [$policyDir]) && PathGuard::isInside($policyDir, [$dir])) {
                return PathGuard::normalizeName($policyDir);
            }
        }

        return null;
    }

    /**
     * Nome de destino do envio, em NFC, validado pelo PathGuard, sem extensão bloqueada e com extensão permitida.
     *
     * @throws ValidationException
     */
    private function validSendName(string $raw, FileTransferPolicy $policy): string
    {
        try {
            $name = PathGuard::normalizeName($raw);
        } catch (InvalidArgumentException $ex) {
            throw ValidationException::withMessages(['name' => $ex->getMessage()]);
        }
        if (($reason = PathGuard::validateFileName($name)) !== null) {
            throw ValidationException::withMessages(['name' => 'Nome de arquivo inválido: '.$reason]);
        }
        if (PathGuard::hasBlockedExtension($name)) {
            throw ValidationException::withMessages(['name' => 'Arquivos executáveis ou scripts não podem ser transferidos.']);
        }
        if (! in_array($this->extensionOf($name), $this->extensions($policy), true)) {
            throw ValidationException::withMessages(['name' => 'Extensão não permitida pela política de transferência.']);
        }

        return $name;
    }

    /** Última extensão em minúsculas ASCII ('' se não houver). */
    private function extensionOf(string $name): string
    {
        $pos = strrpos($name, '.');
        if ($pos === false || $pos === 0) {
            return '';
        }

        return strtr(substr($name, $pos + 1), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /** @return list<string> */
    private function extensions(FileTransferPolicy $policy): array
    {
        return array_values(array_map(
            fn ($ext) => strtr((string) $ext, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'),
            array_filter((array) ($policy->allowed_extensions ?? []), 'is_string')
        ));
    }

    /** @return list<string> */
    private function dirList(mixed $dirs): array
    {
        return array_values(array_filter(is_array($dirs) ? $dirs : [], 'is_string'));
    }

    /**
     * Separa um caminho absoluto de ARQUIVO em [diretório, nome], ambos válidos pelo PathGuard; null se inválido.
     *
     * @return array{0: string, 1: string}|null
     */
    private function splitFilePath(string $path): ?array
    {
        if (PathGuard::directoryReason($path) !== null) {
            return null;
        }
        $pos = strrpos($path, '\\');
        if ($pos === false || $pos < 2) {
            return null;
        }
        $dir = substr($path, 0, $pos);
        $name = substr($path, $pos + 1);
        if ($pos === 2) {
            $dir .= '\\'; // arquivo na raiz: "C:\a.txt"
        }
        if ($name === '' || PathGuard::fileNameReason($name) !== null || PathGuard::directoryReason($dir) !== null) {
            return null;
        }

        return [PathGuard::normalizeName($dir), PathGuard::normalizeName($name)];
    }

    private function maxBytes(FileTransferPolicy $policy): int
    {
        return min((int) $policy->max_bytes, (int) config('toolit.transfers.max_bytes', 104857600));
    }

    /** Nome original só como metadado: UTF-8 válido, sem controles, até 255 caracteres. */
    private function metadataName(string $name): string
    {
        [$clean] = TaskService::sanitizeOutput($name, 1024);
        $clean = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', (string) $clean);

        return mb_substr($clean, 0, 255);
    }

    /** @throws ValidationException */
    private function ensureDiskSpace(int $size): void
    {
        $root = $this->disk()->path('');
        $free = is_dir($root) ? @disk_free_space($root) : false;
        // O dobro: as partes e o arquivo montado coexistem até a montagem terminar.
        if ($free !== false && $free < 2 * $size) {
            throw ValidationException::withMessages(['size' => 'Sem espaço em disco no servidor para receber este arquivo.']);
        }
    }

    /** @throws TransferException */
    private function checkChunk(int $n, int $length, int $size, int $chunk): void
    {
        $total = intdiv($size + $chunk - 1, $chunk);
        if ($n < 0 || $n >= $total) {
            throw TransferException::invalid('Parte fora do intervalo.');
        }
        $expected = $n < $total - 1 ? $chunk : $size - $chunk * ($total - 1);
        if ($length !== $expected) {
            throw TransferException::invalid("A parte {$n} deve ter {$expected} bytes.");
        }
    }

    /**
     * Grava a parte num temporário e renomeia (nunca deixa parte pela metade). Parte já existente: mesmo conteúdo
     * é idempotente; conteúdo diferente é conflito.
     *
     * @throws TransferException
     */
    private function writeChunk(FileTransfer $t, string $kind, int $n, string $bytes): void
    {
        $disk = $this->disk();
        $relative = $this->dir($t).'/'.$kind.'/'.$n;
        $path = $disk->path($relative);

        if (is_file($path)) {
            if (filesize($path) === strlen($bytes) && hash_equals((string) hash_file('sha256', $path), hash('sha256', $bytes))) {
                return;
            }
            throw TransferException::conflict("A parte {$n} já foi recebida com outro conteúdo.");
        }

        $disk->makeDirectory($this->dir($t).'/'.$kind);
        $temp = $path.'.tmp-'.Str::random(12);
        if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || ! @rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Não foi possível gravar a parte do arquivo.');
        }
    }

    /** @return list<int> */
    private function chunkIndexes(FileTransfer $t, string $kind): array
    {
        $indexes = [];
        foreach ($this->disk()->files($this->dir($t).'/'.$kind) as $file) {
            $base = basename($file);
            if (preg_match('/^(0|[1-9][0-9]{0,8})\z/', $base) === 1) {
                $indexes[] = (int) $base;
            }
        }
        sort($indexes);

        return $indexes;
    }

    /** Concatena as partes 0..$total-1 num temporário (streaming). @return string caminho relativo ao disco */
    private function assemble(FileTransfer $t, string $kind, int $total): string
    {
        $disk = $this->disk();
        $disk->makeDirectory($this->dir($t));
        $relative = $this->dir($t).'/file.bin.tmp-'.Str::random(12);
        $out = fopen($disk->path($relative), 'xb');
        if ($out === false) {
            throw new \RuntimeException('Não foi possível montar o arquivo.');
        }
        try {
            for ($n = 0; $n < $total; $n++) {
                $in = fopen($disk->path($this->dir($t).'/'.$kind.'/'.$n), 'rb');
                if ($in === false) {
                    throw new \RuntimeException("Parte {$n} ilegível.");
                }
                try {
                    if (stream_copy_to_stream($in, $out) === false) {
                        throw new \RuntimeException("Falha ao copiar a parte {$n}.");
                    }
                } finally {
                    fclose($in);
                }
            }
        } catch (\Throwable $ex) {
            fclose($out);
            $disk->delete($relative);
            throw $ex;
        }
        fclose($out);

        return $relative;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    private function dir(FileTransfer $t): string
    {
        return 'transfers/'.$t->uuid;
    }

    private function blob(FileTransfer $t): string
    {
        return $this->dir($t).'/file.bin';
    }

    private function browserChunk(): int
    {
        return max(1, (int) config('toolit.transfers.browser_chunk_bytes', 1048576));
    }

    private function agentChunk(): int
    {
        return max(1, (int) config('toolit.transfers.agent_chunk_bytes', 2097152));
    }

    private function reload(FileTransfer $t): FileTransfer
    {
        return FileTransfer::query()->findOrFail($t->id);
    }

    private function endpointOf(FileTransfer $t): ?Endpoint
    {
        return Endpoint::query()->find($t->endpoint_id);
    }

    private function agentOf(FileTransfer $t): ?AgentInstallation
    {
        return AgentInstallation::query()->where('endpoint_id', $t->endpoint_id)->where('status', 'active')->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function meta(FileTransfer $t, array $extra = []): array
    {
        return [
            'transfer' => $t->uuid,
            'direction' => $t->direction->value,
            'remote_path' => $t->remote_path,
            'size' => $t->size,
        ] + $extra;
    }
}
