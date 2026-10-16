<?php

namespace App\Services\Tasks;

use App\Enums\EndpointStatus;
use App\Enums\Permission;
use App\Enums\TaskRisk;
use App\Enums\TaskState;
use App\Exceptions\TaskConflictException;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\RemoteTask;
use App\Models\RemoteTaskAttempt;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Authorization\AccessResolver;
use App\Support\Tasks\TaskCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Máquina de estados das tarefas remotas (docs/superpowers/specs/2026-10-15-tarefas-remotas-design.md).
 *
 * Toda mudança de estado passa por aqui e é auditada. Transições usam UPDATE condicional
 * (`WHERE id = ? AND state IN (...)`): só quem afetou a linha prossegue — é isso que impede dois
 * consumidores de pegarem a mesma tarefa (SELECT … FOR UPDATE seria ignorado pelo SQLite).
 * Lease, expiração e ordenação usam só o relógio do servidor; horários do Agent são informativos.
 */
class TaskService
{
    /** Limite de stdout/stderr por attempt (bytes, cortado em fronteira UTF-8). */
    public const OUTPUT_LIMIT = 16384;

    /** Folga somada ao timeout do tipo para formar a lease. */
    private const LEASE_GRACE_SECONDS = 30;

    /** Horários do Agent fora desta janela em torno do servidor são descartados. */
    private const AGENT_CLOCK_WINDOW_SECONDS = 86400;

    private const RESULT_STATUSES = ['completed', 'failed', 'rejected', 'cancelled'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessResolver $access,
    ) {}

    /**
     * @param  array<mixed>  $params
     *
     * @throws ValidationException tipo/parâmetros fora do catálogo
     * @throws AuthorizationException falta alguma permissão no endpoint
     * @throws \DomainException endpoint inativo ou sem instalação ativa
     */
    public function create(User $by, Endpoint $endpoint, string $type, array $params, ?string $idempotencyKey = null): RemoteTask
    {
        // validate() primeiro: risk()/permissionsFor() não recusam tipos desconhecidos.
        $params = TaskCatalog::validate($type, $params);
        $this->authorize($by, $endpoint, TaskCatalog::permissionsFor($type, $params));

        if ($idempotencyKey !== null) {
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
                throw ValidationException::withMessages(['idempotency_key' => 'Chave de idempotência inválida.']);
            }
            if ($existing = $this->findByIdempotencyKey($by, $idempotencyKey)) {
                return $this->sameRequestOrFail($existing, $endpoint, $type, $params);
            }
        }

        if (! $this->endpointIsActive($endpoint)) {
            throw new \DomainException('O computador não está ativo ou não tem agente ativo.');
        }

        $risk = TaskCatalog::risk($type, $params);

