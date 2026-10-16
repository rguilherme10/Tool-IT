<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\FileTransfer;
use App\Models\FileTransferPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\AccessResolver;
use App\Support\Tasks\TaskForm;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Aba "Arquivos" do computador (task 009): visibilidade por permissão, blocos Enviar/Recuperar conforme a
 * política, lista de transferências (escape, download, cancelar, retomar, paginação) e o contrato com o JS
 * (atributos data-*, meta CSRF, script estático sem inline — a CSP bloqueia script/estilo inline).
 */
class FilesTabTest extends TestCase
{
    private const INBOX = 'C:\\ProgramData\\Toolit\\Inbox';

    private EndpointGroup $groupA;

    private EndpointGroup $groupB;

    private Endpoint $endpointA;

    private User $operatorA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->groupA = EndpointGroup::factory()->create(['name' => 'Financeiro']);
        $this->groupB = EndpointGroup::factory()->create(['name' => 'Diretoria']);
        $this->endpointA = $this->activeEndpoint($this->groupA, ['hostname' => 'PC-A-001']);
        $this->activeEndpoint($this->groupB, ['hostname' => 'PC-B-001']);
        FileTransferPolicy::factory()->create();
        $this->operatorA = $this->userWithRole(RoleKey::Operator, $this->groupA);
    }

    private function tab(?Endpoint $endpoint = null, array $query = []): string
    {
        return route('endpoints.show', ['uuid' => ($endpoint ?? $this->endpointA)->uuid, 'tab' => 'files'] + $query);
    }

    private function html(User $user, array $query = []): string
    {
        return $this->actingAs($user)->get($this->tab(null, $query))->assertOk()->getContent();
    }

    private function transfer(array $attributes = []): FileTransfer
    {
        return FileTransfer::factory()->create($attributes + [
            'endpoint_id' => $this->endpointA->id,
            'created_by' => $this->operatorA->id,
        ]);
    }

    /** Atributos do primeiro elemento que casa com o XPath. @return array<string, string> */
    private function attrs(string $html, string $xpath): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $nodes = (new \DOMXPath($dom))->query($xpath);
        $this->assertGreaterThan(0, $nodes->length, "Elemento não encontrado: {$xpath}");
        $out = [];
        foreach ($nodes->item(0)->attributes as $attr) {
            $out[$attr->name] = $attr->value;
        }

        return $out;
    }

    // --- visibilidade da aba ---------------------------------------------------------------------

    public function test_tab_is_visible_with_send_or_fetch_permission_only(): void
    {
        $show = route('endpoints.show', ['uuid' => $this->endpointA->uuid]);

        $this->actingAs($this->operatorA)->get($show)->assertOk()->assertSee('>Arquivos<', false);
        $support = $this->userWithRole(RoleKey::Support, $this->groupA);
        $this->actingAs($support)->get($show)->assertOk()->assertSee('>Arquivos<', false);

        foreach ([RoleKey::Auditor, RoleKey::Viewer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get($show)->assertOk()->assertDontSee('>Arquivos<', false);
            $this->actingAs($user)->get($this->tab())->assertForbidden();
        }
    }

    public function test_user_outside_scope_gets_404(): void
    {
        $operatorB = $this->userWithRole(RoleKey::Operator, $this->groupB);

        $this->actingAs($operatorB)->get($this->tab())->assertNotFound();
    }

    public function test_support_sees_only_the_fetch_block(): void
    {
        $support = $this->userWithRole(RoleKey::Support, $this->groupA);

        $html = $this->html($support);

        $this->assertStringContainsString('Recuperar', $html);
        $this->assertStringContainsString(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), $html);
        $this->assertStringNotContainsString('data-transfer-upload', $html);
        $this->assertStringNotContainsString('js/transfer-upload.js', $html);
    }

    public function test_operator_sees_both_blocks(): void
    {
        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('data-transfer-upload', $html);
        $this->assertStringContainsString(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), $html);
        $this->assertStringContainsString('>Enviar arquivo<', $html);
        $this->assertStringContainsString('>Recuperar arquivo<', $html);
    }

    // --- política --------------------------------------------------------------------------------

    public function test_without_policy_shows_notice_and_no_forms(): void
    {
        FileTransferPolicy::query()->delete();

        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('Sem política de transferência', $html);
        $this->assertStringNotContainsString('data-transfer-upload', $html);
        $this->assertStringNotContainsString(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), $html);
        $this->assertStringNotContainsString('js/transfer-upload.js', $html);
        $this->assertStringContainsString('Nenhuma transferência', $html);
    }

    public function test_empty_direction_list_turns_only_that_direction_off(): void
    {
        FileTransferPolicy::query()->update(['send_dirs' => json_encode([])]);

        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('Envio desligado', $html);
        $this->assertStringNotContainsString('data-transfer-upload', $html);
        $this->assertStringContainsString(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), $html);

        FileTransferPolicy::query()->update(['send_dirs' => json_encode([self::INBOX]), 'fetch_dirs' => json_encode([])]);
        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('Recuperação desligada', $html);
        $this->assertStringContainsString('data-transfer-upload', $html);
        $this->assertStringNotContainsString(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), $html);
    }

    public function test_select_lists_only_policy_send_dirs_and_escapes_them(): void
    {
        FileTransferPolicy::query()->update(['send_dirs' => json_encode([self::INBOX, 'D:\\Recebidos<b>x</b>"\'']), 'fetch_dirs' => json_encode(['C:\\Somente\\Fetch'])]);

        $html = $this->html($this->operatorA);

        $this->assertSame(2, preg_match_all('/<option value="[^"]*Inbox"|<option value="D:[^"]*"/', $html));
        $this->assertStringContainsString('Recebidos&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('Recebidos<b>', $html);
        // fetch_dirs não entram no <select> de destino
        $select = substr($html, (int) strpos($html, '<select'), (int) strpos($html, '</select>') - (int) strpos($html, '<select'));
        $this->assertStringNotContainsString('Somente', $select);
        $this->assertSame(2, substr_count($select, '<option'));
    }

    public function test_allowed_extensions_and_limit_are_shown_as_hint(): void
    {
        FileTransferPolicy::query()->update(['allowed_extensions' => json_encode(['txt', 'pdf']), 'max_bytes' => 5242880]);

        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('.txt', $html);
        $this->assertStringContainsString('.pdf', $html);
        $this->assertStringContainsString('5 MiB', $html);
    }

    // --- contrato com o JS -----------------------------------------------------------------------

    public function test_page_exposes_csrf_meta_and_static_script_without_inline_code(): void
    {
        config(['toolit.transfers.browser_chunk_bytes' => 1048576]);
        $user = $this->operatorA;

        $html = $this->html($user);

        // Token CSRF exposto ao JS e presente nos formulários POST.
        $token = $this->attrs($html, '//meta[@name="csrf-token"]')['content'];
        $this->assertNotSame('', $token);
        $this->assertSame($token, $this->attrs($html, '//form[contains(@action,"/transfers/fetch")]//input[@name="_token"]')['value']);

        // Script estático, com defer, vindo de asset(); nenhum script inline, estilo inline ou handler inline.
        $script = $this->attrs($html, '//script[contains(@src,"transfer-upload.js")]');
        $this->assertSame(asset('js/transfer-upload.js'), $script['src']);
        $this->assertArrayHasKey('defer', $script);
        $this->assertSame(0, preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $html), 'script inline');
        $this->assertSame(0, preg_match('/<[a-z][^>]*\sstyle\s*=/i', $html), 'style inline');
        $this->assertSame(0, preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html), 'handler inline');
        $this->assertSame(0, preg_match('/\bjavascript:/i', $html), 'javascript: URL');
    }

    public function test_upload_form_data_attributes_are_well_formed(): void
    {
        config(['toolit.transfers.browser_chunk_bytes' => 1048576]);
        FileTransferPolicy::query()->update(['max_bytes' => 2097152]);

        $a = $this->attrs($this->html($this->operatorA), '//form[@data-transfer-upload]');
        $nil = '00000000-0000-0000-0000-000000000000';

        $this->assertSame(route('transfers.store', ['uuid' => $this->endpointA->uuid]), $a['data-store-url']);
        $this->assertSame(route('transfers.upload.status', ['transfer' => $nil]), $a['data-status-url']);
        $this->assertSame(route('transfers.upload.complete', ['transfer' => $nil]), $a['data-complete-url']);
        $this->assertSame(route('transfers.cancel', ['transfer' => $nil]), $a['data-cancel-url']);
        $this->assertSame(route('transfers.upload', ['transfer' => $nil, 'n' => 0]), $a['data-chunk-url']);
        $this->assertStringEndsWith('/upload/0', $a['data-chunk-url']);
        $this->assertSame('1048576', $a['data-chunk-size']);
        $this->assertSame('2097152', $a['data-max-bytes']);
        $this->assertSame($nil, $a['data-id-placeholder']);
    }

    public function test_static_script_exists_and_avoids_dangerous_apis(): void
    {
        $path = public_path('js/transfer-upload.js');
        $this->assertFileExists($path);
        $js = (string) file_get_contents($path);

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval(', 'new Function', 'document.write', 'console.log', 'localStorage', 'sessionStorage'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "transfer-upload.js não deve usar {$forbidden}");
        }
        $this->assertStringContainsString('application/octet-stream', $js);
        $this->assertStringContainsString('X-CSRF-TOKEN', $js);
    }

    // --- lista -----------------------------------------------------------------------------------

    public function test_empty_list_has_no_fake_data(): void
    {
        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('Nenhuma transferência', $html);
    }

    public function test_list_escapes_hostile_original_name_path_error_and_operator(): void
    {
        $evil = User::factory()->create(['name' => '<img src=x>Mallory']);
        $this->transfer([
            'created_by' => $evil->id,
            'original_name' => '<script>alert(1)</script>.txt',
            'remote_path' => 'C:\\x\\<b>y</b>.txt',
            'status' => TransferStatus::Failed,
            'error' => '"><svg onload=alert(2)>',
        ]);

        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;.txt', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>y</b>', $html);
        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringNotContainsString('<svg onload', $html);
        $this->assertStringContainsString('Falhou', $html);
    }

    public function test_list_shows_status_direction_size_hash_operator_and_truncated_hash_with_title(): void
    {
        $hash = hash('sha256', 'conteudo');
        $this->transfer([
            'direction' => TransferDirection::Fetch, 'status' => TransferStatus::Completed, 'size' => 1536,
            'sha256' => $hash, 'original_name' => 'diag.log', 'remote_path' => 'C:\\ProgramData\\Toolit\\Outbox\\diag.log',
        ]);

        $html = $this->html($this->operatorA);

        $this->assertStringContainsString('Concluída', $html);
        $this->assertStringContainsString('Recuperação', $html);
        $this->assertStringContainsString('1,5 KiB', $html);
        $this->assertStringContainsString('title="'.$hash.'"', $html);
        $this->assertStringContainsString(substr($hash, 0, 12), $html);
        $this->assertStringNotContainsString('>'.$hash.'<', $html);
        $this->assertStringContainsString($this->operatorA->name, $html);
    }

    public function test_other_endpoints_transfers_are_not_listed(): void
    {
        $other = $this->activeEndpoint($this->groupA, ['hostname' => 'PC-A-002']);
        FileTransfer::factory()->create(['endpoint_id' => $other->id, 'created_by' => $this->operatorA->id, 'original_name' => 'de-outro-pc.txt']);

        $this->assertStringNotContainsString('de-outro-pc.txt', $this->html($this->operatorA));
    }

    public function test_download_link_only_for_completed_fetch_with_permission(): void
    {
        $fetchDone = $this->transfer(['direction' => TransferDirection::Fetch, 'status' => TransferStatus::Completed, 'original_name' => 'ok.log']);
        $fetchReady = $this->transfer(['direction' => TransferDirection::Fetch, 'status' => TransferStatus::Ready, 'original_name' => 'pendente.log']);
        $fetchPurged = $this->transfer(['direction' => TransferDirection::Fetch, 'status' => TransferStatus::Completed, 'original_name' => 'velho.log', 'purged_at' => now()]);
        $sendDone = $this->transfer(['direction' => TransferDirection::Send, 'status' => TransferStatus::Completed, 'original_name' => 'enviado.txt']);

        $html = $this->html($this->operatorA);

        $this->assertStringContainsString(route('transfers.download', ['transfer' => $fetchDone->uuid]), $html);
        foreach ([$fetchReady, $fetchPurged, $sendDone] as $t) {
            $this->assertStringNotContainsString(route('transfers.download', ['transfer' => $t->uuid]), $html);
        }
        $this->assertStringContainsString('apagado', $html); // arquivo removido pela retenção

        // Suporte (fetch) baixa; operador sem files.transfer_fetch não vê link nem o bloco Recuperar.
        $support = $this->userWithRole(RoleKey::Support, $this->groupA);
        $this->assertStringContainsString(route('transfers.download', ['transfer' => $fetchDone->uuid]), $this->html($support));

        $permission = Permission::query()->where('key', 'files.transfer_fetch')->firstOrFail();
        Role::query()->where('key', RoleKey::Operator->value)->firstOrFail()->permissions()->detach($permission->id);
        app(AccessResolver::class)->flush();

        $html = $this->html($this->operatorA);
        $this->assertStringNotContainsString(route('transfers.download', ['transfer' => $fetchDone->uuid]), $html);
        $this->assertStringNotContainsString(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), $html);
        $this->assertStringContainsString('data-transfer-upload', $html);
    }

    public function test_cancel_form_only_where_the_user_may_cancel_open_transfers(): void
    {
        $open = $this->transfer(['direction' => TransferDirection::Fetch, 'status' => TransferStatus::Ready]);
        $final = $this->transfer(['direction' => TransferDirection::Fetch, 'status' => TransferStatus::Cancelled]);
        $sendReady = $this->transfer(['direction' => TransferDirection::Send, 'status' => TransferStatus::Ready]);

        $html = $this->html($this->operatorA);
        $this->assertStringContainsString(route('transfers.cancel', ['transfer' => $open->uuid]), $html);
        $this->assertStringContainsString(route('transfers.cancel', ['transfer' => $sendReady->uuid]), $html);
        $this->assertStringNotContainsString(route('transfers.cancel', ['transfer' => $final->uuid]), $html);
        $this->assertSame(1, preg_match('/action="[^"]*'.$open->uuid.'\/cancel"[^>]*>\s*<input type="hidden" name="_token"/', $html), 'form de cancelar com CSRF');

        // Suporte só cancela recuperação (não tem files.transfer_send).
        $support = $this->userWithRole(RoleKey::Support, $this->groupA);
        $html = $this->html($support);
        $this->assertStringContainsString(route('transfers.cancel', ['transfer' => $open->uuid]), $html);
        $this->assertStringNotContainsString(route('transfers.cancel', ['transfer' => $sendReady->uuid]), $html);
    }

    public function test_resume_hint_only_for_the_creators_own_uploading_send(): void
    {
        $mine = $this->transfer(['status' => TransferStatus::Uploading, 'chunks_total' => 3, 'size' => 2500, 'original_name' => 'grande.zip', 'chunks_received' => 1]);
        $minePastUpload = $this->transfer(['status' => TransferStatus::Ready]);
        $otherOperator = $this->userWithRole(RoleKey::Operator, $this->groupA);
        $theirs = $this->transfer(['created_by' => $otherOperator->id, 'status' => TransferStatus::Uploading, 'original_name' => 'dele.zip']);

        $html = $this->html($this->operatorA);

        $resume = $this->attrs($html, '//button[@data-resume-transfer="'.$mine->uuid.'"]');
        $this->assertSame('grande.zip', $resume['data-name']);
        $this->assertSame('2500', $resume['data-size']);
        $this->assertSame('3', $resume['data-chunks-total']);
        $this->assertSame(1, substr_count($html, 'data-resume-transfer='));
        $this->assertStringNotContainsString($minePastUpload->uuid.'" data-name', $html);
        $this->assertStringNotContainsString('data-resume-transfer="'.$theirs->uuid, $html);

        // Quem não é o criador vê o estado, mas sem o botão de retomar nem o de cancelar o rascunho dos outros.
        $html = $this->html($otherOperator);
        $this->assertStringNotContainsString('data-resume-transfer="'.$mine->uuid, $html);
        $this->assertStringNotContainsString(route('transfers.cancel', ['transfer' => $mine->uuid]), $html);
        $this->assertStringContainsString('data-resume-transfer="'.$theirs->uuid, $html);

        // Retomar exige o bloco Enviar (política com envio ligado).
        FileTransferPolicy::query()->update(['send_dirs' => json_encode([])]);
        $this->assertStringNotContainsString('data-resume-transfer=', $this->html($this->operatorA));
    }

    public function test_pagination_is_20_per_page_and_keeps_the_tab(): void
    {
        foreach (range(1, 25) as $i) {
            $this->transfer(['original_name' => sprintf('arquivo-%02d.txt', $i)]);
        }

        $html = $this->html($this->operatorA);

        $this->assertSame(20, substr_count($html, 'data-transfer-row'));
        $this->assertStringContainsString('tab=files&amp;page=2', $html);
        $this->assertSame(5, substr_count($this->html($this->operatorA, ['page' => 2]), 'data-transfer-row'));
    }

    public function test_list_does_not_run_a_query_per_row(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->operatorA)->get($this->tab())->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->transfer();
        $count();           // aquece caches do processo (papéis/permissões)
        $few = $count();
        foreach (range(1, 12) as $ignored) {
            $this->transfer(['created_by' => User::factory()->create()->id, 'direction' => TransferDirection::Fetch, 'status' => TransferStatus::Completed]);
        }

        $this->assertSame($few, $count());
    }

    public function test_no_execution_options_anywhere(): void
    {
        $this->transfer(['direction' => TransferDirection::Fetch, 'status' => TransferStatus::Completed]);

        $html = mb_strtolower($this->html($this->operatorA));

        foreach (['executar', 'execute', 'rodar após', 'abrir após', 'run after', 'name="run"', 'name="execute"', 'name="args"'] as $word) {
            $this->assertStringNotContainsString($word, $html);
        }
    }

    public function test_fetch_form_keeps_old_input_after_a_rejection(): void
    {
        $response = $this->actingAs($this->operatorA)->from($this->tab())
            ->post(route('transfers.fetch', ['uuid' => $this->endpointA->uuid]), ['path' => 'C:\\Windows\\system.ini']);

        $response->assertRedirect($this->tab());
        $this->actingAs($this->operatorA)->get($this->tab())->assertOk()
            ->assertSee('C:\\Windows\\system.ini', false);
    }

    // --- rótulos de exibição (TaskForm) ----------------------------------------------------------

    public function test_task_labels_for_file_types_are_display_only(): void
    {
        $this->assertSame('Enviar arquivo', TaskForm::label('file.send'));
        $this->assertSame('Recuperar arquivo', TaskForm::label('file.fetch'));
        $this->assertNotContains('file.send', TaskForm::types());
        $this->assertNotContains('file.fetch', TaskForm::types());
    }
}
