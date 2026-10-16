<?php

namespace Tests\Feature\Api\Agent;

use App\Enums\RoleKey;
use App\Enums\TaskState;
use App\Http\Requests\Api\Agent\TaskResultRequest;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\RemoteTask;
use App\Models\RemoteTaskAttempt;
use App\Models\User;
use App\Services\Agents\RevocationService;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class TasksApiTest extends TestCase
{
    private const NEXT = '/api/agent/v1/tasks/next?wait=0';

    private const HB = '/api/agent/v1/heartbeat';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole(RoleKey::Admin);
    }

    /** @return array<string, mixed> */
    private function contract(string $name): array
    {
        return json_decode(file_get_contents(base_path("../tests/contracts/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private function service(): TaskService
    {
        return app(TaskService::class);
    }

    private function create(Endpoint $endpoint, string $type = 'process.list', array $params = []): RemoteTask
    {
        return $this->service()->create($this->admin, $endpoint, $type, $params);
    }

    /** Agent real inscrito + o computador dele. @return array{0: FakeAgent, 1: Endpoint} */
    private function agentAndEndpoint(): array
    {
        $agent = $this->enrolledAgent();

        return [$agent, Endpoint::firstOrFail()];
    }

    public function test_next_returns_204_when_empty(): void
    {
        [$agent] = $this->agentAndEndpoint();

        $response = $agent->get(self::NEXT)->assertNoContent();
        $this->assertSame('', $response->getContent());
        // Sem o parâmetro wait também vale.
        $agent->get('/api/agent/v1/tasks/next')->assertNoContent();
    }

    public function test_next_returns_task_with_attempt_and_lease(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $task = $this->create($endpoint, 'service.restart', ['name' => 'Spooler']);

        $body = $agent->get(self::NEXT)->assertOk()->json();

        $this->assertEqualsCanonicalizing(array_keys($this->contract('task-next')), array_keys($body), 'mesmas chaves do contrato');
        $this->assertSame($task->uuid, $body['task_id']);
        $this->assertSame('service.restart', $body['type']);
        $this->assertSame(['name' => 'Spooler'], $body['params']);
        $this->assertSame(60, $body['timeout_seconds']);
        $this->assertSame('medium', $body['risk']);
        $this->assertTrue(Str::isUuid($body['attempt_id']));
        $this->assertSame(RemoteTaskAttempt::firstOrFail()->uuid, $body['attempt_id']);
        // lease = timeout + 30 s, no relógio do servidor
        $this->assertEqualsWithDelta(now()->addSeconds(90)->getTimestamp(), strtotime($body['lease_expires_at']), 3);

        // Já foi pega: o próximo poll não devolve a mesma tarefa.
        $agent->get(self::NEXT)->assertNoContent();
        $this->assertSame(TaskState::Running, $task->fresh()->state);
    }

    public function test_next_serializes_empty_params_as_json_object(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $this->create($endpoint, 'process.list');

        $raw = $agent->get(self::NEXT)->assertOk()->getContent();

        $this->assertStringContainsString('"params":{}', $raw);
    }

    public function test_next_validates_wait_and_requires_valid_signature_including_query(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $this->create($endpoint);

        // Query adulterada depois da assinatura → 401, e nada é pego.
        $agent->get(self::NEXT, ['signed_query' => 'wait=30'])->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid');
        $agent->get(self::NEXT, ['signed_query' => ''])->assertUnauthorized();
        $this->assertSame(0, RemoteTaskAttempt::count());

        foreach (['wait=31', 'wait=-1', 'wait=abc'] as $query) {
            $agent->get('/api/agent/v1/tasks/next?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        }
        $this->assertSame(0, RemoteTaskAttempt::count());

        $agent->get('/api/agent/v1/tasks/next?wait=30')->assertOk(); // aceito e ignorado: resposta imediata
    }

    public function test_other_endpoint_task_is_404(): void
    {
        [$agent] = $this->agentAndEndpoint();
        $other = $this->service()->create($this->admin, $this->activeEndpoint(), 'process.list', []);
        $attempt = (string) Str::uuid();

        $agent->post("/api/agent/v1/tasks/{$other->uuid}/progress", ['attempt_id' => $attempt])
            ->assertNotFound()->assertJsonPath('error.code', 'request.not_found');
        $agent->post("/api/agent/v1/tasks/{$other->uuid}/result", ['attempt_id' => $attempt, 'status' => 'completed'])
            ->assertNotFound()->assertJsonPath('error.code', 'request.not_found');
        $agent->post('/api/agent/v1/tasks/'.Str::uuid().'/result', ['attempt_id' => $attempt, 'status' => 'completed'])
            ->assertNotFound()->assertJsonPath('error.code', 'request.not_found');
        $agent->post('/api/agent/v1/tasks/nao-e-uuid/result', ['attempt_id' => $attempt, 'status' => 'completed'])->assertNotFound();

        $this->assertSame(TaskState::Available, $other->fresh()->state);
    }

    public function test_agent_never_receives_tasks_of_another_endpoint(): void
    {
        [$agent] = $this->agentAndEndpoint();
        $this->service()->create($this->admin, $this->activeEndpoint(), 'process.list', []);

        $agent->get(self::NEXT)->assertNoContent();
    }

    public function test_result_roundtrip_and_conflict_409(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $task = $this->create($endpoint);
        $next = $agent->get(self::NEXT)->assertOk()->json();
        $url = "/api/agent/v1/tasks/{$next['task_id']}/result";

        $result = array_replace($this->contract('task-result'), [
            'attempt_id' => $next['attempt_id'],
            'started_at' => now()->subSeconds(2)->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ]);

        $agent->post($url, $result)->assertOk()->assertJsonPath('state', 'completed');
        $this->assertSame(TaskState::Completed, $task->fresh()->state);
        $this->assertSame('Serviço Spooler reiniciado.', RemoteTaskAttempt::firstOrFail()->stdout);

        // Reenvio idêntico: 200 e nada muda.
        $agent->post($url, $result)->assertOk()->assertJsonPath('state', 'completed');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'task.completed')->count(), 'reenvio não audita de novo');

        // Resultado divergente do mesmo attempt, attempt desconhecido e progresso tardio → 409.
        $agent->post($url, array_replace($result, ['stdout' => 'outra coisa', 'status' => 'failed']))
            ->assertStatus(409)->assertJsonPath('error.code', 'task.conflict');
        $agent->post($url, array_replace($result, ['attempt_id' => (string) Str::uuid()]))
            ->assertStatus(409)->assertJsonPath('error.code', 'task.conflict');
        $agent->post("/api/agent/v1/tasks/{$next['task_id']}/progress", ['attempt_id' => $next['attempt_id']])
            ->assertStatus(409)->assertJsonPath('error.code', 'task.conflict');

        $this->assertSame(TaskState::Completed, $task->fresh()->state);
        $this->assertSame('Serviço Spooler reiniciado.', RemoteTaskAttempt::firstOrFail()->stdout);
    }

    public function test_shared_contract_files_match_the_validation_rules(): void
    {
        $rules = array_keys((new TaskResultRequest)->rules());
        $keys = array_keys($this->contract('task-result'));

        $this->assertEqualsCanonicalizing($rules, $keys, 'task-result.json cobre exatamente os campos validados');
        $this->assertEqualsCanonicalizing(
            ['task_id', 'attempt_id', 'type', 'params', 'timeout_seconds', 'lease_expires_at', 'risk'],
            array_keys($this->contract('task-next')),
        );
    }

    public function test_result_truncates_large_output_accepted_by_validation(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $this->create($endpoint);
        $next = $agent->get(self::NEXT)->json();

        $agent->post("/api/agent/v1/tasks/{$next['task_id']}/result", [
            'attempt_id' => $next['attempt_id'], 'status' => 'completed', 'exit_code' => 0,
            'stdout' => str_repeat('a', 65536), 'stderr' => '',
        ])->assertOk();

        $attempt = RemoteTaskAttempt::firstOrFail();
        $this->assertSame(TaskService::OUTPUT_LIMIT, strlen($attempt->stdout));
        $this->assertTrue($attempt->truncated);
    }

    public function test_result_body_validation(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $task = $this->create($endpoint);
        $next = $agent->get(self::NEXT)->json();
        $url = "/api/agent/v1/tasks/{$task->uuid}/result";
        $ok = ['attempt_id' => $next['attempt_id'], 'status' => 'completed'];

        $bad = [
            [['status' => 'exploded'] + $ok],
            [['status' => 'running'] + $ok],
            [array_diff_key($ok, ['status' => 1])],
            [array_diff_key($ok, ['attempt_id' => 1])],
            [['attempt_id' => 'nao-uuid'] + $ok],
            [['stdout' => str_repeat('a', 65537)] + $ok],
            [['stderr' => str_repeat('a', 65537)] + $ok],
            [['stdout' => ['x']] + $ok],
            [['exit_code' => 'zero'] + $ok],
            [['truncated' => 'talvez'] + $ok],
        ];
        foreach ($bad as [$payload]) {
            $agent->post($url, $payload)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        }

        // Nada foi gravado pelos pedidos inválidos.
        $this->assertSame(TaskState::Running, $task->fresh()->state);
        $this->assertNull(RemoteTaskAttempt::firstOrFail()->status);

        $agent->post("/api/agent/v1/tasks/{$task->uuid}/progress", ['attempt_id' => 'nao-uuid'])->assertUnprocessable();
        $agent->post("/api/agent/v1/tasks/{$task->uuid}/progress", ['attempt_id' => $next['attempt_id'], 'percent' => 101])->assertUnprocessable();
    }

    public function test_progress_renews_the_lease(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $task = $this->create($endpoint);
        $next = $agent->get(self::NEXT)->json();

        $this->travel(20)->seconds();
        $response = $agent->post("/api/agent/v1/tasks/{$task->uuid}/progress", ['attempt_id' => $next['attempt_id'], 'percent' => 40])
            ->assertOk();

        $this->assertGreaterThan(strtotime($next['lease_expires_at']), strtotime($response->json('lease_expires_at')));
        $this->assertSame(TaskState::Running, $task->fresh()->state);
    }

    public function test_heartbeat_reports_tasks_available_and_cancel_ids(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $agent->post(self::HB)->assertOk()->assertJson(['tasks_available' => false, 'cancel_task_ids' => []]);

        $task = $this->create($endpoint);
        $agent->post(self::HB)->assertOk()->assertJson(['tasks_available' => true, 'cancel_task_ids' => []]);

        $agent->get(self::NEXT)->assertOk();
        $agent->post(self::HB)->assertOk()->assertJson(['tasks_available' => false, 'cancel_task_ids' => []]);

        $this->service()->cancel($this->admin, $task); // em execução → só registra o pedido
        $agent->post(self::HB)->assertOk()->assertJson(['tasks_available' => false, 'cancel_task_ids' => [$task->uuid]]);
    }

    public function test_heartbeat_ignores_tasks_of_other_endpoints(): void
    {
        [$agent] = $this->agentAndEndpoint();
        $this->service()->create($this->admin, $this->activeEndpoint(), 'process.list', []);

        $agent->post(self::HB)->assertOk()->assertJson(['tasks_available' => false]);
    }

    public function test_revoked_agent_gets_403_on_tasks(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $task = $this->create($endpoint);
        app(RevocationService::class)->revoke(AgentInstallation::firstOrFail(), $this->admin, 'perdido');

        $agent->get(self::NEXT)->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
        $agent->post("/api/agent/v1/tasks/{$task->uuid}/result", ['attempt_id' => (string) Str::uuid(), 'status' => 'completed'])
            ->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
        $agent->post("/api/agent/v1/tasks/{$task->uuid}/progress", ['attempt_id' => (string) Str::uuid()])
            ->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
    }

    public function test_revocation_cancels_open_tasks(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $running = $this->create($endpoint);
        $agent->get(self::NEXT)->assertOk();
        $available = $this->create($endpoint, 'system.info');
        $pending = $this->create($endpoint, 'agent.restart');
        $done = $this->create($endpoint, 'inventory.refresh');
        RemoteTask::whereKey($done->id)->update(['state' => TaskState::Completed->value]);
        $otherEndpointTask = $this->service()->create($this->admin, $this->activeEndpoint(), 'process.list', []);

        app(RevocationService::class)->revoke(AgentInstallation::firstOrFail(), $this->admin, 'perdido');

        foreach ([$running, $available, $pending] as $task) {
            $this->assertSame(TaskState::Cancelled, $task->fresh()->state);
            $this->assertDatabaseHas('audit_logs', ['action' => 'task.cancelled', 'endpoint_id' => $endpoint->id]);
        }
        $this->assertSame(TaskState::Completed, $done->fresh()->state, 'tarefa encerrada não muda');
        $this->assertSame(TaskState::Available, $otherEndpointTask->fresh()->state, 'outro computador não é afetado');
        $this->assertSame('cancelled', RemoteTaskAttempt::firstOrFail()->status);
        $this->assertSame('agent_revoked', $running->fresh()->result_summary['reason']);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_deactivating_endpoint_cancels_open_tasks(): void
    {
        [$agent, $endpoint] = $this->agentAndEndpoint();
        $running = $this->create($endpoint);
        $agent->get(self::NEXT)->assertOk();
        $available = $this->create($endpoint, 'system.info');
        $pending = $this->create($endpoint, 'agent.restart');

        $this->actingAs($this->withTwoFactor($this->admin))->post('/endpoints/'.$endpoint->uuid.'/status', ['status' => 'disabled'])->assertRedirect();

        foreach ([$running, $available, $pending] as $task) {
            $task->refresh();
            $this->assertSame(TaskState::Cancelled, $task->state);
            $this->assertSame('endpoint_deactivated', $task->result_summary['reason']);
        }
        $agent->get(self::NEXT)->assertForbidden()->assertJsonPath('error.code', 'endpoint.disabled');
        $this->artisan('audit:verify')->assertSuccessful();
    }
}
