<?php

namespace App\Support\Tasks;

use App\Enums\Permission;
use App\Enums\TaskRisk;
use App\Models\SystemSetting;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo fechado de tarefas remotas: tipos, schema dos parâmetros, risco, permissões, timeout e tentativas.
 * Nada fora daqui pode ser criado. O Agent mantém um catálogo local equivalente e recusa o que não conhecer.
 */
final class TaskCatalog
{
    private const SERVICE_NAME_PATTERN = '/^[A-Za-z0-9_.$-]{1,128}\z/'; // \z (e não $): "$" aceitaria um "\n" final

    private const SERVICE_TYPES = ['service.start', 'service.stop', 'service.restart'];

    /** @var array<string, TaskDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, TaskDefinition> */
    private static function all(): array
    {
        return self::$definitions ??= collect([
            new TaskDefinition('system.info', true, 30, 3),
            new TaskDefinition('process.list', true, 30, 3, [Permission::ProcessesView]),
            new TaskDefinition('service.list', true, 30, 3, [Permission::ServicesView]),
            new TaskDefinition('inventory.refresh', true, 120, 3),
            new TaskDefinition('service.start', true, 60, 3, [Permission::ServicesControl]),
            new TaskDefinition('service.stop', true, 60, 3, [Permission::ServicesControl]),
            new TaskDefinition('service.restart', false, 60, 1, [Permission::ServicesControl]),
            new TaskDefinition('process.kill', false, 15, 1, [Permission::ProcessesKill]),
            new TaskDefinition('agent.restart', false, 30, 1),
        ])->keyBy('type')->all();
    }

    public static function definition(string $type): ?TaskDefinition
    {
        return self::all()[$type] ?? null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    /**
     * Valida e normaliza os parâmetros. Schema estrito: tipos exatos (sem coerção) e nenhuma chave extra.
     *
     * @param  array<mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(string $type, array $params): array
    {
        if (self::definition($type) === null) {
            throw ValidationException::withMessages(['type' => 'Tipo de tarefa desconhecido.']);
        }

        $allowedKeys = match (true) {
            in_array($type, self::SERVICE_TYPES, true) => ['name'],
            $type === 'process.kill' => ['pid', 'name'],
            default => [],
        };

        if (array_diff(array_map('strval', array_keys($params)), $allowedKeys) !== []) {
            throw ValidationException::withMessages(['params' => 'Parâmetros não permitidos para este tipo de tarefa.']);
        }

        if (in_array($type, self::SERVICE_TYPES, true)) {
            $name = self::requireString($params, 'name');
            if (! preg_match(self::SERVICE_NAME_PATTERN, $name)) {
                throw ValidationException::withMessages(['params.name' => 'Nome de serviço inválido.']);
            }
            if ($type !== 'service.start' && self::inList($name, self::serviceProtected())) {
                throw ValidationException::withMessages(['params.name' => 'Este serviço é protegido e não pode ser parado ou reiniciado.']);
            }

            return ['name' => $name];
        }

        if ($type === 'process.kill') {
            $pid = $params['pid'] ?? null;
            // PIDs 0-4 são do sistema (Idle/System). is_int rejeita string, float e "4 ".
            if (! is_int($pid) || $pid < 5) {
                throw ValidationException::withMessages(['params.pid' => 'PID inválido.']);
            }
            $name = self::requireString($params, 'name');
            $length = mb_strlen($name);
            if ($length < 1 || $length > 128 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                throw ValidationException::withMessages(['params.name' => 'Nome do processo inválido.']);
            }

            return ['pid' => $pid, 'name' => $name];
        }

        return [];
    }

    /** @param array<mixed> $params */
    public static function risk(string $type, array $params): TaskRisk
    {
        return match (true) {
            in_array($type, self::SERVICE_TYPES, true) => self::inList((string) ($params['name'] ?? ''), self::serviceAllowlist())
                ? TaskRisk::Medium
                : TaskRisk::High,
            in_array($type, ['process.kill', 'agent.restart'], true) => TaskRisk::High,
            default => TaskRisk::Low,
        };
    }

    /**
     * Permissões exigidas para CRIAR a tarefa (todas): a do nível de risco + as do recurso.
     *
     * @param  array<mixed>  $params
     * @return list<Permission>
     */
    public static function permissionsFor(string $type, array $params): array
    {
        $base = self::risk($type, $params) === TaskRisk::High ? Permission::TasksCreateHighRisk : Permission::TasksCreate;

        return [$base, ...(self::definition($type)?->createPermissions ?? [])];
    }

    /** @return list<string> */
    public static function serviceAllowlist(): array
    {
        return self::setting('tasks.service_allowlist', config('toolit.tasks.service_allowlist', []));
    }

    /** @return list<string> */
    public static function serviceProtected(): array
    {
        return self::setting('tasks.service_protected', config('toolit.tasks.service_protected', []));
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function setting(string $key, array $default): array
    {
        $value = SystemSetting::query()->where('key', $key)->first()?->value;

        return is_array($value) ? array_values(array_map('strval', $value)) : $default;
    }

    /** Nomes de serviço do Windows não diferenciam maiúsculas de minúsculas. */
    private static function inList(string $name, array $list): bool
    {
        return in_array(strtolower($name), array_map('strtolower', $list), true);
    }

    /** @param array<mixed> $params */
    private static function requireString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (! is_string($value)) {
            throw ValidationException::withMessages(["params.$key" => 'Parâmetro obrigatório (texto).']);
        }

        return $value;
    }
}
