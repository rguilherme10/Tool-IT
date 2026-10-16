<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Enums\TransferStatus;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\FileTransfer;
use App\Models\FileTransferPolicy;
use App\Models\RemoteTask;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Transfers\TransferService;
use App\Services\Users\UserService;
use App\Support\Authorization\AccessResolver;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Rotas do navegador da transferência de arquivos: upload em chunks (corpo bruto), conclusão, recuperação,
 * cancelamento e download. Fora do escopo = 404 (sem revelar que existe); sem permissão = 403.
 */
class TransfersWebTest extends TestCase
{
    private const INBOX = 'C:\\ProgramData\\Toolit\\Inbox';

    private const OUTBOX = 'C:\\ProgramData\\Toolit\\Outbox';

    private EndpointGroup $groupA;

    private EndpointGroup $groupB;

    private Endpoint $endpointA;

    private User $operatorA;

    private TransferService $transfers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // Chunks pequenos: testes rápidos (os tamanhos reais são conferidos em TransferServiceTest).
        config(['toolit.transfers.browser_chunk_bytes' => 1024, 'toolit.transfers.agent_chunk_bytes' => 2048]);
        // Erros como em produção: sem página de depuração (que ecoaria a URL e é lenta de renderizar).
        config(['app.debug' => false]);