        try {
            return DB::transaction(function () use ($by, $endpoint, $type, $params, $idempotencyKey, $risk) {
                $task = new RemoteTask;
                $task->forceFill([
                    'endpoint_id' => $endpoint->id,
                    'type' => $type,
                    'params' => $params,
                    'risk' => $risk,
                    'state' => $risk->requiresApproval() ? TaskState::PendingApproval : TaskState::Available,
                    'created_by' => $by->id,
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => $this->pendingExpiry(),
                ])->save();

                $this->audit->log('task.created', $by, $task, $endpoint->id, $this->meta($task, [
                    'params' => $params, 'state' => $task->state->value,
                ]));

                return $task;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Corrida com a mesma chave: a outra requisição criou primeiro.
            $existing = $this->findByIdempotencyKey($by, (string) $idempotencyKey) ?? throw $e;

            return $this->sameRequestOrFail($existing, $endpoint, $type, $params);
        }
    }

    /**
     * Repetição com a mesma chave só devolve a tarefa existente se for o MESMO pedido (endpoint, tipo e
     * parâmetros normalizados); senão o usuário seria informado de uma tarefa que não pediu.
     *
     * @param  array<string, mixed>  $params  saída de TaskCatalog::validate()
     *
     * @throws ValidationException
     */
    private function sameRequestOrFail(RemoteTask $existing, Endpoint $endpoint, string $type, array $params): RemoteTask
    {
        $stored = (array) $existing->params;
        ksort($stored);
        ksort($params);

        if ((int) $existing->endpoint_id !== (int) $endpoint->id || $existing->type !== $type || $stored !== $params) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Esta chave de idempotência já foi usada para um pedido diferente. Recarregue a página e tente de novo.',
            ]);
        }

        return $existing;
    }

    /**
     * @throws AuthorizationException sem tasks.approve no endpoint
     * @throws \DomainException criador aprovando a própria tarefa ou estado inválido
     */
    public function approve(User $by, RemoteTask $t): RemoteTask
    {
        return DB::transaction(function () use ($by, $t) {
            $this->guardApprover($by, $t);

            if ($t->expires_at !== null && $t->expires_at->lte(now())) {
                throw new \DomainException('A tarefa expirou antes da aprovação.');
            }

            // O prazo para um Agent pegar a tarefa recomeça na aprovação.
            $this->move($t, [TaskState::PendingApproval], TaskState::Available, [
                'approved_by' => $by->id, 'approved_at' => now(), 'expires_at' => $this->pendingExpiry(),
            ]);

            $this->audit->log('task.approved', $by, $t, $t->endpoint_id, $this->meta($t, ['creator' => $t->created_by]));

            return $t->refresh();
        });
    }

    /**
     * @throws AuthorizationException sem tasks.approve no endpoint
     * @throws \DomainException criador rejeitando a própria tarefa ou estado inválido
     */
    public function reject(User $by, RemoteTask $t, ?string $reason): RemoteTask
    {
        return DB::transaction(function () use ($by, $t, $reason) {
            $this->guardApprover($by, $t);
            $reason = $reason === null ? null : mb_substr(trim($reason), 0, 255);

            $this->move($t, [TaskState::PendingApproval], TaskState::Cancelled, [
                'result_summary' => json_encode(['status' => 'rejected', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
            ]);

            $this->audit->log('task.rejected', $by, $t, $t->endpoint_id, $this->meta($t, [
                'creator' => $t->created_by, 'reason' => $reason,
            ]));

            return $t->refresh();
        });
    }

    /**
     * Disponível/pendente → cancelada. Em execução → só registra o pedido (o Agent recebe via heartbeat).
     *
     * @throws AuthorizationException sem tasks.cancel no endpoint
     * @throws \DomainException tarefa já encerrada
     */
    public function cancel(User $by, RemoteTask $t): RemoteTask
    {
        return DB::transaction(function () use ($by, $t) {
            $this->authorize($by, $this->endpointOf($t), [Permission::TasksCancel]);
            $t->refresh();

            if ($t->state === TaskState::Running) {
                if ($t->cancel_requested_at === null) {
                    $affected = RemoteTask::query()->whereKey($t->id)->where('state', TaskState::Running->value)
                        ->whereNull('cancel_requested_at')->update(['cancel_requested_at' => now()]);
                    if ($affected === 1) {
                        $this->audit->log('task.cancel_requested', $by, $t, $t->endpoint_id, $this->meta($t));
                    }
                }

                return $t->refresh();
            }

            $this->move($t, [TaskState::Available, TaskState::PendingApproval], TaskState::Cancelled, [
                'result_summary' => json_encode(['status' => 'cancelled']),
            ]);
            $this->audit->log('task.cancelled', $by, $t, $t->endpoint_id, $this->meta($t, ['previous_state' => $t->state->value]));

            return $t->refresh();
        });
    }

    /**
     * Entrega ao Agent a tarefa disponível mais antiga. Reconfere endpoint ativo, instalação ativa, prazo,
     * aprovação de alto risco e se criador/aprovador ainda têm as permissões (senão cancela a tarefa).
     *
     * @return array{task: RemoteTask, attempt: RemoteTaskAttempt}|null
     */
    public function claim(Endpoint $endpoint): ?array
    {
        $endpoint->refresh();
        $installation = $endpoint->activeInstallation()->first();
        if ($installation === null || ! $this->endpointIsActive($endpoint)) {
            return null;
        }

        // Limite de voltas: cada volta ou entrega, ou descarta uma candidata (perdida na corrida ou cancelada).
        for ($round = 0; $round < 10; $round++) {
            $candidate = $this->claimable($endpoint)->orderBy('created_at')->orderBy('id')->first();
            if ($candidate === null) {
                return null;
            }

            if (! $this->stillAuthorized($candidate, $endpoint)) {
                $this->cancelBySystem($candidate, 'authorization_revoked');

                continue;
            }

            $claimed = DB::transaction(function () use ($candidate, $installation) {
                $affected = RemoteTask::query()->whereKey($candidate->id)
                    ->where('state', TaskState::Available->value)
                    ->where('expires_at', '>', now())
                    ->update(['state' => TaskState::Running->value]);
                if ($affected !== 1) {
                    return null; // outro consumidor levou
                }

                $definition = TaskCatalog::definition($candidate->type);
                $attempt = new RemoteTaskAttempt;
                $attempt->forceFill([
                    'remote_task_id' => $candidate->id,
                    'number' => (int) RemoteTaskAttempt::query()->where('remote_task_id', $candidate->id)->max('number') + 1,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds($definition->timeoutSeconds + self::LEASE_GRACE_SECONDS),
                ])->save();

                $task = $candidate->refresh();
                $this->audit->log('task.claimed', $installation, $task, $task->endpoint_id, $this->meta($task, [
                    'attempt' => $attempt->uuid, 'number' => $attempt->number,
                ]));

                return ['task' => $task, 'attempt' => $attempt];
            });

            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    /**
     * Renova a lease do attempt vigente.
     *
     * @throws TaskConflictException attempt não vigente
     */
    public function progress(RemoteTask $t, string $attemptUuid): void
    {
        DB::transaction(function () use ($t, $attemptUuid) {
            $attempt = $this->currentAttemptOrFail($t, $attemptUuid);

            $affected = RemoteTaskAttempt::query()->whereKey($attempt->id)->whereNull('status')
                ->where('lease_expires_at', '>', now())
                ->update(['lease_expires_at' => now()->addSeconds($this->timeoutOf($t) + self::LEASE_GRACE_SECONDS)]);
            if ($affected !== 1) {
                throw TaskConflictException::staleAttempt();
            }
        });
    }

    /**
     * Registra o resultado do attempt vigente. Reenvio idêntico de um attempt já encerrado devolve o estado atual.
     *
     * @param  array<string, mixed>  $result  status, exit_code, stdout, stderr, truncated, error, started_at, finished_at
     *
     * @throws ValidationException resultado malformado
     * @throws TaskConflictException attempt não vigente ou resultado divergente
     */
    public function complete(RemoteTask $t, string $attemptUuid, array $result): RemoteTask
    {
        $clean = $this->normalizeResult($result);

        return DB::transaction(function () use ($t, $attemptUuid, $clean) {
            $attempt = RemoteTaskAttempt::query()->where('remote_task_id', $t->id)->where('uuid', $attemptUuid)->first();
            if ($attempt === null) {
                throw TaskConflictException::staleAttempt();
            }

            if ($attempt->status !== null) {
                if ($this->sameResult($attempt, $clean)) {
                    return $t->refresh();
                }
                throw TaskConflictException::staleAttempt();
            }

            $attempt = $this->currentAttemptOrFail($t, $attemptUuid);

            $affected = RemoteTaskAttempt::query()->whereKey($attempt->id)->whereNull('status')->update([
                'status' => $clean['status'],
                'exit_code' => $clean['exit_code'],
                'stdout' => $clean['stdout'],
                'stderr' => $clean['stderr'],
                'truncated' => $clean['truncated'],
                'error' => $clean['error'],
                'started_at' => $clean['started_at'],
                'finished_at' => $clean['finished_at'],
            ]);
            if ($affected !== 1) {
                throw TaskConflictException::staleAttempt();
            }

            $target = match ($clean['status']) {
                'completed' => TaskState::Completed,
                'cancelled' => TaskState::Cancelled,
                default => TaskState::Failed, // failed e rejected (Agent recusou sem executar)
            };

            $summary = [
                'status' => $clean['status'],
                'exit_code' => $clean['exit_code'],
                'duration_seconds' => max(0, now()->getTimestamp() - $attempt->claimed_at->getTimestamp()),
                'error' => $clean['error'],
            ];

            try {
                $this->move($t, [TaskState::Running], $target, ['result_summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);
            } catch (\DomainException) {
                throw TaskConflictException::staleAttempt();
            }

            $action = match ($target) {
                TaskState::Completed => 'task.completed',
                TaskState::Cancelled => 'task.cancelled',
                default => 'task.failed',
            };
            // Nunca a saída inteira na auditoria: só o tamanho.
            $this->audit->log($action, $this->agentOf($t), $t, $t->endpoint_id, $this->meta($t, [
                'attempt' => $attempt->uuid, 'number' => $attempt->number, 'status' => $clean['status'],
                'exit_code' => $clean['exit_code'], 'error' => $clean['error'],
                'stdout_bytes' => strlen($clean['stdout'] ?? ''), 'stderr_bytes' => strlen($clean['stderr'] ?? ''),
                'truncated' => $clean['truncated'],
            ]));

            return $t->refresh();
        });
    }

    /**
     * Varredura periódica: prazo de tarefas não pegas e leases vencidas.
     *
     * @return int quantidade de tarefas tratadas
     */
    public function expireDue(): int
    {
        $count = 0;

        $leases = RemoteTaskAttempt::query()->whereNull('status')->where('lease_expires_at', '<=', now())
            ->whereHas('task', fn ($q) => $q->where('state', TaskState::Running->value))
            ->with('task')->get();
        foreach ($leases as $attempt) {
            try {
                DB::transaction(fn () => $this->expireLease($attempt));
                $count++;
            } catch (TaskConflictException) {
                // a lease foi renovada ou o resultado chegou entre a consulta e a atualização
            }
        }

        $due = RemoteTask::query()
            ->whereIn('state', [TaskState::PendingApproval->value, TaskState::Available->value])
            ->where('expires_at', '<=', now())->get();
        foreach ($due as $task) {
            try {
                DB::transaction(function () use ($task) {
                    $previous = $task->state;
                    $this->move($task, [TaskState::PendingApproval, TaskState::Available], TaskState::Expired, [
                        'result_summary' => json_encode(['status' => 'expired']),
                    ]);
                    $this->audit->log('task.expired', null, $task, $task->endpoint_id, $this->meta($task, ['previous_state' => $previous->value]));
                });
                $count++;
            } catch (\DomainException) {
                // pega/cancelada entre a consulta e a atualização
            }
        }

        return $count;
    }

    /** Cancela todas as tarefas abertas do endpoint (revogação/desativação). */
    public function cancelOpenFor(Endpoint $e, string $reason): int
    {
        $count = 0;
        $reason = mb_substr($reason, 0, 255);

        foreach (RemoteTask::query()->where('endpoint_id', $e->id)->whereIn('state', TaskState::openValues())->get() as $task) {
            $count += $this->cancelBySystem($task, $reason) ? 1 : 0;
        }

        return $count;
    }

    /** Há tarefa pronta para este endpoint? (flag tasks_available do heartbeat) */
    public function availableFor(Endpoint $e): bool
    {
        return $this->claimable($e)->exists();
    }

    /** @return list<string> uuids das tarefas em execução com cancelamento pedido */
    public function cancelRequestedIds(Endpoint $e): array
    {
        return RemoteTask::query()->where('endpoint_id', $e->id)->where('state', TaskState::Running->value)
            ->whereNotNull('cancel_requested_at')->orderBy('id')->pluck('uuid')->values()->all();
    }

    // ---------------------------------------------------------------------------------------------

    /** @return Builder<RemoteTask> */
    private function claimable(Endpoint $endpoint): Builder
    {
        return RemoteTask::query()
            ->where('endpoint_id', $endpoint->id)
            ->where('state', TaskState::Available->value)
            ->where('expires_at', '>', now())
            ->whereIn('type', TaskCatalog::types())
            ->where(fn ($q) => $q->where('risk', '!=', TaskRisk::High->value)->orWhereNotNull('approved_by'));
    }

    /** Criador (e aprovador, se alto risco) ainda têm as permissões no endpoint? Usa o risco gravado. */
    private function stillAuthorized(RemoteTask $task, Endpoint $endpoint): bool
    {
        $creator = User::withTrashed()->find($task->created_by);
        if ($creator === null) {
            return false;
        }

        $definition = TaskCatalog::definition($task->type);
        $base = $task->risk === TaskRisk::High ? Permission::TasksCreateHighRisk : Permission::TasksCreate;
        foreach ([$base, ...($definition?->createPermissions ?? [])] as $permission) {
            if (! $this->access->can($creator, $permission, $endpoint)) {
                return false;
            }
        }

        if ($task->risk->requiresApproval()) {
            $approver = $task->approved_by === null ? null : User::withTrashed()->find($task->approved_by);
            if ($approver === null || (int) $approver->id === (int) $creator->id
                || ! $this->access->can($approver, Permission::TasksApprove, $endpoint)) {
                return false;
            }
        }

        return true;
    }

    /** Fecha a lease vencida: cancela (se pedido), recoloca na fila (idempotente com tentativas) ou falha. */
    private function expireLease(RemoteTaskAttempt $attempt): void
    {
        $affected = RemoteTaskAttempt::query()->whereKey($attempt->id)->whereNull('status')
            ->where('lease_expires_at', '<=', now())
            ->update(['status' => 'expired', 'error' => 'lease_expired']);
        if ($affected !== 1) {
            throw TaskConflictException::staleAttempt();
        }

        // Relê dentro da transação: um cancelamento pedido depois da consulta da varredura deve cancelar, não recolocar na fila.
        $task = $attempt->task->refresh();
        $definition = TaskCatalog::definition($task->type);
        $meta = ['attempt' => $attempt->uuid, 'number' => $attempt->number, 'error' => 'lease_expired'];

        try {
            if ($task->cancel_requested_at !== null) {
                $this->move($task, [TaskState::Running], TaskState::Cancelled, [
                    'result_summary' => json_encode(['status' => 'cancelled', 'error' => 'lease_expired']),
                ]);
                $this->audit->log('task.cancelled', null, $task, $task->endpoint_id, $this->meta($task, $meta));
            } elseif ($definition !== null && $definition->idempotent && $attempt->number < $definition->maxAttempts) {
                // Nova tentativa: novo prazo para ser pega.
                $this->move($task, [TaskState::Running], TaskState::Available, ['expires_at' => $this->pendingExpiry()]);
                $this->audit->log('task.requeued', null, $task, $task->endpoint_id, $this->meta($task, $meta));
            } else {
                $this->move($task, [TaskState::Running], TaskState::Failed, [
                    'result_summary' => json_encode(['status' => 'failed', 'error' => 'lease_expired']),
                ]);
                $this->audit->log('task.failed', null, $task, $task->endpoint_id, $this->meta($task, $meta));
            }
        } catch (\DomainException) {
            throw TaskConflictException::staleAttempt(); // desfaz a atualização do attempt
        }
    }

    /** Cancelamento pelo sistema (revogação, permissão perdida). Fecha o attempt aberto, se houver. */
    private function cancelBySystem(RemoteTask $task, string $reason): bool
    {
        try {
            DB::transaction(function () use ($task, $reason) {
                $task->refresh();
                $previous = $task->state;

                RemoteTaskAttempt::query()->where('remote_task_id', $task->id)->whereNull('status')
                    ->update(['status' => 'cancelled', 'error' => $reason]);

                $this->move($task, [TaskState::PendingApproval, TaskState::Available, TaskState::Running], TaskState::Cancelled, [
                    'result_summary' => json_encode(['status' => 'cancelled', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
                ]);
                $this->audit->log('task.cancelled', null, $task, $task->endpoint_id, $this->meta($task, [
                    'previous_state' => $previous->value, 'reason' => $reason,
                ]));
            });

            return true;
        } catch (\DomainException) {
            return false; // já encerrada
        }
    }

    /**
     * Transição atômica: só aplica se o estado atual ainda for um dos esperados.
     *
     * @param  list<TaskState>  $from
     * @param  array<string, mixed>  $extra
     *
     * @throws \DomainException estado atual não permite a transição
     */
    private function move(RemoteTask $task, array $from, TaskState $to, array $extra = []): void
    {
        foreach ($from as $state) {
            if (! $state->canTransitionTo($to)) {
                throw new \LogicException("Transição não prevista: {$state->value} → {$to->value}.");
            }
        }

        $affected = RemoteTask::query()->whereKey($task->id)
            ->whereIn('state', array_map(fn (TaskState $s) => $s->value, $from))
            ->update(['state' => $to->value] + $extra);

        if ($affected !== 1) {
            $current = RemoteTask::query()->whereKey($task->id)->toBase()->value('state');
            throw new \DomainException("Tarefa no estado '{$current}' não pode passar para '{$to->value}'.");
        }
    }

    /** @throws TaskConflictException */
    private function currentAttemptOrFail(RemoteTask $t, string $attemptUuid): RemoteTaskAttempt
    {
        $t->refresh();
        $current = RemoteTaskAttempt::query()->where('remote_task_id', $t->id)->orderByDesc('number')->first();

        if ($t->state !== TaskState::Running || $current === null || $current->uuid !== $attemptUuid
            || $current->status !== null || $current->lease_expires_at->lte(now())) {
            throw TaskConflictException::staleAttempt();
        }

        return $current;
    }

    /**
     * @param  list<Permission>  $permissions
     *
     * @throws AuthorizationException
     */
    private function authorize(User $user, ?Endpoint $endpoint, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($endpoint === null || ! $this->access->can($user, $permission, $endpoint)) {
                throw new AuthorizationException('Você não tem permissão para esta tarefa neste computador.');
            }
        }
    }

    /** Guardas comuns de aprovar/rejeitar: permissão no endpoint, separação de funções, estado. */
    private function guardApprover(User $by, RemoteTask $t): void
    {
        $this->authorize($by, $this->endpointOf($t), [Permission::TasksApprove]);

        if ((int) $by->id === (int) $t->created_by) {
            throw new \DomainException('Quem criou a tarefa não pode aprová-la nem rejeitá-la.');
        }

        $t->refresh();
        if ($t->state !== TaskState::PendingApproval) {
            throw new \DomainException('A tarefa não está aguardando aprovação.');
        }
    }

    private function endpointOf(RemoteTask $t): ?Endpoint
    {
        return Endpoint::query()->find($t->endpoint_id);
    }

    private function endpointIsActive(Endpoint $endpoint): bool
    {
        return $endpoint->status === EndpointStatus::Active
            && ! $endpoint->trashed()
            && $endpoint->activeInstallation()->exists();
    }

    private function agentOf(RemoteTask $t): ?AgentInstallation
    {
        return AgentInstallation::query()->where('endpoint_id', $t->endpoint_id)->where('status', 'active')->first();
    }

    private function findByIdempotencyKey(User $by, string $key): ?RemoteTask
    {
        return RemoteTask::query()->where('created_by', $by->id)->where('idempotency_key', $key)->first();
    }

    private function pendingExpiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) config('toolit.tasks.pending_ttl_minutes', 30));
    }

    private function timeoutOf(RemoteTask $t): int
    {
        return TaskCatalog::definition($t->type)?->timeoutSeconds ?? 30;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function meta(RemoteTask $t, array $extra = []): array
    {
        return ['task' => $t->uuid, 'type' => $t->type, 'risk' => $t->risk->value] + $extra;
    }

    /**
     * Valida e sanitiza o resultado enviado pelo Agent.
     *
     * @param  array<string, mixed>  $result
     * @return array{status: string, exit_code: ?int, stdout: ?string, stderr: ?string, truncated: bool, error: ?string, started_at: ?CarbonImmutable, finished_at: ?CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function normalizeResult(array $result): array
    {
        $status = $result['status'] ?? null;
        if (! is_string($status) || ! in_array($status, self::RESULT_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status de resultado inválido.']);
        }
        $exitCode = $result['exit_code'] ?? null;
        if ($exitCode !== null && ! is_int($exitCode)) {
            throw ValidationException::withMessages(['exit_code' => 'exit_code deve ser inteiro.']);
        }
        foreach (['stdout', 'stderr', 'error'] as $key) {
            if (isset($result[$key]) && ! is_string($result[$key])) {
                throw ValidationException::withMessages([$key => "{$key} deve ser texto."]);
            }
        }

        [$stdout, $cutOut] = self::sanitizeOutput($result['stdout'] ?? null, self::OUTPUT_LIMIT);
        [$stderr, $cutErr] = self::sanitizeOutput($result['stderr'] ?? null, self::OUTPUT_LIMIT);
        [$error] = self::sanitizeOutput($result['error'] ?? null, 1024);
        $error = $error === null || $error === '' ? null : mb_substr($error, 0, 255);
        if ($status === 'rejected') {
            $error ??= 'rejected';
        }

        return [
            'status' => $status,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'truncated' => $cutOut || $cutErr || ($result['truncated'] ?? false) === true,
            'error' => $error,
            'started_at' => self::agentTime($result['started_at'] ?? null),
            'finished_at' => self::agentTime($result['finished_at'] ?? null),
        ];
    }

    /** Reenvio do mesmo resultado? (horários do Agent ficam de fora: são informativos) */
    private function sameResult(RemoteTaskAttempt $attempt, array $clean): bool
    {
        return $attempt->status === $clean['status']
            && $attempt->exit_code === $clean['exit_code']
            && $attempt->stdout === $clean['stdout']
            && $attempt->stderr === $clean['stderr']
            && $attempt->error === $clean['error'];
    }

    /**
     * UTF-8 válido (sequências inválidas viram U+FFFD), sem caracteres de controle exceto \n e \t,
     * cortado em $maxBytes numa fronteira de caractere.
     *
     * @return array{0: ?string, 1: bool} [texto, truncado]
     */
    public static function sanitizeOutput(?string $text, int $maxBytes): array
    {
        if ($text === null) {
            return [null, false];
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $text = mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }

        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);

        if (strlen($text) <= $maxBytes) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, $maxBytes, 'UTF-8'), true];
    }

    /** Horário informado pelo Agent: aceito só dentro de ±24 h do servidor; senão descartado. */
    private static function agentTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $time = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        if (abs($time->getTimestamp() - now()->getTimestamp()) > self::AGENT_CLOCK_WINDOW_SECONDS) {
            return null;
        }

        return $time->setTimezone(config('app.timezone'));
    }
}
