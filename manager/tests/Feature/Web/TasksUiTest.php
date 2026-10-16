<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Enums\TaskState;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\RemoteTask;
use App\Models\User;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserService;
use Illuminate\Support\Str;
use Tests\TestCase;

class TasksUiTest extends TestCase
{
    private EndpointGroup $groupA;

    private EndpointGroup $groupB;

    private Endpoint $endpointA;

    private Endpoint $endpointB;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tasks = app(TaskService::class);
        $this->groupA = EndpointGroup::factory()->create(['name' => 'Financeiro']);
        $this->groupB = EndpointGroup::factory()->create(['name' => 'Diretoria']);
        $this->endpointA = $this->activeEndpoint($this->groupA, ['hostname' => 'PC-A-001']);
        $this->endpointB = $this->activeEndpoint($this->groupB, ['hostname' => 'PC-B-001']);
    }

    /** Administrador (MFA obrigatório para o papel: sem 2FA ativo o middleware redireciona). */
    private function admin(?EndpointGroup $group = null): User
    {
        return $this->withTwoFactor($this->userWithRole(RoleKey::Admin, $group));
    }

    private function tab(Endpoint $endpoint): string
    {
        return route('endpoints.show', ['uuid' => $endpoint->uuid, 'tab' => 'tasks']);
    }

    private function storeUrl(Endpoint $endpoint): string
    {
        return route('tasks.store', ['uuid' => $endpoint->uuid]);
    }

    private function payload(string $type, array $extra = []): array
    {
        return ['type' => $type, 'idempotency_key' => (string) Str::uuid()] + $extra;
    }

    private function killTask(User $by, Endpoint $endpoint, string $name = 'notepad.exe'): RemoteTask
    {
        return $this->tasks->create($by, $endpoint, 'process.kill', ['pid' => 4321, 'name' => $name]);
    }

    public function test_tasks_tab_visible_only_with_tasks_view(): void
    {
        $viewer = $this->userWithRole(RoleKey::Viewer);   // Consulta: sem tasks.view
        $this->actingAs($viewer)->get(route('endpoints.show', ['uuid' => $this->endpointA->uuid]))->assertOk()->assertDontSee('>Tarefas<', false);
        $this->actingAs($viewer)->get($this->tab($this->endpointA))->assertForbidden();

        $auditor = $this->userWithRole(RoleKey::Auditor);  // só tasks.view
        $this->actingAs($auditor)->get($this->tab($this->endpointA))->assertOk()
            ->assertSee('>Tarefas<', false)->assertSee('Nenhuma tarefa')->assertDontSee('Nova tarefa');

        // Escopo por grupo: operador do grupo B nem enxerga o computador do grupo A (404).
        $operatorB = $this->userWithRole(RoleKey::Operator, $this->groupB);
        $this->actingAs($operatorB)->get($this->tab($this->endpointA))->assertNotFound();
        $this->actingAs($operatorB)->get($this->tab($this->endpointB))->assertOk();
    }

    public function test_create_form_lists_only_allowed_types(): void
    {
        $support = $this->userWithRole(RoleKey::Support);
        $page = $this->actingAs($support)->get($this->tab($this->endpointA))->assertOk();
        $page->assertSee('Nova tarefa')->assertSee('Listar processos')->assertSee('Listar serviços')->assertSee('Informações do sistema');
        $page->assertDontSee('Encerrar processo')->assertDontSee('Reiniciar serviço')->assertDontSee('Reiniciar o agente');

        $operator = $this->userWithRole(RoleKey::Operator);
        $this->actingAs($operator)->get($this->tab($this->endpointA))->assertOk()
            ->assertSee('Encerrar processo')->assertSee('Reiniciar serviço')->assertSee('Reiniciar o agente');
    }

    public function test_store_creates_task_with_idempotency_key_and_redirects(): void
    {
        $support = $this->userWithRole(RoleKey::Support);
        $data = $this->payload('process.list');

        $this->actingAs($support)->post($this->storeUrl($this->endpointA), $data)
            ->assertRedirect($this->tab($this->endpointA))->assertSessionHas('status');

        $task = RemoteTask::firstOrFail();
        $this->assertSame('process.list', $task->type);
        $this->assertSame($support->id, $task->created_by);
        $this->assertSame($data['idempotency_key'], $task->idempotency_key);
        $this->assertSame(TaskState::Available, $task->state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.created', 'endpoint_id' => $this->endpointA->id]);

        // Reenvio do formulário (duplo clique/F5): mesma tarefa, nada novo.
        $this->actingAs($support)->post($this->storeUrl($this->endpointA), $data)->assertRedirect($this->tab($this->endpointA));
        $this->assertSame(1, RemoteTask::count());
    }

    public function test_store_reusing_key_for_another_endpoint_or_type_is_rejected(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $data = $this->payload('process.list');
        $this->actingAs($operator)->post($this->storeUrl($this->endpointA), $data)->assertRedirect();

        $this->actingAs($operator)->post($this->storeUrl($this->endpointB), $data)->assertSessionHasErrors('idempotency_key');
        $this->actingAs($operator)->post($this->storeUrl($this->endpointA), ['type' => 'service.list'] + $data)->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, RemoteTask::count());
    }

    public function test_store_requires_valid_type_and_key(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);

        foreach (['powershell.run', 'files.send', 'screen.view', 'bogus'] as $type) {
            $this->actingAs($operator)->post($this->storeUrl($this->endpointA), $this->payload($type))->assertSessionHasErrors('type');
        }
        $this->actingAs($operator)->post($this->storeUrl($this->endpointA), ['type' => 'process.list'])->assertSessionHasErrors('idempotency_key');
        $this->actingAs($operator)->post($this->storeUrl($this->endpointA), ['type' => 'process.list', 'idempotency_key' => 'abc'])->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, RemoteTask::count());
    }

    public function test_store_hostile_params_returns_validation_error(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $cases = [
            ['service.restart', ['name' => 'Spooler; calc']],
            ['service.restart', ['name' => 'a b']],
            ['service.restart', ['name' => '..\\x']],
            ['service.restart', ['name' => str_repeat('a', 512)]],
            ['service.restart', ['name' => ['Spooler']]],
            ['service.restart', ['name' => "Spool\ner"]],   // um \n final seria aparado pelo TrimStrings; no meio, não
            ['service.restart', ['name' => '']],
            ['process.kill', ['pid' => '-1', 'name' => 'x.exe']],
            ['process.kill', ['pid' => 'abc', 'name' => 'x.exe']],
            ['process.kill', ['pid' => '4', 'name' => 'System']],
            ['process.kill', ['pid' => '12.5', 'name' => 'x.exe']],
            ['process.kill', ['name' => 'x.exe']],
            ['process.kill', ['pid' => '4321', 'name' => str_repeat('n', 200)]],
        ];

        foreach ($cases as $i => [$type, $fields]) {
            $this->actingAs($operator)->from($this->tab($this->endpointA))
                ->post($this->storeUrl($this->endpointA), $this->payload($type, $fields))
                ->assertRedirect($this->tab($this->endpointA))
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, RemoteTask::count());
    }

    public function test_store_valid_process_kill_goes_to_pending_approval(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $this->actingAs($operator)->post($this->storeUrl($this->endpointA), $this->payload('process.kill', ['pid' => '4321', 'name' => 'notepad.exe']))
            ->assertRedirect($this->tab($this->endpointA));

        $task = RemoteTask::firstOrFail();
        $this->assertSame(TaskState::PendingApproval, $task->state);
        $this->assertSame(['pid' => 4321, 'name' => 'notepad.exe'], $task->params);
    }

    public function test_store_without_permission_is_403_and_nothing_is_created(): void
    {
        $support = $this->userWithRole(RoleKey::Support);
        $auditor = $this->userWithRole(RoleKey::Auditor);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $this->actingAs($support)->post($this->storeUrl($this->endpointA), $this->payload('process.kill', ['pid' => '4321', 'name' => 'x.exe']))->assertForbidden();
        $this->actingAs($auditor)->post($this->storeUrl($this->endpointA), $this->payload('process.list'))->assertForbidden();
        $this->actingAs($viewer)->post($this->storeUrl($this->endpointA), $this->payload('process.list'))->assertForbidden();
        $this->assertSame(0, RemoteTask::count());
    }

    public function test_store_on_inactive_endpoint_shows_error_not_500(): void
    {
        $this->endpointA->installations()->update(['status' => 'revoked']);
        $operator = $this->userWithRole(RoleKey::Operator);

        $this->actingAs($operator)->from($this->tab($this->endpointA))->post($this->storeUrl($this->endpointA), $this->payload('process.list'))
            ->assertRedirect($this->tab($this->endpointA))->assertSessionHasErrors('task');
        $this->assertSame(0, RemoteTask::count());
    }

    public function test_high_risk_shows_pending_approval_and_creator_has_no_approve_button(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $task = $this->killTask($admin, $this->endpointA);

        $mine = $this->actingAs($admin)->get(route('tasks.show', $task->uuid))->assertOk();
        $mine->assertSee('Aguardando aprovação')->assertDontSee('>Aprovar<', false)->assertDontSee('>Rejeitar<', false);
        $this->actingAs($admin)->get(route('tasks.approvals'))->assertOk()->assertSee('notepad.exe')->assertDontSee('>Aprovar<', false);
        $this->actingAs($admin)->get($this->tab($this->endpointA))->assertOk()->assertSee('Aguardando aprovação')->assertDontSee('>Aprovar<', false);

        $theirs = $this->actingAs($other)->get(route('tasks.show', $task->uuid))->assertOk();
        $theirs->assertSee('>Aprovar<', false)->assertSee('>Rejeitar<', false);
        $this->actingAs($other)->get(route('tasks.approvals'))->assertSee('>Aprovar<', false);

        // Defesa no serviço: criador forçando o POST recebe erro, não 500, e nada muda.
        $this->actingAs($admin)->from(route('tasks.show', $task->uuid))->post(route('tasks.approve', $task->uuid))
            ->assertRedirect(route('tasks.show', $task->uuid))->assertSessionHasErrors('task');
        $this->actingAs($admin)->from(route('tasks.show', $task->uuid))->post(route('tasks.reject', $task->uuid))->assertSessionHasErrors('task');
        $this->assertSame(TaskState::PendingApproval, $task->fresh()->state);
    }

    public function test_approvals_page_lists_only_scoped_tasks(): void
    {
        $creator = $this->admin();
        $this->killTask($creator, $this->endpointA, 'visivel.exe');
        $this->killTask($creator, $this->endpointB, 'oculto.exe');
        $this->tasks->create($creator, $this->endpointA, 'process.list', []);   // sem aprovação: não aparece

        $adminA = $this->admin($this->groupA);
        $this->actingAs($adminA)->get(route('tasks.approvals'))->assertOk()
            ->assertSee('visivel.exe')->assertSee('PC-A-001')->assertDontSee('oculto.exe')->assertDontSee('PC-B-001')->assertDontSee('Listar processos');

        $this->actingAs($this->admin())->get(route('tasks.approvals'))->assertSee('visivel.exe')->assertSee('oculto.exe');

        // Sem tasks.approve em nenhum escopo: 403 e sem link no menu.
        $operator = $this->userWithRole(RoleKey::Operator);
        $this->actingAs($operator)->get(route('tasks.approvals'))->assertForbidden();
        $this->actingAs($operator)->get('/')->assertDontSee('Aprovações');
        $this->actingAs($this->admin())->get('/')->assertSee('Aprovações');
    }

    public function test_approvals_empty_state_has_no_fake_data(): void
    {
        $this->actingAs($this->admin())->get(route('tasks.approvals'))->assertOk()->assertSee('Nenhuma tarefa aguardando aprovação');
    }

    public function test_approve_by_other_admin_works_and_audited(): void
    {
        $creator = $this->admin();
        $approver = $this->admin();
        $task = $this->killTask($creator, $this->endpointA);

        $this->actingAs($approver)->from(route('tasks.approvals'))->post(route('tasks.approve', $task->uuid))
            ->assertRedirect(route('tasks.approvals'))->assertSessionHas('status');

        $fresh = $task->fresh();
        $this->assertSame(TaskState::Available, $fresh->state);
        $this->assertSame($approver->id, $fresh->approved_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.approved', 'actor_id' => (string) $approver->id, 'endpoint_id' => $this->endpointA->id]);
    }

    public function test_reject_by_other_admin_cancels_and_is_audited(): void
    {
        $creator = $this->admin();
        $approver = $this->admin();
        $task = $this->killTask($creator, $this->endpointA);

        $this->actingAs($approver)->post(route('tasks.reject', $task->uuid), ['reason' => 'Não autorizado pelo gestor'])->assertSessionHas('status');

        $this->assertSame(TaskState::Cancelled, $task->fresh()->state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.rejected', 'actor_id' => (string) $approver->id]);
    }

    public function test_operator_cannot_approve_even_in_scope(): void
    {
        $admin = $this->admin();
        $task = $this->killTask($admin, $this->endpointA);
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $this->actingAs($operator)->post(route('tasks.approve', $task->uuid))->assertForbidden();
        $this->actingAs($operator)->post(route('tasks.reject', $task->uuid))->assertForbidden();
        $this->assertSame(TaskState::PendingApproval, $task->fresh()->state);
    }

    public function test_bola_group_a_operator_cannot_touch_group_b_tasks(): void
    {
        $admin = $this->admin();
        $taskB = $this->killTask($admin, $this->endpointB);
        $operatorA = $this->userWithRole(RoleKey::Operator, $this->groupA);
        $adminA = $this->admin($this->groupA);

        $this->actingAs($operatorA)->post($this->storeUrl($this->endpointB), $this->payload('process.list'))->assertNotFound();
        $this->actingAs($operatorA)->get(route('tasks.show', $taskB->uuid))->assertNotFound();
        $this->actingAs($operatorA)->post(route('tasks.cancel', $taskB->uuid))->assertNotFound();
        $this->actingAs($adminA)->get(route('tasks.show', $taskB->uuid))->assertNotFound();
        $this->actingAs($adminA)->post(route('tasks.approve', $taskB->uuid))->assertNotFound();
        $this->actingAs($adminA)->post(route('tasks.reject', $taskB->uuid))->assertNotFound();

        $this->assertSame(TaskState::PendingApproval, $taskB->fresh()->state);
        $this->assertSame(1, RemoteTask::count());

        // UUID inexistente ou malformado: também 404.
        $this->actingAs($operatorA)->get(route('tasks.show', (string) Str::uuid()))->assertNotFound();
        $this->actingAs($operatorA)->get('/tasks/1')->assertNotFound();
    }

    public function test_cancel_requires_permission(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $task = $this->tasks->create($operator, $this->endpointA, 'process.list', []);

        $this->actingAs($this->userWithRole(RoleKey::Auditor))->post(route('tasks.cancel', $task->uuid))->assertForbidden();
        $this->actingAs($this->userWithRole(RoleKey::Viewer))->post(route('tasks.cancel', $task->uuid))->assertForbidden();
        $this->assertSame(TaskState::Available, $task->fresh()->state);

        $this->actingAs($operator)->from($this->tab($this->endpointA))->post(route('tasks.cancel', $task->uuid))
            ->assertRedirect($this->tab($this->endpointA))->assertSessionHas('status');
        $this->assertSame(TaskState::Cancelled, $task->fresh()->state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.cancelled', 'actor_id' => (string) $operator->id]);

        // Já encerrada: erro amigável, não 500.
        $this->actingAs($operator)->from($this->tab($this->endpointA))->post(route('tasks.cancel', $task->uuid))->assertSessionHasErrors('task');
    }

    public function test_cancel_button_only_for_users_who_can_cancel_open_tasks(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $task = $this->tasks->create($operator, $this->endpointA, 'process.list', []);
        $url = route('tasks.cancel', $task->uuid);

        $this->actingAs($operator)->get($this->tab($this->endpointA))->assertSee('action="'.$url.'"', false);
        $this->actingAs($this->userWithRole(RoleKey::Auditor))->get($this->tab($this->endpointA))->assertOk()->assertDontSee('action="'.$url.'"', false);
        $this->actingAs($this->userWithRole(RoleKey::Auditor))->get(route('tasks.show', $task->uuid))->assertOk()->assertDontSee('action="'.$url.'"', false);
    }

    public function test_detail_escapes_output(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $task = $this->tasks->create($operator, $this->endpointA, 'process.list', []);
        $claim = $this->tasks->claim($this->endpointA);
        $this->tasks->complete($task, $claim['attempt']->uuid, [
            'status' => 'completed', 'exit_code' => 0,
            'stdout' => '<script>alert(1)</script> & "aspas"', 'stderr' => '<img src=x onerror=alert(2)>',
        ]);

        $page = $this->actingAs($operator)->get(route('tasks.show', $task->uuid))->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
        $page->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;aspas&quot;', false);
        $page->assertSee('&lt;img src=x onerror=alert(2)&gt;', false);
        $page->assertSee('Concluída')->assertSee('Tentativa 1');
    }

    public function test_params_and_names_are_escaped_everywhere(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $name = '<script>alert(9)</script>.exe';
        $task = $this->killTask($admin, $this->endpointA, $name);

        foreach ([route('tasks.show', $task->uuid), $this->tab($this->endpointA), route('tasks.approvals')] as $url) {
            $this->actingAs($other)->get($url)->assertOk()->assertDontSee('<script>alert(9)</script>', false)
                ->assertSee('&lt;script&gt;alert(9)&lt;/script&gt;.exe', false);
        }
    }

    public function test_detail_shows_state_risk_creator_approver_and_params(): void
    {
        $creator = User::factory()->create(['name' => 'Carla Criadora']);
        app(UserService::class)->assignRole($creator, RoleKey::Admin, null);
        $this->withTwoFactor($creator);
        $approver = User::factory()->create(['name' => 'Aldo Aprovador']);
        app(UserService::class)->assignRole($approver, RoleKey::Admin, null);
        $task = $this->killTask($creator, $this->endpointA);
        $this->tasks->approve($approver, $task);

        $this->actingAs($creator)->get(route('tasks.show', $task->uuid))->assertOk()
            ->assertSee('Encerrar processo')->assertSee('Disponível')->assertSee('Alto')
            ->assertSee('Carla Criadora')->assertSee('Aldo Aprovador')->assertSee('PC-A-001')
            ->assertSee('4321')->assertSee('notepad.exe');
    }

    public function test_detail_requires_tasks_view(): void
    {
        $task = $this->tasks->create($this->admin(), $this->endpointA, 'process.list', []);

        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get(route('tasks.show', $task->uuid))->assertForbidden();
        $this->actingAs($this->userWithRole(RoleKey::Auditor))->get(route('tasks.show', $task->uuid))->assertOk();
    }

    public function test_list_is_paginated_by_20_and_empty_state_is_honest(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $this->actingAs($operator)->get($this->tab($this->endpointA))->assertSee('Nenhuma tarefa');

        RemoteTask::factory()->count(25)->forEndpoint($this->endpointA)->create(['created_by' => $operator->id]);
        RemoteTask::factory()->count(3)->forEndpoint($this->endpointB)->create(['created_by' => $operator->id]);

        $page = $this->actingAs($operator)->get($this->tab($this->endpointA))->assertOk()->assertDontSee('Nenhuma tarefa');
        $this->assertSame(20, substr_count($page->getContent(), 'data-task-row'));
        $second = $this->actingAs($operator)->get($this->tab($this->endpointA).'&page=2')->assertOk();
        $this->assertSame(5, substr_count($second->getContent(), 'data-task-row'));
    }

    public function test_no_powershell_files_or_screen_options_in_ui(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $task = $this->killTask($admin, $this->endpointA);

        foreach ([$this->tab($this->endpointA), route('tasks.approvals'), route('tasks.show', $task->uuid)] as $url) {
            $text = $this->actingAs($other)->get($url)->assertOk()->getContent();
            foreach (['powershell', 'files.', 'screen.', 'enviar arquivo', 'baixar arquivo', 'transferência de arquivo', 'ver tela', 'compartilhar tela'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $text, "{$word} em {$url}");
            }
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('tasks.approvals'))->assertRedirect('/login');
        $this->post($this->storeUrl($this->endpointA), $this->payload('process.list'))->assertRedirect('/login');
        $this->get('/tasks/'.Str::uuid())->assertRedirect('/login');
    }

    public function test_form_embeds_a_fresh_uuid_idempotency_key_per_render(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $a = $this->actingAs($operator)->get($this->tab($this->endpointA))->getContent();
        $b = $this->actingAs($operator)->get($this->tab($this->endpointA))->getContent();

        preg_match_all('/name="idempotency_key" value="([0-9a-f-]{36})"/', $a, $ma);
        preg_match_all('/name="idempotency_key" value="([0-9a-f-]{36})"/', $b, $mb);
        $this->assertNotEmpty($ma[1]);
        $this->assertSame([], array_intersect($ma[1], $mb[1]));
        $this->assertSame(count($ma[1]), count(array_unique($ma[1])));
    }
}
