<?php

namespace Tests\Feature\Tasks;

use App\Enums\RoleKey;
use App\Enums\TaskRisk;
use App\Enums\TaskState;
use App\Exceptions\TaskConflictException;
use App\Models\Endpoint;
use App\Models\RemoteTask;
use App\Models\RemoteTaskAttempt;
use App\Models\User;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    private TaskService $tasks;

    private User $admin;

    private Endpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tasks = app(TaskService::class);
        $this->admin = $this->userWithRole(RoleKey::Admin);
        $this->endpoint = $this->activeEndpoint();
    }

    /** Tarefa já em execução, com attempt vigente. */
    private function running(string $type = 'process.list', array $params = []): array
    {
        $this->tasks->create($this->admin, $this->endpoint, $type, $params);

        return $this->tasks->claim($this->endpoint);
    }

    public function test_low_risk_is_available_and_high_risk_needs_approval(): void
    {
        $low = $this->tasks->create($this->admin, $this->endpoint, 'process.list', []);
        $medium = $this->tasks->create($this->admin, $this->endpoint, 'service.restart', ['name' => 'Spooler']);
        $high = $this->tasks->create($this->admin, $this->endpoint, 'process.kill', ['pid' => 1234, 'name' => 'notepad.exe']);
        $outside = $this->tasks->create($this->admin, $this->endpoint, 'service.start', ['name' => 'RpcSs']);

        $this->assertSame(TaskState::Available, $low->state);
        $this->assertSame(TaskRisk::Low, $low->risk);
        $this->assertSame(TaskState::Available, $medium->state);
        $this->assertSame(TaskRisk::Medium, $medium->risk);
        $this->assertSame(TaskState::PendingApproval, $high->state);
        $this->assertSame(TaskRisk::High, $high->risk);
        $this->assertSame(TaskState::PendingApproval, $outside->state);
        $this->assertSame($this->admin->id, $low->created_by);
        $this->assertTrue($low->expires_at->isFuture());
    }

    public function test_create_rejects_invalid_params_and_inactive_endpoint(): void
    {
        try {
            $this->tasks->create($this->admin, $this->endpoint, 'process.kill', ['pid' => '1234', 'name' => 'x']);
            $this->fail('pid como string deveria ser recusado');
        } catch (ValidationException) {
        }

        $this->endpoint->installations()->update(['status' => 'revoked']);
        $this->expectException(\DomainException::class);
        $this->tasks->create($this->admin, $this->endpoint->fresh(), 'process.list', []);
    }

    public function test_creator_cannot_approve_own_task(): void
    {
        $task = $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);

        $this->expectException(\DomainException::class);
        $this->tasks->approve($this->admin, $task);
    }

    public function test_approval_by_other_admin_makes_available(): void
    {
        $other = $this->userWithRole(RoleKey::Admin);
        $task = $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);

        $approved = $this->tasks->approve($other, $task);

        $this->assertSame(TaskState::Available, $approved->state);
        $this->assertSame($other->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        // Aprovar de novo: estado inválido.
        $this->expectException(\DomainException::class);
        $this->tasks->approve($other, $approved);
    }

    public function test_reject_cancels_pending_task(): void
    {
        $other = $this->userWithRole(RoleKey::Admin);
        $task = $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);

        $rejected = $this->tasks->reject($other, $task, 'sem janela de manutenção');

        $this->assertSame(TaskState::Cancelled, $rejected->state);
        $this->assertNull($this->tasks->claim($this->endpoint));
    }

    public function test_idempotency_key_returns_same_task(): void
    {
        $first = $this->tasks->create($this->admin, $this->endpoint, 'process.list', [], 'chave-1');
        $second = $this->tasks->create($this->admin, $this->endpoint, 'process.list', [], 'chave-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RemoteTask::count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'task.created')->count());

        // Mesma chave de outro usuário cria outra tarefa.
        $this->tasks->create($this->userWithRole(RoleKey::Admin), $this->endpoint, 'process.list', [], 'chave-1');
        $this->assertSame(2, RemoteTask::count());
    }

    public function test_claim_takes_oldest_and_second_claim_gets_nothing(): void
    {
        $this->travel(-2)->minutes();
        $oldest = $this->tasks->create($this->admin, $this->endpoint, 'process.list', []);
        $this->travelBack();
        $this->tasks->create($this->admin, $this->endpoint, 'service.list', []);

        $first = $this->tasks->claim($this->endpoint);
        $this->assertSame($oldest->id, $first['task']->id);
        $this->assertSame(TaskState::Running, $first['task']->state);
        $this->assertSame(1, $first['attempt']->number);
        // Lease = timeout (30 s) + 30 s, em horário do servidor.
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $first['attempt']->lease_expires_at->timestamp, 2);

        $second = $this->tasks->claim($this->endpoint);
        $this->assertNotSame($oldest->id, $second['task']->id);

        // Nada mais disponível: a próxima chamada não recebe nada (nem a mesma tarefa de novo).
        $this->assertNull($this->tasks->claim($this->endpoint));
        $this->assertSame(2, RemoteTaskAttempt::count());
    }

    public function test_claim_refuses_pending_approval_and_expired(): void
    {
        $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);
        $expired = $this->tasks->create($this->admin, $this->endpoint, 'process.list', []);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertNull($this->tasks->claim($this->endpoint));
        $this->assertFalse($this->tasks->availableFor($this->endpoint));

        // Alto risco "disponível" sem aprovação gravada (estado forjado) também é recusado.
        RemoteTask::factory()->forEndpoint($this->endpoint)->create(['type' => 'agent.restart', 'risk' => TaskRisk::High, 'created_by' => $this->admin->id]);
        $this->assertNull($this->tasks->claim($this->endpoint));
    }

    public function test_claim_refuses_revoked_installation(): void
    {
        $this->tasks->create($this->admin, $this->endpoint, 'process.list', []);
        $this->assertTrue($this->tasks->availableFor($this->endpoint));

        $this->endpoint->installations()->update(['status' => 'revoked']);
        $this->assertNull($this->tasks->claim($this->endpoint->fresh()));

        $this->endpoint->installations()->update(['status' => 'active']);
        $this->endpoint->forceFill(['status' => 'disabled'])->save();
        $this->assertNull($this->tasks->claim($this->endpoint->fresh()));
    }

    public function test_claim_cancels_task_when_creator_lost_permission(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $task = $this->tasks->create($operator, $this->endpoint, 'process.list', []);

        app(UserService::class)->removeAssignment($operator->roleAssignments()->first());

        $this->assertNull($this->tasks->claim($this->endpoint));
        $this->assertSame(TaskState::Cancelled, $task->fresh()->state);
    }

    public function test_late_or_foreign_attempt_result_conflicts(): void
    {
        ['task' => $task, 'attempt' => $old] = $this->running();

        // Lease vence; tipo idempotente volta para available e é pego de novo (attempt 2).
        $this->travel(61)->seconds();
        $this->assertSame(1, $this->tasks->expireDue());
        $retry = $this->tasks->claim($this->endpoint);
        $this->assertSame(2, $retry['attempt']->number);

        // Resultado tardio do attempt antigo: conflito, sem alterar nada.
        try {
            $this->tasks->complete($task->fresh(), $old->uuid, ['status' => 'completed', 'exit_code' => 0]);
            $this->fail('attempt antigo deveria gerar conflito');
        } catch (TaskConflictException) {
        }
        $this->assertSame(TaskState::Running, $task->fresh()->state);

        // Attempt de outra tarefa ou inexistente: conflito.
        $this->expectException(TaskConflictException::class);
        $this->tasks->progress($task->fresh(), (string) Str::uuid());
    }

    public function test_progress_renews_lease_and_late_progress_conflicts(): void
    {
        ['task' => $task, 'attempt' => $attempt] = $this->running();

        $this->travel(50)->seconds();
        $this->tasks->progress($task, $attempt->uuid);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $attempt->fresh()->lease_expires_at->timestamp, 2);

        $this->travel(61)->seconds();
        $this->expectException(TaskConflictException::class);
        $this->tasks->progress($task, $attempt->uuid);
    }

    public function test_duplicate_identical_result_is_idempotent(): void
    {
        ['task' => $task, 'attempt' => $attempt] = $this->running();
        $result = ['status' => 'completed', 'exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''];

        $done = $this->tasks->complete($task, $attempt->uuid, $result);
        $this->assertSame(TaskState::Completed, $done->state);
        $this->assertSame('completed', $done->result_summary['status']);
        $this->assertSame(0, $done->result_summary['exit_code']);

        $again = $this->tasks->complete($task->fresh(), $attempt->uuid, $result);
        $this->assertSame(TaskState::Completed, $again->state);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'task.completed')->count());

        // Mesmo attempt com resultado diferente: conflito.
        $this->expectException(TaskConflictException::class);
        $this->tasks->complete($task->fresh(), $attempt->uuid, ['status' => 'failed', 'exit_code' => 1]);
    }

    public function test_rejected_result_becomes_failed_with_error(): void
    {
        ['task' => $task, 'attempt' => $attempt] = $this->running();

        $done = $this->tasks->complete($task, $attempt->uuid, ['status' => 'rejected', 'error' => 'tipo desconhecido no Agent']);

        $this->assertSame(TaskState::Failed, $done->state);
        $this->assertSame('rejected', $attempt->fresh()->status);
        $this->assertSame('tipo desconhecido no Agent', $attempt->fresh()->error);

        $this->expectException(ValidationException::class);
        $this->running();
        $next = RemoteTask::query()->where('state', 'running')->first();
        $this->tasks->complete($next, $next->currentAttempt->uuid, ['status' => 'bogus']);
    }

    public function test_output_is_truncated_and_sanitized(): void
    {
        ['task' => $task, 'attempt' => $attempt] = $this->running();
        $stdout = "\x00<b>\xC3".str_repeat('á', 10 * 1024); // ~20 KiB, UTF-8 inválido e byte nulo
        $stderr = "linha 1\r\nlinha\t2\x07\x1B[31m";

        $this->tasks->complete($task, $attempt->uuid, ['status' => 'completed', 'exit_code' => 0, 'stdout' => $stdout, 'stderr' => $stderr]);
        $attempt->refresh();

        $this->assertLessThanOrEqual(16384, strlen($attempt->stdout));
        $this->assertTrue(mb_check_encoding($attempt->stdout, 'UTF-8'));
        $this->assertStringNotContainsString("\x00", $attempt->stdout);
        $this->assertStringStartsWith('<b>', $attempt->stdout); // HTML é preservado aqui e escapado na UI
        $this->assertTrue($attempt->truncated);
        $this->assertSame("linha 1\nlinha\t2[31m", $attempt->stderr);

        $log = DB::table('audit_logs')->where('action', 'task.completed')->first();
        $this->assertStringNotContainsString('<b>', $log->metadata); // auditoria guarda só o tamanho
    }

    public function test_agent_timestamps_are_clamped(): void
    {
        ['task' => $task, 'attempt' => $attempt] = $this->running();

        $this->tasks->complete($task, $attempt->uuid, [
            'status' => 'completed', 'exit_code' => 0,
            'started_at' => now()->subHours(25)->toIso8601String(),
            'finished_at' => now()->addMinutes(5)->toIso8601String(),
        ]);
        $attempt->refresh();

        $this->assertNull($attempt->started_at);
        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, $attempt->finished_at->timestamp, 2);

        ['task' => $task2, 'attempt' => $attempt2] = $this->running();
        $this->tasks->complete($task2, $attempt2->uuid, [
            'status' => 'completed', 'exit_code' => 0, 'started_at' => 'não é data', 'finished_at' => now()->addDays(2)->toIso8601String(),
        ]);
        $this->assertNull($attempt2->fresh()->started_at);
        $this->assertNull($attempt2->fresh()->finished_at);
    }

    public function test_expire_due_pending_running_idempotent_and_not(): void
    {
        $pending = $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);
        $available = $this->tasks->create($this->admin, $this->endpoint, 'system.info', []);
        RemoteTask::query()->whereKey([$pending->id, $available->id])->update(['expires_at' => now()->subSecond()]);
        $this->assertSame(2, $this->tasks->expireDue());
        $this->assertSame(TaskState::Expired, $pending->fresh()->state);
        $this->assertSame(TaskState::Expired, $available->fresh()->state);

        // Idempotente (3 tentativas): volta para available até a 3ª; depois falha.
        ['task' => $idem] = $this->running('process.list');
        for ($i = 1; $i <= 3; $i++) {
            $this->travel(61)->seconds();
            $this->assertSame(1, $this->tasks->expireDue());
            if ($i < 3) {
                $this->assertSame(TaskState::Available, $idem->fresh()->state);
                $this->assertSame($i + 1, $this->tasks->claim($this->endpoint)['attempt']->number);
            }
        }
        $this->assertSame(TaskState::Failed, $idem->fresh()->state);
        $this->assertSame('lease_expired', $idem->fresh()->currentAttempt->error);

        // Não idempotente (service.restart, 1 tentativa): falha direto.
        ['task' => $restart] = $this->running('service.restart', ['name' => 'Spooler']);
        $this->travel(91)->seconds();
        $this->assertSame(1, $this->tasks->expireDue());
        $this->assertSame(TaskState::Failed, $restart->fresh()->state);
        $this->assertSame('lease_expired', $restart->fresh()->currentAttempt->error);

        $this->assertSame(0, $this->tasks->expireDue());
    }

    public function test_cancel_running_sets_request_only(): void
    {
        ['task' => $task, 'attempt' => $attempt] = $this->running();
        $available = $this->tasks->create($this->admin, $this->endpoint, 'system.info', []);

        $cancelled = $this->tasks->cancel($this->admin, $available);
        $this->assertSame(TaskState::Cancelled, $cancelled->state);

        $requested = $this->tasks->cancel($this->admin, $task);
        $this->assertSame(TaskState::Running, $requested->state);
        $this->assertNotNull($requested->cancel_requested_at);
        $this->assertSame([$task->uuid], $this->tasks->cancelRequestedIds($this->endpoint));

        $done = $this->tasks->complete($requested, $attempt->uuid, ['status' => 'cancelled']);
        $this->assertSame(TaskState::Cancelled, $done->state);
        $this->assertSame([], $this->tasks->cancelRequestedIds($this->endpoint));

        $this->expectException(\DomainException::class);
        $this->tasks->cancel($this->admin, $done);
    }

    public function test_cancel_open_for_endpoint(): void
    {
        $other = $this->activeEndpoint();
        $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);
        $this->tasks->create($this->admin, $this->endpoint, 'system.info', []);
        ['task' => $running] = $this->running();
        $done = $this->running();
        $this->tasks->complete($done['task'], $done['attempt']->uuid, ['status' => 'completed', 'exit_code' => 0]);
        $foreign = $this->tasks->create($this->admin, $other, 'system.info', []);

        $this->assertSame(3, $this->tasks->cancelOpenFor($this->endpoint, 'agent_revoked'));

        $this->assertSame(0, RemoteTask::query()->where('endpoint_id', $this->endpoint->id)->whereIn('state', TaskState::openValues())->count());
        $this->assertSame('cancelled', $running->fresh()->currentAttempt->status);
        $this->assertSame(TaskState::Completed, $done['task']->fresh()->state);
        $this->assertSame(TaskState::Available, $foreign->fresh()->state);
    }

    public function test_every_transition_is_audited(): void
    {
        $other = $this->userWithRole(RoleKey::Admin);
        $high = $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);
        $this->tasks->approve($other, $high);
        $rejected = $this->tasks->create($this->admin, $this->endpoint, 'agent.restart', []);
        $this->tasks->reject($other, $rejected, 'não');
        $claim = $this->tasks->claim($this->endpoint);
        $this->tasks->complete($claim['task'], $claim['attempt']->uuid, ['status' => 'failed', 'exit_code' => 1, 'stdout' => 'x']);
        $cancel = $this->tasks->create($this->admin, $this->endpoint, 'system.info', []);
        $this->tasks->cancel($this->admin, $cancel);
        $expire = $this->tasks->create($this->admin, $this->endpoint, 'system.info', []);
        $expire->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->tasks->expireDue();
        $ok = $this->running();
        $this->tasks->complete($ok['task'], $ok['attempt']->uuid, ['status' => 'completed', 'exit_code' => 0]);

        $actions = DB::table('audit_logs')->where('action', 'like', 'task.%')->pluck('action')->unique()->values()->all();
        foreach (['task.created', 'task.approved', 'task.rejected', 'task.claimed', 'task.failed', 'task.cancelled', 'task.expired', 'task.completed'] as $action) {
            $this->assertContains($action, $actions);
        }

        $claimed = DB::table('audit_logs')->where('action', 'task.claimed')->first();
        $this->assertSame('agent', $claimed->actor_type);
        $this->assertSame($this->endpoint->id, (int) $claimed->endpoint_id);
        $failed = json_decode(DB::table('audit_logs')->where('action', 'task.failed')->value('metadata'), true);
        $this->assertSame('agent.restart', $failed['type']);
        $this->assertSame('high', $failed['risk']);
        $this->assertSame(1, $failed['stdout_bytes']);

        $this->artisan('audit:verify')->assertSuccessful();
    }
}