        $this->transfers = app(TransferService::class);
        $this->groupA = EndpointGroup::factory()->create(['name' => 'Financeiro']);
        $this->groupB = EndpointGroup::factory()->create(['name' => 'Diretoria']);
        $this->endpointA = $this->activeEndpoint($this->groupA, ['hostname' => 'PC-A-001']);
        $this->activeEndpoint($this->groupB, ['hostname' => 'PC-B-001']);
        FileTransferPolicy::factory()->create();
        $this->operatorA = $this->userWithRole(RoleKey::Operator, $this->groupA);
    }

    // --- auxiliares ------------------------------------------------------------------------------

    private function startSend(User $user, int $size, string $name = 'relatorio.pdf', ?Endpoint $endpoint = null): TestResponse
    {
        return $this->actingAs($user)->postJson(route('transfers.store', ['uuid' => ($endpoint ?? $this->endpointA)->uuid]), [
            'name' => $name, 'size' => $size, 'dir' => self::INBOX,
        ]);
    }

    private function newSend(int $size, ?User $by = null): FileTransfer
    {
        $uuid = $this->startSend($by ?? $this->operatorA, $size)->assertCreated()->json('transfer_id');

        return FileTransfer::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function putChunk(User $user, string $uuid, int|string $n, string $bytes, string $contentType = 'application/octet-stream', array $server = []): TestResponse
    {
        return $this->actingAs($user)->call('PUT', '/transfers/'.$uuid.'/upload/'.$n, [], [], [], $server + [
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => (string) strlen($bytes),
            'HTTP_ACCEPT' => 'application/json',
        ], $bytes);
    }

    private function uploadStatus(User $user, string $uuid): TestResponse
    {
        return $this->actingAs($user)->getJson(route('transfers.upload.status', ['transfer' => $uuid]));
    }

    private function completeUpload(User $user, string $uuid): TestResponse
    {
        return $this->actingAs($user)->postJson(route('transfers.upload.complete', ['transfer' => $uuid]));
    }

    /** Recuperação concluída (fluxo do Agent pelo serviço; a API do Agent é testada em TransfersApiTest). */
    private function completedFetch(string $content, string $name = 'diag.log'): FileTransfer
    {
        $t = $this->transfers->createFetch($this->operatorA, $this->endpointA, self::OUTBOX.'\\'.$name);
        $this->transfers->begin($t, strlen($content));
        foreach (str_split($content, 2048) as $n => $bytes) {
            $this->transfers->storeAgentChunk($t->fresh(), $n, $bytes, hash('sha256', $bytes));
        }

        return $this->transfers->complete($t->fresh(), hash('sha256', $content), strlen($content));
    }

    private function download(User $user, string $uuid): TestResponse
    {
        return $this->actingAs($user)->get(route('transfers.download', ['transfer' => $uuid]));
    }

    private function swapRole(User $user, RoleKey $role, ?EndpointGroup $group): void
    {
        RoleAssignment::query()->where('user_id', $user->id)->delete();
        app(UserService::class)->assignRole($user, $role, $group);
        app(AccessResolver::class)->flush();
    }

    // --- envio ponta a ponta ---------------------------------------------------------------------

    public function test_send_end_to_end_with_out_of_order_duplicate_chunks_and_resume(): void
    {
        $content = random_bytes(2500); // 1024 + 1024 + 452
        $parts = str_split($content, 1024);

        $created = $this->startSend($this->operatorA, 2500)->assertCreated()
            ->assertJsonPath('chunk_size', 1024)->assertJsonPath('chunks_total', 3)->assertJsonPath('status', 'uploading');
        $uuid = $created->json('transfer_id');
        $this->assertTrue(Str::isUuid($uuid));

        $this->putChunk($this->operatorA, $uuid, 2, $parts[2])->assertOk()->assertJsonPath('received_chunks', [2]);
        $this->putChunk($this->operatorA, $uuid, 0, $parts[0])->assertOk();
        $this->putChunk($this->operatorA, $uuid, 0, $parts[0])->assertOk(); // retentativa idêntica: idempotente

        // Retomada: o navegador pergunta o que já chegou.
        $this->uploadStatus($this->operatorA, $uuid)->assertOk()
            ->assertJsonPath('received_chunks', [0, 2])->assertJsonPath('chunks_total', 3)
            ->assertJsonPath('chunk_size', 1024)->assertJsonPath('status', 'uploading');

        $other = $parts[0];
        $other[0] = chr(ord($other[0]) ^ 0xFF);
        $this->putChunk($this->operatorA, $uuid, 0, $other)->assertStatus(409)->assertJsonPath('code', 'transfer.conflict');

        $this->putChunk($this->operatorA, $uuid, 1, $parts[1])->assertOk();
        $this->uploadStatus($this->operatorA, $uuid)->assertJsonPath('received_chunks', [0, 1, 2]);

        $this->completeUpload($this->operatorA, $uuid)->assertOk()
            ->assertJsonPath('status', 'ready')->assertJsonPath('sha256', hash('sha256', $content));

        $t = FileTransfer::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(TransferStatus::Ready, $t->status);
        $this->assertSame('file.send', RemoteTask::query()->findOrFail($t->remote_task_id)->type);
        $this->assertSame($content, Storage::disk('local')->get('transfers/'.$uuid.'/file.bin'));

        // Fora de `uploading` não aceita mais chunk nem nova conclusão.
        $this->putChunk($this->operatorA, $uuid, 0, $parts[0])->assertStatus(409)->assertJsonPath('code', 'transfer.conflict');
        $this->completeUpload($this->operatorA, $uuid)->assertStatus(409);
        $this->assertSame(1, RemoteTask::query()->count());
    }

    public function test_complete_with_missing_chunk_is_422_and_creates_no_task(): void
    {
        $t = $this->newSend(2500);
        $this->putChunk($this->operatorA, $t->uuid, 0, str_repeat('a', 1024))->assertOk();
        $this->putChunk($this->operatorA, $t->uuid, 2, str_repeat('c', 452))->assertOk();

        $this->completeUpload($this->operatorA, $t->uuid)->assertUnprocessable()
            ->assertJsonPath('code', 'transfer.incomplete')->assertJsonPath('missing_chunks', [1]);

        $this->assertSame(TransferStatus::Uploading, $t->fresh()->status);
        $this->assertSame(0, RemoteTask::query()->count());
    }

    public function test_chunk_validation(): void
    {
        $t = $this->newSend(2500);

        $this->putChunk($this->operatorA, $t->uuid, 3, str_repeat('x', 452))->assertUnprocessable()->assertJsonPath('code', 'transfer.invalid');
        $this->putChunk($this->operatorA, $t->uuid, 0, str_repeat('x', 1000))->assertUnprocessable()->assertJsonPath('code', 'transfer.invalid');
        $this->putChunk($this->operatorA, $t->uuid, 2, '')->assertUnprocessable();
        // n só com dígitos (negativo, sinal, hexadecimal, texto: a rota nem casa).
        foreach (['-1', '+1', '0x1', 'abc', '1.0'] as $n) {
            $this->putChunk($this->operatorA, $t->uuid, $n, str_repeat('x', 1024))->assertNotFound();
        }
        // Corpo acima do chunk do navegador (+0 de folga): 413 sem gravar nada.
        $this->putChunk($this->operatorA, $t->uuid, 0, str_repeat('x', 1025))->assertStatus(413)->assertJsonPath('code', 'payload.too_large');
        // Corpo declarado pequeno mas maior de fato (Content-Length mentiroso).
        $this->putChunk($this->operatorA, $t->uuid, 0, str_repeat('x', 1025), 'application/octet-stream', ['CONTENT_LENGTH' => '10'])->assertStatus(413);
        // Só corpo bruto: multipart, formulário e JSON são recusados.
        foreach (['multipart/form-data; boundary=x', 'application/x-www-form-urlencoded', 'application/json', 'text/plain'] as $type) {
            $this->putChunk($this->operatorA, $t->uuid, 0, str_repeat('x', 1024), $type)->assertStatus(415);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $t->fresh()->chunks_received);
    }

    // --- quem pode enviar chunks -----------------------------------------------------------------

    public function test_only_the_creator_with_send_permission_uploads_chunks(): void
    {
        $t = $this->newSend(10);
        $otherOperator = $this->userWithRole(RoleKey::Operator, $this->groupA);
        $support = $this->userWithRole(RoleKey::Support, $this->groupA);
        $admin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin));

        foreach ([$otherOperator, $support, $admin] as $user) {
            $this->putChunk($user, $t->uuid, 0, '0123456789')->assertForbidden();
            $this->uploadStatus($user, $t->uuid)->assertForbidden();
            $this->completeUpload($user, $t->uuid)->assertForbidden();
        }
        $this->assertSame([], Storage::disk('local')->allFiles());

        // O criador que perdeu files.transfer_send (agora só Suporte) também não continua o upload.
        $this->swapRole($this->operatorA, RoleKey::Support, $this->groupA);
        $this->putChunk($this->operatorA, $t->uuid, 0, '0123456789')->assertForbidden();
        $this->uploadStatus($this->operatorA, $t->uuid)->assertForbidden();
        $this->completeUpload($this->operatorA, $t->uuid)->assertForbidden();

        $this->swapRole($this->operatorA, RoleKey::Operator, $this->groupA);
        $this->putChunk($this->operatorA, $t->uuid, 0, '0123456789')->assertOk();
        $this->completeUpload($this->operatorA, $t->uuid)->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_fetch_transfer_does_not_accept_browser_chunks(): void
    {
        $t = $this->transfers->createFetch($this->operatorA, $this->endpointA, self::OUTBOX.'\\a.log');

        $this->putChunk($this->operatorA, $t->uuid, 0, 'abc')->assertStatus(409)->assertJsonPath('code', 'transfer.conflict');
        $this->uploadStatus($this->operatorA, $t->uuid)->assertStatus(409);
        $this->completeUpload($this->operatorA, $t->uuid)->assertStatus(409);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // --- papéis e escopo -------------------------------------------------------------------------

    public function test_roles_on_creation(): void
    {
        $support = $this->userWithRole(RoleKey::Support, $this->groupA);
        $auditor = $this->userWithRole(RoleKey::Auditor);
        $viewer = $this->userWithRole(RoleKey::Viewer);
        $fetchUrl = route('transfers.fetch', ['uuid' => $this->endpointA->uuid]);
        $path = ['path' => self::OUTBOX.'\\diag.log'];

        // Suporte: recupera, não envia.
        $this->startSend($support, 10)->assertForbidden();
        $this->actingAs($support)->postJson($fetchUrl, $path)->assertCreated()->assertJsonPath('status', 'ready');

        foreach ([$auditor, $viewer] as $user) {
            $this->startSend($user, 10)->assertForbidden();
            $this->actingAs($user)->postJson($fetchUrl, $path)->assertForbidden();
        }

        $this->startSend($this->operatorA, 10)->assertCreated();
        $this->actingAs($this->operatorA)->postJson($fetchUrl, $path)->assertCreated();
        $this->assertSame(3, FileTransfer::query()->count());

        // Formulário sem JSON: redireciona com mensagem.
        $this->actingAs($this->operatorA)->post($fetchUrl, $path)
            ->assertRedirect(route('endpoints.show', ['uuid' => $this->endpointA->uuid, 'tab' => 'files']))->assertSessionHas('status');
    }

    public function test_creation_validation(): void
    {
        $fetchUrl = route('transfers.fetch', ['uuid' => $this->endpointA->uuid]);

        $this->actingAs($this->operatorA)->postJson($fetchUrl, ['path' => 'C:\\Windows\\System32\\config\\SAM'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->actingAs($this->operatorA)->postJson($fetchUrl, [])->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->startSend($this->operatorA, 10, 'virus.exe')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->startSend($this->operatorA, 104857601)->assertUnprocessable()->assertJsonValidationErrors('size');
        $this->startSend($this->operatorA, 0)->assertUnprocessable();
        $this->actingAs($this->operatorA)->postJson(route('transfers.store', ['uuid' => $this->endpointA->uuid]), [
            'name' => 'a.txt', 'size' => 10, 'dir' => self::OUTBOX,
        ])->assertUnprocessable()->assertJsonValidationErrors('dir');
        // Subnome opcional define o destino.
        $this->actingAs($this->operatorA)->postJson(route('transfers.store', ['uuid' => $this->endpointA->uuid]), [
            'name' => 'origem.txt', 'size' => 10, 'dir' => self::INBOX, 'sub_name' => 'destino.txt',
        ])->assertCreated();
        $this->assertSame(self::INBOX.'\\destino.txt', FileTransfer::query()->sole()->remote_path);

        // Computador sem agente ativo: mensagem, nada criado.
        $this->endpointA->installations()->update(['status' => 'revoked']);
        $this->startSend($this->operatorA, 10)->assertUnprocessable();
        $this->assertSame(1, FileTransfer::query()->count());
    }

    public function test_group_b_user_gets_404_on_every_route_without_leaking(): void
    {
        $operatorB = $this->userWithRole(RoleKey::Operator, $this->groupB);
        $send = $this->newSend(10);
        $fetch = $this->completedFetch('conteudo');
        $unknown = (string) Str::uuid();

        $this->startSend($operatorB, 10)->assertNotFound();
        $this->actingAs($operatorB)->postJson(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), ['path' => self::OUTBOX.'\\a.log'])->assertNotFound();

        foreach ([$send->uuid, $fetch->uuid, $unknown] as $uuid) {
            $responses = [
                $this->putChunk($operatorB, $uuid, 0, '0123456789'),
                $this->uploadStatus($operatorB, $uuid),
                $this->completeUpload($operatorB, $uuid),
                $this->actingAs($operatorB)->postJson(route('transfers.cancel', ['transfer' => $uuid])),
                $this->download($operatorB, $uuid),
            ];
            foreach ($responses as $i => $response) {
                $response->assertNotFound();
                $this->assertStringNotContainsString($uuid, (string) $response->getContent(), "rota {$i} não pode ecoar o uuid");
            }
        }
        // Mesma resposta para o que existe e para o que não existe.
        $this->assertSame(
            $this->uploadStatus($operatorB, $send->uuid)->json(),
            $this->uploadStatus($operatorB, $unknown)->json(),
        );

        $this->assertSame(TransferStatus::Uploading, $send->fresh()->status);
        $this->assertSame(TransferStatus::Completed, $fetch->fresh()->status);
        $this->assertSame(['transfers/'.$fetch->uuid.'/file.bin'], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'transfer.downloaded']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'transfer.cancelled']);
    }

    // --- cancelamento ----------------------------------------------------------------------------

    public function test_cancel_needs_direction_permission_in_scope(): void
    {
        $send = $this->newSend(10);
        $support = $this->userWithRole(RoleKey::Support, $this->groupA);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $this->actingAs($support)->postJson(route('transfers.cancel', ['transfer' => $send->uuid]))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('transfers.cancel', ['transfer' => $send->uuid]))->assertForbidden();
        $this->assertSame(TransferStatus::Uploading, $send->fresh()->status);

        $this->actingAs($this->operatorA)->postJson(route('transfers.cancel', ['transfer' => $send->uuid]))
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->actingAs($this->operatorA)->postJson(route('transfers.cancel', ['transfer' => $send->uuid]))->assertStatus(409);
        $this->putChunk($this->operatorA, $send->uuid, 0, '0123456789')->assertStatus(409);

        // Suporte cancela a recuperação (tem files.transfer_fetch + tasks.cancel); a tarefa também é cancelada.
        $fetch = $this->transfers->createFetch($this->operatorA, $this->endpointA, self::OUTBOX.'\\a.log');
        $this->actingAs($support)->post(route('transfers.cancel', ['transfer' => $fetch->uuid]))->assertRedirect();
        $this->assertSame(TransferStatus::Cancelled, $fetch->fresh()->status);
        $this->assertTrue(RemoteTask::query()->findOrFail($fetch->remote_task_id)->state->isFinal());
    }

    // --- download --------------------------------------------------------------------------------

    public function test_download_only_completed_fetch_with_exact_headers_and_audit(): void
    {
        $content = random_bytes(5000);
        $fetch = $this->completedFetch($content);

        $response = $this->download($this->operatorA, $fetch->uuid)->assertOk();

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame($content, file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertSame('attachment; filename="diag.log"; filename*=UTF-8\'\'diag.log', $response->headers->get('Content-Disposition'));
        $this->assertSame(['nosniff'], $response->headers->all('X-Content-Type-Options'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        // O Symfony sempre acrescenta "private" quando não há public/private explícito.
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.downloaded']);

        // Suporte (files.transfer_fetch) baixa; Auditor e Consulta não.
        $this->download($this->userWithRole(RoleKey::Support, $this->groupA), $fetch->uuid)->assertOk();
        $this->download($this->userWithRole(RoleKey::Auditor), $fetch->uuid)->assertForbidden();
        $this->download($this->userWithRole(RoleKey::Viewer), $fetch->uuid)->assertForbidden();

        // Envio (mesmo concluído) e recuperação não concluída não são baixáveis.
        $send = $this->newSend(10);
        $this->download($this->operatorA, $send->uuid)->assertStatus(409);
        $pending = $this->transfers->createFetch($this->operatorA, $this->endpointA, self::OUTBOX.'\\outro.log');
        $this->download($this->operatorA, $pending->uuid)->assertStatus(409);

        // Blob apagado pela retenção: 409.
        $fetch->forceFill(['purged_at' => now()])->save();
        $this->download($this->operatorA, $fetch->uuid)->assertStatus(409);
    }

    /** @return array<string, array{0: string, 1: string}> nome original hostil => nome ASCII esperado */
    public static function hostileNames(): array
    {
        return [
            'aspas' => ['re"la"torio.log', 're_la_torio.log'],
            'quebra de linha' => ["a\r\nSet-Cookie: x=1.log", 'aSet-Cookie_ x=1.log'],
            'travessia' => ['../../etc/passwd', 'passwd'],
            'barra invertida' => ['..\\..\\win.ini', 'win.ini'],
            'unicode' => ['relatório çã.log', 'relat_rio __.log'],
            'rtl override' => ["fatura\u{202E}gol.exe", 'faturagol.exe'],
            'zero width' => ["a\u{200B}b.log", 'ab.log'],
            'só pontos' => ['...', 'arquivo'],
            'vazio' => ['', 'arquivo'],
            'ponto-e-vírgula' => ['a; filename=b.exe', 'a_ filename=b.exe'],
        ];
    }

    #[DataProvider('hostileNames')]
    public function test_download_filename_is_sanitized(string $original, string $ascii): void
    {
        $fetch = $this->completedFetch('abc');
        $fetch->forceFill(['original_name' => $original])->save();

        $disposition = (string) $this->download($this->operatorA, $fetch->uuid)->assertOk()->headers->get('Content-Disposition');

        $this->assertMatchesRegularExpression('/^attachment; filename="[^"\\\\\/\r\n]*"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]*$/', $disposition);
        preg_match('/filename="([^"]*)"/', $disposition, $plain);
        $this->assertSame($ascii, $plain[1]);

        preg_match("/filename\\*=UTF-8''(.*)$/", $disposition, $encoded);
        $decoded = rawurldecode($encoded[1]);
        $this->assertTrue(mb_check_encoding($decoded, 'UTF-8'));
        $this->assertDoesNotMatchRegularExpression('/[\p{Cc}\p{Cf}"\/\\\\:*?<>|;]/u', $decoded);
        $this->assertNotSame('', $decoded);
        if ($original === 'relatório çã.log') {
            $this->assertSame('relatório çã.log', $decoded, 'Unicode legítimo é preservado no filename*');
        }
    }

    // --- CSRF ------------------------------------------------------------------------------------

    public function test_csrf_is_required_on_state_changing_routes(): void
    {
        // O framework desliga a verificação em testes; aqui ela volta a valer.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $t = $this->transfers->createSend($this->operatorA, $this->endpointA, 'a.txt', 10, self::INBOX, null);

        $this->startSend($this->operatorA, 10)->assertStatus(419);
        $this->putChunk($this->operatorA, $t->uuid, 0, '0123456789')->assertStatus(419);
        $this->completeUpload($this->operatorA, $t->uuid)->assertStatus(419);
        $this->actingAs($this->operatorA)->postJson(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), ['path' => self::OUTBOX.'\\a.log'])->assertStatus(419);
        $this->actingAs($this->operatorA)->postJson(route('transfers.cancel', ['transfer' => $t->uuid]))->assertStatus(419);
        $this->assertSame(1, FileTransfer::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Com o token da sessão (cabeçalho X-CSRF-TOKEN, como o JS do painel envia) passa.
        $this->withSession(['_token' => 'token-de-teste']);
        $this->putChunk($this->operatorA, $t->uuid, 0, '0123456789', 'application/octet-stream', ['HTTP_X_CSRF_TOKEN' => 'token-de-teste'])->assertOk();
        // GETs não precisam de token.
        $this->uploadStatus($this->operatorA, $t->uuid)->assertOk()->assertJsonPath('received_chunks', [0]);
    }
}
