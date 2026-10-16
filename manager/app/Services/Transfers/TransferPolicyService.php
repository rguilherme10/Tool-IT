<?php

namespace App\Services\Transfers;

use App\Models\EndpointGroup;
use App\Models\FileTransferPolicy;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escrita das políticas de transferência (criar, editar, remover), sempre em transação e com auditoria.
 *
 * Recebe valores JÁ validados e normalizados (FileTransferPolicyRequest: PathGuard, extensões, limites); esta
 * classe não valida caminhos. É o único ponto que grava FileTransferPolicy (o modelo é guarded).
 */
class TransferPolicyService
{
    /** Valores iniciais sugeridos ao criar uma política (spec, seção "Política"). */
    public const DEFAULTS = [
        'send_dirs' => ['C:\\ProgramData\\Toolit\\Inbox'],
        'fetch_dirs' => ['C:\\ProgramData\\Toolit\\Outbox', 'C:\\ProgramData\\Toolit\\Logs'],
        'allowed_extensions' => ['txt', 'log', 'csv', 'json', 'xml', 'pdf', 'zip', '7z', 'png', 'jpg', 'docx', 'xlsx'],
        'max_bytes' => 104857600,
        'allow_overwrite' => false,
    ];

    /** Campos de valor da política (os que entram na auditoria e em "before"). */
    private const VALUE_FIELDS = ['send_dirs', 'fetch_dirs', 'allowed_extensions', 'max_bytes', 'allow_overwrite'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{send_dirs: list<string>, fetch_dirs: list<string>, allowed_extensions: list<string>, max_bytes: int, allow_overwrite: bool}  $attributes
     *
     * @throws ValidationException se já existe política para o escopo (inclusive em corrida, pelo índice único)
     */
    public function create(User $actor, ?EndpointGroup $group, array $attributes): FileTransferPolicy
    {
        try {
            return DB::transaction(function () use ($actor, $group, $attributes) {
                $policy = new FileTransferPolicy;
                $policy->forceFill($this->values($attributes) + ['endpoint_group_id' => $group?->id, 'updated_by' => $actor->id]);
                $policy->save();

                $this->audit->log('transfer_policy.created', $actor, $policy, metadata: $this->snapshot($policy, $group));

                return $policy;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['group' => 'Já existe uma política para este escopo. Edite a existente.']);
        }
    }

    /**
     * O escopo (grupo) nunca muda: para mudar, remova e crie outra.
     *
     * @param  array{send_dirs: list<string>, fetch_dirs: list<string>, allowed_extensions: list<string>, max_bytes: int, allow_overwrite: bool}  $attributes
     */
    public function update(User $actor, FileTransferPolicy $policy, array $attributes): FileTransferPolicy
    {
        return DB::transaction(function () use ($actor, $policy, $attributes) {
            $before = $policy->only(self::VALUE_FIELDS);

            $policy->forceFill($this->values($attributes) + ['updated_by' => $actor->id]);
            $policy->save();

            $this->audit->log('transfer_policy.updated', $actor, $policy, metadata: $this->snapshot($policy, $policy->group) + ['before' => $before]);

            return $policy;
        });
    }

    public function delete(User $actor, FileTransferPolicy $policy): void
    {
        // Ainda não há tabela de transferências: remover é sempre possível. A Task 3 faz o onTaskFinished/claim
        // tratar a transferência em andamento cuja política deixou de existir (a política efetiva é reavaliada
        // no claim; sem ela, a tarefa é recusada).
        DB::transaction(function () use ($actor, $policy) {
            $this->audit->log('transfer_policy.deleted', $actor, $policy, metadata: $this->snapshot($policy, $policy->group));
            $policy->delete();
        });
    }

    /** @return array<string, mixed> */
    private function values(array $attributes): array
    {
        return [
            'send_dirs' => array_values($attributes['send_dirs']),
            'fetch_dirs' => array_values($attributes['fetch_dirs']),
            'allowed_extensions' => array_values($attributes['allowed_extensions']),
            'max_bytes' => (int) $attributes['max_bytes'],
            'allow_overwrite' => (bool) $attributes['allow_overwrite'],
        ];
    }

    /**
     * Metadados de auditoria: escopo ("global" ou "group:<uuid>") e valores efetivos.
     *
     * @return array<string, mixed>
     */
    private function snapshot(FileTransferPolicy $policy, ?EndpointGroup $group): array
    {
        return ['scope' => $group === null ? 'global' : 'group:'.$group->uuid] + $policy->only(self::VALUE_FIELDS);
    }
}
