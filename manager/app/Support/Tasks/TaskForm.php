<?php

namespace App\Support\Tasks;

use App\Models\Endpoint;
use App\Models\User;
use App\Support\Authorization\AccessResolver;

/**
 * Apresentação do catálogo no painel: rótulos, campos do formulário e quais tipos o usuário pode criar.
 * Não decide nada por conta própria: validação e autorização continuam em TaskCatalog e TaskService.
 */
final class TaskForm
{
    /** @var array<string, array{label: string, help: string}> */
    private const TYPES = [
        'system.info' => ['label' => 'Informações do sistema', 'help' => 'Lê versão do sistema, tempo ligado e recursos. Não altera nada.'],
        'process.list' => ['label' => 'Listar processos', 'help' => 'Lista os processos em execução. Não altera nada.'],
        'service.list' => ['label' => 'Listar serviços', 'help' => 'Lista os serviços do Windows e seus estados. Não altera nada.'],
        'inventory.refresh' => ['label' => 'Atualizar inventário', 'help' => 'Pede ao agente uma nova coleta de inventário agora.'],
        'service.start' => ['label' => 'Iniciar serviço', 'help' => 'Serviços da lista permitida são executados direto; os demais exigem aprovação de outra pessoa.'],
        'service.stop' => ['label' => 'Parar serviço', 'help' => 'Serviços da lista permitida são executados direto; os demais exigem aprovação de outra pessoa.'],
        'service.restart' => ['label' => 'Reiniciar serviço', 'help' => 'Serviços da lista permitida são executados direto; os demais exigem aprovação de outra pessoa.'],
        'process.kill' => ['label' => 'Encerrar processo', 'help' => 'Alto risco: exige aprovação de outra pessoa. Processos críticos do sistema nunca são encerrados.'],
        'agent.restart' => ['label' => 'Reiniciar o agente', 'help' => 'Alto risco: exige aprovação de outra pessoa.'],
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    public static function help(string $type): string
    {
        return self::TYPES[$type]['help'] ?? '';
    }

    /** Campos do formulário do tipo (espelham o schema de TaskCatalog::validate). @return list<string> */
    public static function fields(string $type): array
    {
        return match (true) {
            in_array($type, ['service.start', 'service.stop', 'service.restart'], true) => ['name'],
            $type === 'process.kill' => ['pid', 'name'],
            default => [],
        };
    }

    /**
     * Monta os parâmetros a partir da entrada do formulário. Não coage nem filtra valores: o que não for do tipo
     * esperado segue como veio e é recusado por TaskCatalog::validate(). Só o PID digitado (texto) vira inteiro.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function params(string $type, array $input): array
    {
        $params = [];
        foreach (self::fields($type) as $field) {
            if (! array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $value = $input[$field];
            if ($field === 'pid' && is_string($value) && preg_match('/^\s*\d{1,9}\s*$/', $value)) {
                $value = (int) trim($value);
            }
            $params[$field] = $value;
        }

        return $params;
    }

    /**
     * Tipos que o usuário pode criar NESTE computador. Para serviços, vale se puder criar de alguma forma
     * (nome da lista permitida = médio risco, ou fora dela = alto risco); o nome digitado é reconferido na criação.
     *
     * @return list<string>
     */
    public static function creatableTypes(User $user, Endpoint $endpoint, AccessResolver $access): array
    {
        $allowed = [];
        foreach (TaskCatalog::types() as $type) {
            foreach (self::representativeParams($type) as $params) {
                $permitted = true;
                foreach (TaskCatalog::permissionsFor($type, $params) as $permission) {
                    if (! $access->can($user, $permission, $endpoint)) {
                        $permitted = false;
                        break;
                    }
                }
                if ($permitted) {
                    $allowed[] = $type;
                    break;
                }
            }
        }

        return $allowed;
    }

    /** @return list<array<string, mixed>> parâmetros que cobrem os níveis de risco possíveis do tipo */
    private static function representativeParams(string $type): array
    {
        if (in_array($type, ['service.start', 'service.stop', 'service.restart'], true)) {
            $listed = TaskCatalog::serviceAllowlist()[0] ?? null;

            return array_values(array_filter([
                $listed === null ? null : ['name' => $listed],
                ['name' => '__fora_da_lista_permitida__'],
            ]));
        }

        return [[]];
    }
}
