<?php

namespace Tests\Feature\Transfers;

use App\Enums\EndpointStatus;
use App\Enums\RoleKey;
use App\Enums\TaskRisk;
use App\Enums\TaskState;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Events\RemoteTaskFinished;
use App\Exceptions\TransferException;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\FileTransfer;
use App\Models\FileTransferPolicy;
use App\Models\RemoteTask;
use App\Models\User;
use App\Services\Agents\EnrollmentService;
use App\Services\Agents\RevocationService;
use App\Services\Tasks\TaskService;
use App\Services\Transfers\TransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class TransferServiceTest extends TestCase
{
    private const INBOX = 'C:\\ProgramData\\Toolit\\Inbox';

    private const OUTBOX = 'C:\\ProgramData\\Toolit\\Outbox';

    private TransferService $transfers;

    private TaskService $tasks;

    private User $operator;

    private Endpoint $endpoint;

    private FileTransferPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // Chunks pequenos para os testes ficarem rápidos (os padrões reais são conferidos em test_default_chunk_sizes).
        config(['toolit.transfers.browser_chunk_bytes' => 1024, 'toolit.transfers.agent_chunk_bytes' => 2048]);

        $this->transfers = app(TransferService::class);
        $this->tasks = app(TaskService::class);
        $this->operator = $this->userWithRole(RoleKey::Operator);
        $this->endpoint = $this->activeEndpoint();
        $this->policy = FileTransferPolicy::factory()->create();
    }

    // --- auxiliares ------------------------------------------------------------------------------

    private function newSend(int $size, string $name = 'relatorio.pdf', ?User $by = null, ?Endpoint $endpoint = null): FileTransfer
    {
        return $this->transfers->createSend($by ?? $this->operator, $endpoint ?? $this->endpoint, $name, $size, self::INBOX, null);
    }

    private function uploadAll(FileTransfer $t, string $content): void
    {
        foreach (str_split($content, 1024) as $n => $bytes) {
            $this->transfers->storeUploadChunk($t, $n, $bytes);
        }
    }

    /** Transferência de envio pronta (upload concluído, tarefa criada). */
    private function readySend(string $content): FileTransfer
    {
        $t = $this->newSend(strlen($content));
        $this->uploadAll($t, $content);

        return $this->transfers->completeUpload($this->operator, $t->fresh());
    }

    private function expectTransferError(callable $fn, int $status, ?string $code = null): void
    {
        try {
            $fn();
            $this->fail('Deveria lançar TransferException');
        } catch (TransferException $e) {
            $this->assertSame($status, $e->status, $e->getMessage());
            if ($code !== null) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, FileTransfer::query()->count());
        $this->assertSame(0, RemoteTask::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function blobPath(FileTransfer $t, string $relative = 'file.bin'): string
    {
        return 'transfers/'.$t->uuid.'/'.$relative;
    }

    // --- criação do envio ------------------------------------------------------------------------

    public function test_without_policy_transfers_are_off(): void
    {
        $this->policy->delete();

        foreach ([
            fn () => $this->newSend(10),
            fn () => $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log'),
        ] as $fn) {
            try {
                $fn();
                $this->fail('Sem política deveria recusar');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingCreated();
    }

    public function test_empty_direction_lists_turn_that_direction_off(): void
    {
        $this->policy->forceFill(['send_dirs' => [], 'allowed_extensions' => []])->save();
        $this->expectException(ValidationException::class);
        $this->newSend(10, 'a.txt');
    }

    public function test_send_directory_must_be_one_of_the_policy_directories(): void
    {
        foreach ([self::OUTBOX, self::INBOX.'\\Sub', self::INBOX.'\\..\\Outbox', 'C:\\ProgramData\\Toolit', self::INBOX.'X', '\\\\srv\\c$', 'Inbox', self::INBOX.'\\'] as $dir) {
            try {
                $this->transfers->createSend($this->operator, $this->endpoint, 'a.txt', 10, $dir, null);
                $this->fail("Diretório {$dir} deveria ser recusado");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingCreated();

        // Mesma pasta com outra caixa ASCII: aceita, e o destino usa a grafia da política.
        $t = $this->transfers->createSend($this->operator, $this->endpoint, 'a.txt', 10, 'c:\\programdata\\toolit\\INBOX', null);
        $this->assertSame(self::INBOX.'\\a.txt', $t->remote_path);
    }

    public function test_hostile_names_are_refused_without_creating_anything(): void
    {
        $names = [
            '..', '.', 'a.pdf.exe', 'relatorio.PDF.EXE', 'a.exe', '.exe', 'a.EXE', 'a.exe.', 'a.exe ', 'CON.txt', 'con', 'nul.pdf',
            "a\u{202E}fdp.txt", "fatura\u{202E}fdp.exe", "a\u{200B}.txt", "a.e\u{200B}xe", 'a.ｅｘｅ', str_repeat('a', 257).'.txt',
            'a.txt.', 'a.txt ', 'sub\\a.txt', 'a/b.txt', '..\\..\\Windows\\a.txt', 'a:b.txt', 'a.txt:Zone.Identifier', 'PROGRA~1.txt',
            'semextensao', 'a.bat', 'a.html', 'a[1].txt', "a\x00.txt", "a\nb.txt", '', "\xff\xfe.txt", 'a.ps1', 'a.lnk',
        ];
        foreach ($names as $name) {
            try {
                $this->newSend(10, $name);
                $this->fail('Nome deveria ser recusado: '.json_encode($name));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // O subnome é o que vale como nome de destino: hostil é recusado mesmo com nome original bom.
        foreach (['..', 'a.pdf.exe', 'CON.txt', 'x\\a.txt'] as $sub) {
            try {
                $this->transfers->createSend($this->operator, $this->endpoint, 'bom.txt', 10, self::INBOX, $sub);
                $this->fail('Subnome deveria ser recusado: '.$sub);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNothingCreated();
    }

    public function test_sub_name_defines_destination_and_original_name_is_only_metadata(): void
    {
        $t = $this->transfers->createSend($this->operator, $this->endpoint, '..\\..\\evil<script>.txt', 10, self::INBOX, 'Relatório Final.PDF');

        $this->assertSame(self::INBOX.'\\Relatório Final.PDF', $t->remote_path);
        $this->assertSame('..\\..\\evil<script>.txt', $t->original_name);
        $this->assertSame($t->uuid, $t->stored_name);
        $this->assertSame(TransferStatus::Uploading, $t->status);
        $this->assertSame(TransferDirection::Send, $t->direction);
        $this->assertFalse($t->overwrite);
        $this->assertSame(1024, $t->chunk_size);
        $this->assertSame(1, $t->chunks_total);
        $this->assertNull($t->remote_task_id);
        $this->assertTrue($t->expires_at->isFuture());
    }

    public function test_extension_must_be_in_the_policy_list(): void
    {
        $this->policy->forceFill(['allowed_extensions' => ['txt']])->save();

        $this->assertSame(TransferStatus::Uploading, $this->newSend(10, 'a.TXT')->status);
        $this->expectException(ValidationException::class);
        $this->newSend(10, 'a.pdf');
    }

    public function test_blocked_extensions_are_refused_even_if_listed_by_the_policy(): void
    {
        $this->policy->forceFill(['allowed_extensions' => ['exe', 'txt', 'js']])->save();

        foreach (['a.exe', 'a.js', 'a.exe.txt'] as $name) {
            try {
                $this->newSend(10, $name);
                $this->fail($name);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingCreated();
    }

    public function test_size_limits(): void
    {
        $this->policy->forceFill(['max_bytes' => 5000])->save();

        foreach ([0, -1, 5001, PHP_INT_MAX] as $size) {
            try {
                $this->newSend($size);
                $this->fail("Tamanho {$size} deveria ser recusado");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingCreated();

        $t = $this->newSend(5000);
        $this->assertSame(5, $t->chunks_total);
    }

    public function test_overwrite_follows_policy(): void
    {
        $this->policy->forceFill(['allow_overwrite' => true])->save();

        $this->assertTrue($this->newSend(10)->overwrite);
    }

    // --- upload pelo navegador -------------------------------------------------------------------

    public function test_happy_send_flow_with_out_of_order_and_duplicate_chunks(): void
    {
        $content = random_bytes(2500); // 3 chunks: 1024 + 1024 + 452
        $t = $this->newSend(strlen($content));
        $parts = str_split($content, 1024);

        $this->transfers->storeUploadChunk($t, 2, $parts[2]);
        $this->transfers->storeUploadChunk($t, 0, $parts[0]);
        $this->transfers->storeUploadChunk($t, 0, $parts[0]); // duplicado idêntico: idempotente
        $this->assertSame([0, 2], $this->transfers->receivedUploadChunks($t));
        $this->transfers->storeUploadChunk($t, 1, $parts[1]);
        $this->assertSame([0, 1, 2], $this->transfers->receivedUploadChunks($t));
        $this->assertSame(3, $t->fresh()->chunks_received);

        $ready = $this->transfers->completeUpload($this->operator, $t->fresh());

        $this->assertSame(TransferStatus::Ready, $ready->status);
        $this->assertSame(hash('sha256', $content), $ready->sha256);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($this->blobPath($ready))), $ready->sha256);
        $this->assertSame($content, Storage::disk('local')->get($this->blobPath($ready)));
        $this->assertSame([], Storage::disk('local')->files('transfers/'.$t->uuid.'/up'), 'partes temporárias apagadas');

        $task = RemoteTask::query()->findOrFail($ready->remote_task_id);
        $this->assertSame('file.send', $task->type);
        $this->assertSame(['transfer_id' => $t->uuid], $task->params);
        $this->assertSame(TaskState::Available, $task->state);
        $this->assertSame(TaskRisk::Medium, $task->risk);
        $this->assertSame($t->uuid, $task->idempotency_key);

        // Agent: metadados com chunk do Agent, leitura dos chunks e conclusão.
        $meta = $this->transfers->metadataFor($ready);
        $this->assertSame('send', $meta['direction']);
        $this->assertSame(self::INBOX.'\\relatorio.pdf', $meta['remote_path']);
        $this->assertSame(2500, $meta['size']);
        $this->assertSame($ready->sha256, $meta['sha256']);
        $this->assertSame(2048, $meta['chunk_size']);
        $this->assertSame(2, $meta['chunks_total']);
        $this->assertFalse($meta['overwrite']);

        $claimed = $this->tasks->claim($this->endpoint);
        $this->assertSame($task->id, $claimed['task']->id);

        $read = $this->transfers->chunkBytes($ready, 0).$this->transfers->chunkBytes($ready->fresh(), 1);
        $this->assertSame($content, $read);
        $this->assertSame(TransferStatus::Transferring, $ready->fresh()->status);

        $done = $this->transfers->complete($ready->fresh(), $ready->sha256, 2500);
        $this->assertSame(TransferStatus::Completed, $done->status);
        // Reenvio idêntico do complete: idempotente.
        $this->assertSame(TransferStatus::Completed, $this->transfers->complete($done, $done->sha256, 2500)->status);

        $this->tasks->complete($claimed['task'], $claimed['attempt']->uuid, ['status' => 'completed', 'exit_code' => 0]);
        $this->assertSame(TransferStatus::Completed, $done->fresh()->status);

        foreach (['transfer.created', 'transfer.upload_completed', 'transfer.completed', 'task.created'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_default_chunk_sizes(): void
    {
        config(['toolit.transfers.browser_chunk_bytes' => 1048576, 'toolit.transfers.agent_chunk_bytes' => 2097152]);
        $content = random_bytes(2 * 1048576 + 1000);

        $t = $this->newSend(strlen($content));
        $this->assertSame(1048576, $t->chunk_size);
        $this->assertSame(3, $t->chunks_total);
        foreach (str_split($content, 1048576) as $n => $bytes) {
            $this->transfers->storeUploadChunk($t, $n, $bytes);
        }
        $ready = $this->transfers->completeUpload($this->operator, $t->fresh());

        $meta = $this->transfers->metadataFor($ready);
        $this->assertSame(2097152, $meta['chunk_size']);
        $this->assertSame(2, $meta['chunks_total']);
        $this->assertSame(substr($content, 2097152), $this->transfers->chunkBytes($ready, 1));
        $this->assertSame(hash('sha256', $content), $ready->sha256);
    }

    public function test_upload_chunk_edge_cases(): void
    {
        $content = random_bytes(2500);
        $t = $this->newSend(2500);
        $parts = str_split($content, 1024);

        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t, -1, $parts[0]), 422);
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t, 3, $parts[2]), 422);
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t, 0, substr($parts[0], 1)), 422);   // não-último curto
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t, 0, $parts[0].'x'), 422);          // não-último longo
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t, 2, $parts[2].'x'), 422);          // último maior (arquivo maior que o declarado)
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t, 2, ''), 422);

        $this->transfers->storeUploadChunk($t, 0, $parts[0]);
        $other = $parts[0];
        $other[0] = chr(ord($other[0]) ^ 0xFF);
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t, 0, $other), 409, 'transfer.conflict');
        $this->assertSame($parts[0], Storage::disk('local')->get($this->blobPath($t, 'up/0')));

        // Chunk faltando: completeUpload recusa e não cria tarefa.
        $this->transfers->storeUploadChunk($t, 2, $parts[2]);
        $this->expectTransferError(fn () => $this->transfers->completeUpload($this->operator, $t->fresh()), 409);
        $this->assertSame(0, RemoteTask::query()->count());
        $this->assertSame(TransferStatus::Uploading, $t->fresh()->status);

        // Tudo certo agora; depois de pronto não aceita mais chunks nem nova conclusão.
        $this->transfers->storeUploadChunk($t, 1, $parts[1]);
        $ready = $this->transfers->completeUpload($this->operator, $t->fresh());
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($ready, 0, $parts[0]), 409);
        $this->expectTransferError(fn () => $this->transfers->completeUpload($this->operator, $ready), 409);
        $this->assertSame(1, RemoteTask::query()->count());
    }

    public function test_complete_upload_on_inactive_endpoint_keeps_uploading_and_parts(): void
    {
        $t = $this->newSend(10, 'a.txt');
        $this->transfers->storeUploadChunk($t, 0, '0123456789');
        $this->endpoint->installations()->update(['status' => 'revoked']);

        try {
            $this->transfers->completeUpload($this->operator, $t->fresh());
            $this->fail('Endpoint sem agente ativo deveria recusar');
        } catch (\DomainException) {
        }

        $this->assertSame(TransferStatus::Uploading, $t->fresh()->status);
        $this->assertNull($t->fresh()->remote_task_id);
        $this->assertSame(0, RemoteTask::query()->count());
        $this->assertSame([0], $this->transfers->receivedUploadChunks($t));
    }

    public function test_policy_removed_before_upload_completion_refuses_without_task(): void
    {
        $t = $this->newSend(10, 'a.txt');
        $this->transfers->storeUploadChunk($t, 0, '0123456789');
        $this->policy->delete();

        try {
            $this->transfers->completeUpload($this->operator, $t->fresh());
            $this->fail('Política removida deveria recusar a conclusão');
        } catch (ValidationException) {
        }

        $this->assertSame(TransferStatus::Uploading, $t->fresh()->status);
        $this->assertSame(0, RemoteTask::query()->count());
        $this->assertFalse(Storage::disk('local')->exists($this->blobPath($t)));
    }

    // --- papéis e BOLA ---------------------------------------------------------------------------

    public function test_roles_and_scope(): void
    {
        $groupA = EndpointGroup::factory()->create();
        $groupB = EndpointGroup::factory()->create();
        $endpointA = $this->activeEndpoint($groupA);
        $endpointB = $this->activeEndpoint($groupB);

        $operatorA = $this->userWithRole(RoleKey::Operator, $groupA);
        $support = $this->userWithRole(RoleKey::Support);
        $auditor = $this->userWithRole(RoleKey::Auditor);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $send = fn (User $u, Endpoint $e) => $this->transfers->createSend($u, $e, 'a.txt', 10, self::INBOX, null);
        $fetch = fn (User $u, Endpoint $e) => $this->transfers->createFetch($u, $e, self::OUTBOX.'\\a.log');

        $this->assertSame(TransferStatus::Uploading, $send($operatorA, $endpointA)->status);
        $this->assertSame(TransferStatus::Ready, $fetch($operatorA, $endpointA)->status);
        $this->assertSame(TransferStatus::Ready, $fetch($support, $endpointA)->status);

        $denied = [
            [$send, $operatorA, $endpointB], [$fetch, $operatorA, $endpointB],
            [$send, $support, $endpointA],
            [$send, $auditor, $endpointA], [$fetch, $auditor, $endpointA],
            [$send, $viewer, $endpointA], [$fetch, $viewer, $endpointA],
        ];
        foreach ($denied as $i => [$fn, $user, $endpoint]) {
            try {
                $fn($user, $endpoint);
                $this->fail("Caso {$i} deveria ser negado");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(3, FileTransfer::query()->count());
    }

    public function test_only_the_uploader_completes_and_cancel_needs_permission_in_scope(): void
    {
        $t = $this->newSend(10, 'a.txt');
        $this->transfers->storeUploadChunk($t, 0, '0123456789');

        $group = EndpointGroup::factory()->create();
        $otherOperator = $this->userWithRole(RoleKey::Operator);
        $outsider = $this->userWithRole(RoleKey::Operator, $group);
        $support = $this->userWithRole(RoleKey::Support);

        foreach ([$otherOperator, $outsider] as $user) {
            try {
                $this->transfers->completeUpload($user, $t->fresh());
                $this->fail('Só quem criou conclui o upload');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        // Rascunho em uploading: só o criador cancela (outro operador com escopo global também não).
        foreach ([$otherOperator, $outsider, $support] as $user) {
            try {
                $this->transfers->cancel($user, $t->fresh());
                $this->fail('Cancelamento sem permissão no escopo');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(TransferStatus::Uploading, $t->fresh()->status);

        $cancelled = $this->transfers->cancel($this->operator, $t->fresh());
        $this->assertSame(TransferStatus::Cancelled, $cancelled->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.cancelled']);
    }

    public function test_find_for_endpoint_never_returns_other_endpoints_transfer(): void
    {
        $t = $this->newSend(10, 'a.txt');
        $other = $this->activeEndpoint();

        $this->assertSame($t->id, $this->transfers->findForEndpoint($this->endpoint, $t->uuid)?->id);
        $this->assertNull($this->transfers->findForEndpoint($other, $t->uuid));
        $this->assertNull($this->transfers->findForEndpoint($this->endpoint, 'nao-e-uuid'));
    }

    // --- recuperação -----------------------------------------------------------------------------

    public function test_fetch_path_validation(): void
    {
        $invalid = [
            'C:\\Windows\\System32\\config\\SAM', self::OUTBOX, self::OUTBOX.'\\..\\Inbox\\a.txt', self::INBOX.'\\a.txt',
            'a.log', 'Outbox\\a.log', '\\\\srv\\share\\a.log', self::OUTBOX.'\\a.log:stream', self::OUTBOX.'\\CON.log',
            self::OUTBOX.'\\a.log.', self::OUTBOX.'\\', self::OUTBOX.'X\\a.log', self::OUTBOX."\\a\u{202E}gol.txt",
            self::OUTBOX.'/a.log', self::OUTBOX.'\\'.str_repeat('a', 121), '',
        ];
        foreach ($invalid as $path) {
            try {
                $this->transfers->createFetch($this->operator, $this->endpoint, $path);
                $this->fail('Caminho deveria ser recusado: '.json_encode($path));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingCreated();

        // Subpasta de um diretório de fetch é permitida; extensão executável também (ler não é executar).
        $sub = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\Sub\\app.exe');
        $this->assertSame(self::OUTBOX.'\\Sub\\app.exe', $sub->remote_path);
        $this->assertSame('app.exe', $sub->original_name);
        $this->assertFalse($sub->overwrite);
    }

    public function test_happy_fetch_flow(): void
    {
        $content = random_bytes(5000); // chunks do Agent: 2048 + 2048 + 904
        $t = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\diag.log');

        $this->assertSame(TransferStatus::Ready, $t->status);
        $this->assertSame(TransferDirection::Fetch, $t->direction);
        $task = RemoteTask::query()->findOrFail($t->remote_task_id);
        $this->assertSame('file.fetch', $task->type);
        $this->assertSame(['transfer_id' => $t->uuid], $task->params);
        $this->assertSame(TaskState::Available, $task->state);

        $meta = $this->transfers->metadataFor($t);
        $this->assertSame('fetch', $meta['direction']);
        $this->assertSame(self::OUTBOX.'\\diag.log', $meta['remote_path']);
        $this->assertSame(2048, $meta['chunk_size']);
        $this->assertSame([], $meta['chunks_received']);

        $this->transfers->begin($t, 5000);
        $t = $t->fresh();
        $this->assertSame(TransferStatus::Transferring, $t->status);
        $this->assertSame(5000, $t->size);
        $this->assertSame(3, $t->chunks_total);
        $this->transfers->begin($t, 5000); // reinício com o mesmo tamanho: mantém o estado

        $parts = str_split($content, 2048);
        $this->transfers->storeAgentChunk($t, 2, $parts[2], hash('sha256', $parts[2]));
        $this->transfers->storeAgentChunk($t, 0, $parts[0], strtoupper(hash('sha256', $parts[0])));
        $this->transfers->storeAgentChunk($t, 0, $parts[0], hash('sha256', $parts[0])); // duplicado idêntico
        $this->assertSame([0, 2], $this->transfers->metadataFor($t->fresh())['chunks_received']);
        $this->transfers->storeAgentChunk($t, 1, $parts[1], hash('sha256', $parts[1]));

        $done = $this->transfers->complete($t->fresh(), hash('sha256', $content), 5000);
        $this->assertSame(TransferStatus::Completed, $done->status);
        $this->assertSame(hash('sha256', $content), $done->sha256);
        $this->assertSame(hash('sha256', $content), hash_file('sha256', Storage::disk('local')->path($this->blobPath($done))));
        $this->assertSame([], Storage::disk('local')->files('transfers/'.$t->uuid.'/in'));
        $this->assertSame(TransferStatus::Completed, $this->transfers->complete($done, $done->sha256, 5000)->status);

        $path = $this->transfers->downloadPath($this->operator, $done);
        $this->assertSame($content, file_get_contents($path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.downloaded']);

        $auditor = $this->userWithRole(RoleKey::Auditor);
        $this->expectException(AuthorizationException::class);
        $this->transfers->downloadPath($auditor, $done);
    }

    public function test_fetch_begin_above_max_bytes_fails_the_transfer(): void
    {
        $this->policy->forceFill(['max_bytes' => 4096])->save();
        $t = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');

        $this->expectTransferError(fn () => $this->transfers->begin($t, 4097), 422, 'transfer.too_large');
        $this->assertSame(TransferStatus::Failed, $t->fresh()->status);
        $this->expectTransferError(fn () => $this->transfers->begin($t->fresh(), -1), 409);
    }

    public function test_fetch_begin_rejects_negative_size(): void
    {
        $t = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');
        $this->expectTransferError(fn () => $this->transfers->begin($t, -1), 422);
        $this->assertSame(TransferStatus::Ready, $t->fresh()->status);
    }

    public function test_fetch_begin_with_new_size_restarts_received_chunks(): void
    {
        $t = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');
        $this->transfers->begin($t, 3000);
        $first = random_bytes(2048);
        $this->transfers->storeAgentChunk($t->fresh(), 0, $first, hash('sha256', $first));

        $this->transfers->begin($t->fresh(), 100);
        $t = $t->fresh();
        $this->assertSame(TransferStatus::Transferring, $t->status);
        $this->assertSame(100, $t->size);
        $this->assertSame(1, $t->chunks_total);
        $this->assertSame(0, $t->chunks_received);
        $this->assertSame([], $this->transfers->metadataFor($t)['chunks_received']);
    }

    public function test_agent_chunk_errors(): void
    {
        $t = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');
        $bytes = random_bytes(2048);

        // Antes do begin: conflito.
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($t, 0, $bytes, hash('sha256', $bytes)), 409);

        $this->transfers->begin($t, 3000);
        $t = $t->fresh();
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($t, 0, $bytes, hash('sha256', 'outro')), 422, 'transfer.chunk_hash');
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($t, 0, $bytes, 'xyz'), 422, 'transfer.chunk_hash');
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($t, -1, $bytes, hash('sha256', $bytes)), 422);
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($t, 2, $bytes, hash('sha256', $bytes)), 422);
        $short = substr($bytes, 1);
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($t, 0, $short, hash('sha256', $short)), 422);
        $this->assertSame([], Storage::disk('local')->files('transfers/'.$t->uuid.'/in'));

        $this->transfers->storeAgentChunk($t, 0, $bytes, hash('sha256', $bytes));
        $other = random_bytes(2048);
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($t, 0, $other, hash('sha256', $other)), 409);

        // Falta o chunk 1: complete recusa sem encerrar (o Agent pode reenviar).
        $this->expectTransferError(fn () => $this->transfers->complete($t->fresh(), hash('sha256', 'x'), 3000), 409);
        $this->assertSame(TransferStatus::Transferring, $t->fresh()->status);
    }

    public function test_fetch_complete_with_divergent_hash_or_size_fails_and_discards(): void
    {
        $content = random_bytes(3000);
        $parts = str_split($content, 2048);

        foreach ([[hash('sha256', 'outro'), 3000], [hash('sha256', $content), 2999]] as $i => [$sha, $size]) {
            $t = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX."\\a{$i}.log");
            $this->transfers->begin($t, 3000);
            foreach ($parts as $n => $bytes) {
                $this->transfers->storeAgentChunk($t->fresh(), $n, $bytes, hash('sha256', $bytes));
            }

            $result = $this->transfers->complete($t->fresh(), $sha, $size);

            $this->assertSame(TransferStatus::Failed, $result->status, "caso {$i}");
            $this->assertNotNull($result->error);
            $this->assertSame([], Storage::disk('local')->allFiles('transfers/'.$t->uuid), 'descartado');
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.failed']);
    }

    public function test_send_complete_with_divergent_hash_fails(): void
    {
        $ready = $this->readySend('0123456789');

        $result = $this->transfers->complete($ready, hash('sha256', 'outro'), 10);

        $this->assertSame(TransferStatus::Failed, $result->status);
    }

    // --- política revalidada no Agent ------------------------------------------------------------

    public function test_metadata_refuses_when_policy_no_longer_allows(): void
    {
        $send = $this->readySend('0123456789');
        $fetch = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');
        $this->assertIsArray($this->transfers->metadataFor($send));
        $this->assertIsArray($this->transfers->metadataFor($fetch));

        $changes = [
            ['send_dirs' => ['C:\\ProgramData\\Toolit\\Outro']],
            ['allowed_extensions' => ['txt']],
            ['max_bytes' => 5],
        ];
        $original = $this->policy->only(['send_dirs', 'fetch_dirs', 'allowed_extensions', 'max_bytes']);
        foreach ($changes as $change) {
            $this->policy->forceFill($original)->forceFill($change)->save();
            $this->expectTransferError(fn () => $this->transfers->metadataFor($send), 409, 'transfer.conflict');
        }
        $this->policy->forceFill($original)->forceFill(['fetch_dirs' => []])->save();
        $this->expectTransferError(fn () => $this->transfers->metadataFor($fetch), 409);

        $this->policy->delete();
        $this->expectTransferError(fn () => $this->transfers->metadataFor($send), 409);
        $this->expectTransferError(fn () => $this->transfers->metadataFor($fetch), 409);
    }

    // --- ciclo de vida com a tarefa --------------------------------------------------------------

    public function test_cancelling_the_task_cancels_the_transfer(): void
    {
        $t = $this->readySend('0123456789');
        $admin = $this->userWithRole(RoleKey::Admin);

        $this->tasks->cancel($admin, RemoteTask::query()->findOrFail($t->remote_task_id));

        $this->assertSame(TransferStatus::Cancelled, $t->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.cancelled']);
    }

    public function test_revocation_via_cancel_open_for_cancels_open_transfers(): void
    {
        $send = $this->readySend('0123456789');
        $fetch = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');

        $this->tasks->cancelOpenFor($this->endpoint, 'endpoint_revoked');

        $this->assertSame(TransferStatus::Cancelled, $send->fresh()->status);
        $this->assertSame(TransferStatus::Cancelled, $fetch->fresh()->status);
    }

    public function test_task_expiry_and_failure_propagate(): void
    {
        $expiring = $this->readySend('0123456789');
        $this->travel(31)->minutes();
        $this->tasks->expireDue();
        $this->assertSame(TransferStatus::Expired, $expiring->fresh()->status);
        $this->travelBack();

        $failing = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');
        $claimed = $this->tasks->claim($this->endpoint);
        $this->assertSame($failing->remote_task_id, $claimed['task']->id);
        $this->tasks->complete($claimed['task'], $claimed['attempt']->uuid, ['status' => 'rejected', 'error' => 'fora das raízes locais']);
        $this->assertSame(TransferStatus::Failed, $failing->fresh()->status);
    }

    public function test_task_completed_without_agent_confirmation_fails_the_transfer(): void
    {
        $t = $this->readySend('0123456789');
        $claimed = $this->tasks->claim($this->endpoint);

        $this->tasks->complete($claimed['task'], $claimed['attempt']->uuid, ['status' => 'completed', 'exit_code' => 0]);

        $fresh = $t->fresh();
        $this->assertSame(TransferStatus::Failed, $fresh->status);
        $this->assertStringContainsString('sem confirmação', $fresh->error);
    }

    /** Upload em andamento + envio pronto (com tarefa) + recuperação, e uma transferência de outro computador. */
    private function openTransfers(): array
    {
        $uploading = $this->newSend(2048, 'a.txt');
        $this->transfers->storeUploadChunk($uploading, 0, str_repeat('a', 1024));
        $ready = $this->readySend('0123456789');
        $fetch = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');
        $other = $this->newSend(10, 'b.txt', null, $this->activeEndpoint());

        return [$uploading, $ready, $fetch, $other];
    }

    private function assertClosedBy(string $reason, array $transfers, FileTransfer $other): void
    {
        [$uploading, $ready, $fetch] = $transfers;
        foreach ([$uploading, $ready, $fetch] as $t) {
            $fresh = $t->fresh();
            $this->assertSame(TransferStatus::Cancelled, $fresh->status);
            $this->assertSame($reason, $fresh->error);
            // Exatamente um registro de cancelamento por transferência (o evento da tarefa não audita de novo).
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'transfer.cancelled')
                ->where('subject_id', (string) $t->id)->where('subject_type', $t->getMorphClass())->count());
        }
        foreach ([$ready, $fetch] as $t) {
            $this->assertSame(TaskState::Cancelled, RemoteTask::query()->findOrFail($t->remote_task_id)->state);
        }
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($uploading->fresh(), 1, str_repeat('b', 1024)), 409);
        $this->assertSame(TransferStatus::Uploading, $other->fresh()->status, 'outro computador não é afetado');
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_revocation_cancels_all_open_transfers_including_uploads(): void
    {
        $transfers = $this->openTransfers();

        app(RevocationService::class)->revoke($this->endpoint->activeInstallation()->firstOrFail(), $this->userWithRole(RoleKey::Admin), 'perdido');

        $this->assertClosedBy('agent_revoked', $transfers, $transfers[3]);
    }

    public function test_deactivation_cancels_all_open_transfers_including_uploads(): void
    {
        $transfers = $this->openTransfers();
        $admin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin));

        $this->actingAs($admin)->post('/endpoints/'.$this->endpoint->uuid.'/status', ['status' => 'disabled'])->assertRedirect();

        $this->assertClosedBy('endpoint_deactivated', $transfers, $transfers[3]);
    }

    public function test_reenrollment_revocation_cancels_open_transfers(): void
    {
        $transfers = $this->openTransfers();
        $admin = $this->userWithRole(RoleKey::Admin);
        [, $plain] = app(EnrollmentService::class)->issueToken($admin, null, 1, replaces: $this->endpoint);

        (new FakeAgent($this))->enroll($plain)->assertCreated();

        $this->assertClosedBy('agent_revoked', $transfers, $transfers[3]);
    }

    public function test_cancel_open_for_is_idempotent_and_skips_final_transfers(): void
    {
        [$uploading, $ready] = $this->openTransfers();
        $done = $this->readySend('abc');
        $this->transfers->complete($done, $done->sha256, 3);

        $this->assertSame(3, $this->transfers->cancelOpenFor($this->endpoint, 'agent_revoked'));
        $this->assertSame(0, $this->transfers->cancelOpenFor($this->endpoint, 'agent_revoked'));
        $this->assertSame(TransferStatus::Completed, $done->fresh()->status);
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'transfer.cancelled')->count());

        // A tarefa cancelada depois encontra a transferência já encerrada: nada a mais na auditoria.
        $this->tasks->cancelOpenFor($this->endpoint, 'agent_revoked');
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'transfer.cancelled')->count());
    }

    public function test_inactive_endpoint_or_expired_upload_refuses_chunks_and_completion(): void
    {
        $t = $this->newSend(2048, 'a.txt');
        $this->transfers->storeUploadChunk($t, 0, str_repeat('a', 1024));

        FileTransfer::query()->whereKey($t->id)->update(['expires_at' => now()->subSecond()]);
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t->fresh(), 1, str_repeat('b', 1024)), 409);
        FileTransfer::query()->whereKey($t->id)->update(['expires_at' => now()->addHour()]);

        $this->endpoint->installations()->update(['status' => 'revoked']);
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t->fresh(), 1, str_repeat('b', 1024)), 409);
        $this->endpoint->installations()->update(['status' => 'active']);
        $this->transfers->storeUploadChunk($t->fresh(), 1, str_repeat('b', 1024));

        $this->endpoint->forceFill(['status' => EndpointStatus::Disabled])->save();
        $this->expectTransferError(fn () => $this->transfers->storeUploadChunk($t->fresh(), 1, str_repeat('b', 1024)), 409);
        $this->expectTransferError(fn () => $this->transfers->completeUpload($this->operator, $t->fresh()), 409);
        $this->assertSame(0, RemoteTask::query()->count());
    }

    public function test_user_cancel_of_ready_transfer_cancels_its_task(): void
    {
        $t = $this->readySend('0123456789');

        $this->transfers->cancel($this->operator, $t);

        $this->assertSame(TransferStatus::Cancelled, $t->fresh()->status);
        $this->assertSame(TaskState::Cancelled, RemoteTask::query()->findOrFail($t->remote_task_id)->state);
        $this->expectTransferError(fn () => $this->transfers->cancel($this->operator, $t->fresh()), 409);
    }

    public function test_failing_listener_rolls_back_the_task_transition(): void
    {
        $t = $this->readySend('0123456789');
        Event::listen(RemoteTaskFinished::class, function () {
            throw new \RuntimeException('falha simulada');
        });

        try {
            $this->tasks->cancel($this->userWithRole(RoleKey::Admin), RemoteTask::query()->findOrFail($t->remote_task_id));
            $this->fail('A exceção do listener deveria propagar');
        } catch (\RuntimeException) {
        }

        $this->assertSame(TaskState::Available, RemoteTask::query()->findOrFail($t->remote_task_id)->state);
        $this->assertSame(TransferStatus::Ready, $t->fresh()->status);
    }

    public function test_services_resolve_without_dependency_cycle_and_listener_is_registered(): void
    {
        $this->assertInstanceOf(TaskService::class, app(TaskService::class));
        $this->assertInstanceOf(TransferService::class, app(TransferService::class));
        $this->assertTrue(Event::hasListeners(RemoteTaskFinished::class));
    }

    public function test_finished_non_file_task_does_not_touch_transfers(): void
    {
        $t = $this->newSend(10, 'a.txt');
        $task = $this->tasks->create($this->operator, $this->endpoint, 'process.list', []);
        $this->tasks->cancel($this->operator, $task);

        $this->assertSame(TransferStatus::Uploading, $t->fresh()->status);
    }

    // --- máquina de estados ----------------------------------------------------------------------

    public function test_state_machine(): void
    {
        $this->assertTrue(TransferStatus::Uploading->canTransitionTo(TransferStatus::Ready));
        $this->assertFalse(TransferStatus::Uploading->canTransitionTo(TransferStatus::Completed));
        $this->assertTrue(TransferStatus::Transferring->canTransitionTo(TransferStatus::Ready));
        $this->assertTrue(TransferStatus::Verifying->canTransitionTo(TransferStatus::Completed));
        $this->assertFalse(TransferStatus::Verifying->canTransitionTo(TransferStatus::Cancelled));
        foreach ([TransferStatus::Completed, TransferStatus::Failed, TransferStatus::Cancelled, TransferStatus::Expired] as $final) {
            $this->assertTrue($final->isFinal());
            foreach (TransferStatus::cases() as $to) {
                $this->assertFalse($final->canTransitionTo($to));
            }
        }
        $this->assertFalse(TransferStatus::Ready->isFinal());
    }

    public function test_operations_in_the_wrong_direction_or_state_conflict(): void
    {
        $send = $this->readySend('0123456789');
        $fetch = $this->transfers->createFetch($this->operator, $this->endpoint, self::OUTBOX.'\\a.log');

        $this->expectTransferError(fn () => $this->transfers->begin($send, 10), 409);
        $this->expectTransferError(fn () => $this->transfers->chunkBytes($fetch, 0), 409);
        $this->expectTransferError(fn () => $this->transfers->storeAgentChunk($send, 0, 'x', hash('sha256', 'x')), 409);
        $this->expectTransferError(fn () => $this->transfers->chunkBytes($send, 1), 422);
        $this->expectTransferError(fn () => $this->transfers->chunkBytes($send, -1), 422);

        $this->transfers->cancel($this->operator, $fetch);
        $this->expectTransferError(fn () => $this->transfers->begin($fetch->fresh(), 10), 409);
        $this->expectTransferError(fn () => $this->transfers->metadataFor($fetch->fresh()), 409);
        $this->expectTransferError(fn () => $this->transfers->complete($fetch->fresh(), hash('sha256', ''), 0), 409);

        // Estado final não muda por uma transferência "atrasada" do Agent.
        $this->assertSame(TransferStatus::Cancelled, $fetch->fresh()->status);
        $this->assertSame(1, DB::table('file_transfers')->where('status', 'cancelled')->count());
    }
}
