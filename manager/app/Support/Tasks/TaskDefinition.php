<?php

namespace App\Support\Tasks;

use App\Enums\Permission;

/**
 * Entrada do catálogo de tarefas.
 *
 * $createPermissions são as permissões de RECURSO exigidas na criação (ex.: processes.kill); a permissão de
 * criação por risco (tasks.create / tasks.create_high_risk) é acrescentada por TaskCatalog::permissionsFor().
 */
final readonly class TaskDefinition
{
    /** @param list<Permission> $createPermissions */
    public function __construct(
        public string $type,
        public bool $idempotent,
        public int $timeoutSeconds,
        public int $maxAttempts,
        public array $createPermissions = [],
    ) {}
}
