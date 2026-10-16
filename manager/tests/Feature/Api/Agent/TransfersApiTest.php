<?php

namespace Tests\Feature\Api\Agent;

use App\Enums\RoleKey;
use App\Enums\TaskState;
use App\Enums\TransferStatus;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\FileTransfer;
use App\Models\FileTransferPolicy;
use App\Models\RemoteTask;
use App\Models\User;
use App\Services\Agents\RevocationService;
use App\Services\Tasks\TaskService;
use App\Services\Transfers\TransferService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * API do Agent para transferências (assinada, escopada ao endpoint da instalação, só com a tarefa em execução).
 * Contrato do GET de metadados em tests/contracts/transfer-metadata.json (lido também pelos testes do Agent C#).
 */
class TransfersApiTest extends TestCase
{
    private const INBOX = 'C:\\ProgramData\\Toolit\\Inbox';

    private const OUTBOX = 'C:\\ProgramData\\Toolit\\Outbox';

    private const API = '/api/agent/v1/transfers/';

    private FakeAgent $agent;

    private Endpoint $endpoint;

    private User $operator;

    private FileTransferPolicy $policy;

    private TransferService $transfers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['toolit.transfers.browser_chunk_bytes' => 1024, 'toolit.transfers.agent_chunk_bytes' => 2048]);

        $this->agent = $this->enrolledAgent();
        $this->endpoint = Endpoint::firstOrFail();
        $this->operator = $this->userWithRole(RoleKey::Operator);
        $this->policy = FileTransferPolicy::factory()->create();
        $this->transfers = app(TransferService::class);
    }

    // --- auxiliares ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function contract(string $name): array
    {
        return json_decode(file_get_contents(base_path("../tests/contracts/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private function readySend(string $content, ?Endpoint $endpoint = null): FileTransfer
    {
        $t = $this->transfers->createSend($this->operator, $endpoint ?? $this->endpoint, 'relatorio.pdf', strlen($content), self::INBOX, null);
        $chunk = (int) config('toolit.transfers.browser_chunk_bytes');
        foreach (str_split($content, $chunk) as $n => $bytes) {
            $this->transfers->storeUploadChunk($t, $n, $bytes);
        }

        return $this->transfers->completeUpload($this->operator, $t->fresh());
    }

    private function newFetch(string $name = 'diag.log', ?Endpoint $endpoint = null): FileTransfer
    {
        return $this->transfers->createFetch($this->operator, $endpoint ?? $this->endpoint, self::OUTBOX.'\\'.$name);
    }

    /** O Agent pega a tarefa (fica `running`). */
    private function claim(FileTransfer $t): void
    {
        $next = $this->agent->get('/api/agent/v1/tasks/next')->assertOk()->json();
        $this->assertSame($t->uuid, $next['params']['transfer_id']);
        $this->assertSame(TaskState::Running, RemoteTask::query()->findOrFail($t->remote_task_id)->state);
    }

    private function putChunk(FileTransfer|string $t, int|string $n, string $bytes, ?string $sha = null, array $tweak = []): TestResponse
    {
        $uuid = is_string($t) ? $t : $t->uuid;
        $headers = $sha === null ? [] : ['X-Chunk-Sha256' => $sha];

        return $this->agent->putRaw(self::API.$uuid.'/chunks/'.$n, $bytes, $headers, $tweak);
    }

    /** @return list<string> */
    private function storedAgentChunks(FileTransfer $t): array
    {
        return Storage::disk('local')->files('transfers/'.$t->uuid.'/in');
    }

    // --- metadados -------------------------------------------------------------------------------

    public function test_metadata_matches_the_contract_for_send_and_fetch(): void
    {
        $contract = $this->contract('transfer-metadata');
        $content = random_bytes(5000);
        $send = $this->readySend($content);
        $this->claim($send);

        $body = $this->agent->get(self::API.$send->uuid)->assertOk()->json();

        $this->assertSame(array_keys($contract), array_keys($body), 'mesmas chaves (e ordem) do contrato');
        foreach ($contract as $key => $value) {
            $this->assertSame(gettype($value), gettype($body[$key]), "tipo de {$key}");
        }
        $this->assertSame([
            'transfer_id' => $send->uuid,
            'direction' => 'send',
            'size' => 5000,
            'sha256' => hash('sha256', $content),
            'chunk_size' => 2048,
            'chunks_total' => 3,
            'remote_path' => self::INBOX.'\\relatorio.pdf',
            'overwrite' => false,
            'received_chunks' => [],
        ], $body);

        // Recuperação: mesmas chaves; antes do begin size/sha256/chunks_total são nulos.
        $this->transfers->complete($send->fresh(), hash('sha256', $content), 5000);
        $fetch = $this->newFetch();
        $this->claim($fetch);
        $body = $this->agent->get(self::API.$fetch->uuid)->assertOk()->json();
        $this->assertSame(array_keys($contract), array_keys($body));
        $this->assertSame('fetch', $body['direction']);
        $this->assertNull($body['size']);
        $this->assertNull($body['sha256']);
        $this->assertNull($body['chunks_total']);
        $this->assertSame(self::OUTBOX.'\\diag.log', $body['remote_path']);
        $this->assertSame([], $body['received_chunks']);

        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 5000])->assertOk();
        $part = random_bytes(2048);
        $this->putChunk($fetch, 1, $part, hash('sha256', $part))->assertOk();
        $body = $this->agent->get(self::API.$fetch->uuid)->assertOk()->json();
        foreach ($contract as $key => $value) {
            if ($key !== 'sha256') {
                $this->assertSame(gettype($value), gettype($body[$key]), "tipo de {$key} (recuperação)");
            }
        }
        $this->assertSame(5000, $body['size']);
        $this->assertSame(3, $body['chunks_total']);
        $this->assertSame([1], $body['received_chunks']);
    }

    public function test_task_must_be_running_to_move_bytes(): void
    {
        $send = $this->readySend('0123456789');
        $fetch = $this->newFetch();
        $uploading = $this->transfers->createSend($this->operator, $this->endpoint, 'a.txt', 10, self::INBOX, null);

        // Tarefas ainda `available` (não pegas) e upload sem tarefa: nada passa.
        $calls = fn () => [
            $this->agent->get(self::API.$send->uuid),
            $this->agent->get(self::API.$send->uuid.'/chunks/0'),
            $this->agent->post(self::API.$send->uuid.'/complete', ['sha256' => hash('sha256', '0123456789'), 'size' => 10]),
            $this->agent->get(self::API.$fetch->uuid),
            $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 3]),
            $this->putChunk($fetch, 0, 'abc', hash('sha256', 'abc')),
            $this->agent->post(self::API.$fetch->uuid.'/complete', ['sha256' => hash('sha256', 'abc'), 'size' => 3]),
            $this->agent->get(self::API.$uploading->uuid),
            $this->agent->get(self::API.$uploading->uuid.'/chunks/0'),
        ];
        foreach ($calls() as $i => $response) {
            $response->assertStatus(409)->assertJsonPath('error.code', 'transfer.conflict');
        }
        $this->assertSame(TransferStatus::Ready, $send->fresh()->status);
        $this->assertSame(TransferStatus::Ready, $fetch->fresh()->status);
        $this->assertSame([], $this->storedAgentChunks($fetch));

        // Tarefa cancelada (a transferência junto): continua 409.
        app(TaskService::class)->cancel($this->operator, RemoteTask::query()->findOrFail($send->remote_task_id));
        $this->agent->get(self::API.$send->uuid)->assertStatus(409);
        $this->agent->get(self::API.$send->uuid.'/chunks/0')->assertStatus(409);
    }

    public function test_task_of_another_transfer_running_does_not_unlock_this_one(): void
    {
        $fetch = $this->newFetch('a.log');
        $other = $this->newFetch('b.log');
        $this->claim($fetch); // pega a mais antiga

        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 3])->assertOk();
        $this->agent->post(self::API.$other->uuid.'/begin', ['size' => 3])->assertStatus(409)->assertJsonPath('error.code', 'transfer.conflict');
        $this->assertSame(TransferStatus::Ready, $other->fresh()->status);
    }

    // --- envio: GET de chunks --------------------------------------------------------------------

    public function test_send_chunks_are_2mib_except_the_last_with_correct_hash_header(): void
    {
        config(['toolit.transfers.browser_chunk_bytes' => 1048576, 'toolit.transfers.agent_chunk_bytes' => 2097152]);
        $content = random_bytes(2 * 2097152 + 1000);
        $send = $this->readySend($content);
        $this->claim($send);

        $meta = $this->agent->get(self::API.$send->uuid)->assertOk()->json();
        $this->assertSame(2097152, $meta['chunk_size']);
        $this->assertSame(3, $meta['chunks_total']);

        foreach ([2, 0, 1] as $n) { // ordem livre
            $response = $this->agent->get(self::API.$send->uuid.'/chunks/'.$n)->assertOk();
            $bytes = $response->getContent();
            $this->assertSame($n < 2 ? 2097152 : 1000, strlen($bytes));
            $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            $this->assertSame(hash('sha256', $bytes), $response->headers->get('X-Chunk-Sha256'));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->headers->get('X-Chunk-Sha256'));
            $this->assertSame(substr($content, $n * 2097152, 2097152), $bytes);
        }
        $this->assertSame(TransferStatus::Transferring, $send->fresh()->status);

        $this->agent->get(self::API.$send->uuid.'/chunks/3')->assertUnprocessable()->assertJsonPath('error.code', 'transfer.invalid');
        foreach (['-1', 'abc', '1.5', '0x1'] as $n) {
            $this->agent->get(self::API.$send->uuid.'/chunks/'.$n)->assertNotFound()->assertJsonPath('error.code', 'request.not_found');
        }

        $this->agent->post(self::API.$send->uuid.'/complete', ['sha256' => hash('sha256', $content), 'size' => strlen($content)])
            ->assertOk()->assertExactJson(['status' => 'completed']);
        $this->assertSame(TransferStatus::Completed, $send->fresh()->status);
        // Reenvio idêntico do complete: idempotente.
        $this->agent->post(self::API.$send->uuid.'/complete', ['sha256' => hash('sha256', $content), 'size' => strlen($content)])
            ->assertOk()->assertExactJson(['status' => 'completed']);
    }

    public function test_send_complete_with_divergent_hash_fails_the_transfer(): void
    {
        $send = $this->readySend('0123456789');
        $this->claim($send);
        $this->agent->get(self::API.$send->uuid.'/chunks/0')->assertOk();

        // Decisão do serviço: hash divergente não é erro do pedido — a transferência falha e a resposta diz isso.
        $this->agent->post(self::API.$send->uuid.'/complete', ['sha256' => str_repeat('a', 64), 'size' => 10])
            ->assertOk()->assertExactJson(['status' => 'failed']);
        $this->assertSame(TransferStatus::Failed, $send->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.failed']);

        $this->agent->get(self::API.$send->uuid.'/chunks/0')->assertStatus(409);
        $this->agent->post(self::API.$send->uuid.'/complete', ['sha256' => hash('sha256', '0123456789'), 'size' => 10])->assertStatus(409);
    }

    public function test_complete_validates_body(): void
    {
        $send = $this->readySend('0123456789');
        $this->claim($send);

        foreach ([[], ['sha256' => 'xyz', 'size' => 10], ['sha256' => str_repeat('a', 64)], ['sha256' => str_repeat('a', 64), 'size' => -1],
            ['sha256' => str_repeat('a', 64), 'size' => 'dez'], ['sha256' => str_repeat('g', 64), 'size' => 10]] as $body) {
            $this->agent->post(self::API.$send->uuid.'/complete', $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        }
        $this->assertSame(TransferStatus::Ready, $send->fresh()->status);
    }

    // --- recuperação: begin + PUT de chunks ------------------------------------------------------

    public function test_fetch_flow_with_out_of_order_duplicate_chunks(): void
    {
        $content = random_bytes(5000);
        $parts = str_split($content, 2048);
        $fetch = $this->newFetch();
        $this->claim($fetch);

        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 5000])->assertOk()
            ->assertExactJson(['status' => 'transferring', 'chunk_size' => 2048, 'chunks_total' => 3]);

        $this->putChunk($fetch, 2, $parts[2], hash('sha256', $parts[2]))->assertOk()->assertExactJson(['chunks_received' => 1]);
        $this->putChunk($fetch, 0, $parts[0], strtoupper(hash('sha256', $parts[0])))->assertOk();
        $this->putChunk($fetch, 0, $parts[0], hash('sha256', $parts[0]))->assertOk(); // retentativa idêntica
        $this->assertSame([0, 2], $this->agent->get(self::API.$fetch->uuid)->json('received_chunks'));

        // Faltando parte: conflito, nada concluído.
        $this->agent->post(self::API.$fetch->uuid.'/complete', ['sha256' => hash('sha256', $content), 'size' => 5000])
            ->assertStatus(409)->assertJsonPath('error.code', 'transfer.conflict');

        $this->putChunk($fetch, 3, 'x', hash('sha256', 'x'))->assertUnprocessable()->assertJsonPath('error.code', 'transfer.invalid');
        $this->putChunk($fetch, 1, substr($parts[1], 1), hash('sha256', substr($parts[1], 1)))->assertUnprocessable()->assertJsonPath('error.code', 'transfer.invalid');
        $this->putChunk($fetch, 1, $parts[1], hash('sha256', $parts[1]))->assertOk();

        $this->agent->post(self::API.$fetch->uuid.'/complete', ['sha256' => hash('sha256', $content), 'size' => 5000])
            ->assertOk()->assertExactJson(['status' => 'completed']);
        $this->assertSame($content, Storage::disk('local')->get('transfers/'.$fetch->uuid.'/file.bin'));

        // Recuperação não serve GET de chunk.
        $this->agent->get(self::API.$fetch->uuid.'/chunks/0')->assertStatus(409);
    }

    public function test_wrong_direction_is_conflict(): void
    {
        $send = $this->readySend('0123456789');
        $this->claim($send);

        $this->putChunk($send, 0, '0123456789', hash('sha256', '0123456789'))->assertStatus(409)->assertJsonPath('error.code', 'transfer.conflict');
        $this->agent->post(self::API.$send->uuid.'/begin', ['size' => 10])->assertStatus(409)->assertJsonPath('error.code', 'transfer.conflict');
        $this->assertSame([], $this->storedAgentChunks($send));
    }

    public function test_chunk_hash_missing_or_wrong_is_422_and_nothing_is_stored(): void
    {
        $fetch = $this->newFetch();
        $this->claim($fetch);
        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 3])->assertOk();

        $this->putChunk($fetch, 0, 'abc')->assertUnprocessable()->assertJsonPath('error.code', 'transfer.chunk_hash');
        $this->putChunk($fetch, 0, 'abc', hash('sha256', 'abd'))->assertUnprocessable()->assertJsonPath('error.code', 'transfer.chunk_hash');
        $this->putChunk($fetch, 0, 'abc', 'nao-e-hash')->assertUnprocessable()->assertJsonPath('error.code', 'transfer.chunk_hash');
        $this->putChunk($fetch, 0, 'abc', '')->assertUnprocessable()->assertJsonPath('error.code', 'transfer.chunk_hash');

        $this->assertSame([], $this->storedAgentChunks($fetch));
        $this->assertSame(0, $fetch->fresh()->chunks_received);
    }

    public function test_begin_above_max_bytes_is_422_and_fails_the_transfer(): void
    {
        $this->policy->forceFill(['max_bytes' => 4096])->save();
        $fetch = $this->newFetch();
        $this->claim($fetch);

        foreach ([[], ['size' => -1], ['size' => 'grande'], ['size' => 1.5]] as $body) {
            $this->agent->post(self::API.$fetch->uuid.'/begin', $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        }
        $this->assertSame(TransferStatus::Ready, $fetch->fresh()->status);

        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 4097])->assertUnprocessable()->assertJsonPath('error.code', 'transfer.too_large');
        $this->assertSame(TransferStatus::Failed, $fetch->fresh()->status);
        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 10])->assertStatus(409);
    }

    public function test_fetch_complete_with_divergent_hash_fails_and_discards(): void
    {
        $fetch = $this->newFetch();
        $this->claim($fetch);
        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 3])->assertOk();
        $this->putChunk($fetch, 0, 'abc', hash('sha256', 'abc'))->assertOk();

        $this->agent->post(self::API.$fetch->uuid.'/complete', ['sha256' => hash('sha256', 'abd'), 'size' => 3])
            ->assertOk()->assertExactJson(['status' => 'failed']);
        $this->assertSame(TransferStatus::Failed, $fetch->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('transfers/'.$fetch->uuid));
    }

    // --- autenticação, teto e escopo -------------------------------------------------------------

    public function test_oversize_chunk_is_413_before_signature_verification(): void
    {
        $fetch = $this->newFetch();
        $this->claim($fetch);
        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 3])->assertOk();

        $big = str_repeat('x', 2621441);
        // Assinatura inválida de propósito: o teto responde antes (413, não 401).
        $this->putChunk($fetch, 0, $big, hash('sha256', $big), ['signature' => 'AAAA'])
            ->assertStatus(413)->assertJsonPath('error.code', 'payload.too_large')->assertJsonPath('error.details.max_bytes', 2621440);
        $this->assertSame([], $this->storedAgentChunks($fetch));

        // As demais rotas de transferência ficam no teto normal (1 MiB).
        $this->agent->request('POST', self::API.$fetch->uuid.'/begin', ['size' => 3, 'pad' => str_repeat('x', 1048577)])
            ->assertStatus(413);
    }

    public function test_tampered_signature_query_or_body_is_401_and_nothing_changes(): void
    {
        $fetch = $this->newFetch();
        $this->claim($fetch);
        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 3])->assertOk();
        $hash = hash('sha256', 'abc');

        // Corpo trocado depois de assinar (mesmo hash de chunk no cabeçalho).
        $this->putChunk($fetch, 0, 'abc', $hash, ['signed_body' => 'abd'])->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid');
        // Caminho (índice) trocado depois de assinar.
        $this->putChunk($fetch, 0, 'abc', $hash, ['signed_path' => self::API.$fetch->uuid.'/chunks/1'])->assertUnauthorized();
        // Assinatura lixo e sem cabeçalho de identidade.
        $this->putChunk($fetch, 0, 'abc', $hash, ['signature' => base64_encode(random_bytes(64))])->assertUnauthorized();
        $this->putChunk($fetch, 0, 'abc', $hash, ['drop_headers' => ['HTTP_X_AGENT_ID']])->assertUnauthorized();
        $this->assertSame([], $this->storedAgentChunks($fetch));

        // Query adulterada no GET de metadados e corpo adulterado no begin/complete.
        $this->agent->get(self::API.$fetch->uuid.'?x=1', ['signed_query' => ''])->assertUnauthorized();
        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 10], ['signed_body' => json_encode(['size' => 3])])->assertUnauthorized();
        $this->agent->post(self::API.$fetch->uuid.'/complete', ['sha256' => $hash, 'size' => 3], ['signed_body' => '{}'])->assertUnauthorized();
        $this->assertSame(3, $fetch->fresh()->size);
        $this->assertSame(TransferStatus::Transferring, $fetch->fresh()->status);
    }

    public function test_transfer_of_another_endpoint_or_unknown_is_404(): void
    {
        $other = $this->activeEndpoint();
        $foreignSend = $this->readySend('0123456789', $other);
        $foreignFetch = $this->newFetch('x.log', $other);
        // A tarefa do outro computador em execução não muda nada para este Agent.
        app(TaskService::class)->claim($other);

        foreach ([$foreignSend->uuid, $foreignFetch->uuid, (string) Str::uuid()] as $uuid) {
            $responses = [
                $this->agent->get(self::API.$uuid),
                $this->agent->get(self::API.$uuid.'/chunks/0'),
                $this->putChunk($uuid, 0, 'abc', hash('sha256', 'abc')),
                $this->agent->post(self::API.$uuid.'/begin', ['size' => 3]),
                $this->agent->post(self::API.$uuid.'/complete', ['sha256' => hash('sha256', 'abc'), 'size' => 3]),
            ];
            foreach ($responses as $response) {
                $response->assertNotFound()->assertJsonPath('error.code', 'request.not_found');
            }
        }
        $this->agent->get(self::API.'nao-e-uuid')->assertNotFound()->assertJsonPath('error.code', 'request.not_found');

        $this->assertSame(TransferStatus::Ready, $foreignSend->fresh()->status);
        $this->assertSame(TransferStatus::Ready, $foreignFetch->fresh()->status);
        $this->assertSame([], $this->storedAgentChunks($foreignFetch));
    }

    public function test_revoked_agent_gets_403(): void
    {
        $fetch = $this->newFetch();
        $this->claim($fetch);
        $this->agent->post(self::API.$fetch->uuid.'/begin', ['size' => 3])->assertOk();
        app(RevocationService::class)->revoke(AgentInstallation::firstOrFail(), $this->operator, 'perdido');

        foreach ([
            $this->agent->get(self::API.$fetch->uuid),
            $this->putChunk($fetch, 0, 'abc', hash('sha256', 'abc')),
            $this->agent->post(self::API.$fetch->uuid.'/complete', ['sha256' => hash('sha256', 'abc'), 'size' => 3]),
        ] as $response) {
            $response->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
        }
        $this->assertSame([], $this->storedAgentChunks($fetch));
        $this->assertSame(TransferStatus::Cancelled, $fetch->fresh()->status);
    }
}
