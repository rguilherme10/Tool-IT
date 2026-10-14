<?php

namespace App\Services\Audit;

use App\Models\AgentInstallation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Trilha de auditoria append-only com hash encadeado (ADR-009).
 *
 * Cada registro inclui o hash do anterior; alterar/remover/reordenar qualquer registro quebra a cadeia
 * (detectado por `php artisan audit:verify`). A gravação ocorre em transação com lock na linha única de
 * `audit_chain`; se a auditoria falhar, a transação da ação auditada também falha (quando aninhada).
 */
class AuditLogger
{
    private const REDACT = '/password|passwd|secret|token|signature|authorization|private|credential/i';

    /**
     * @param  User|AgentInstallation|'system'|null  $actor  null/'system' = processo do sistema
     * @param  array<string, mixed>  $metadata  nunca incluir segredos; chaves sensíveis são mascaradas
     */
    public function log(
        string $action,
        User|AgentInstallation|string|null $actor = null,
        ?Model $subject = null,
        ?int $endpointId = null,
        array $metadata = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        [$actorType, $actorId] = match (true) {
            $actor instanceof User => ['user', (string) $actor->getKey()],
            $actor instanceof AgentInstallation => ['agent', (string) $actor->uuid],
            default => ['system', null],
        };

        $ip ??= request()?->ip();
        $userAgent ??= request()?->userAgent();

        DB::transaction(function () use ($action, $actorType, $actorId, $subject, $endpointId, $metadata, $ip, $userAgent) {
            $chain = DB::table('audit_chain')->where('id', 1)->lockForUpdate()->first();

            $row = [
                'seq' => $chain->last_seq + 1,
                'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'subject_type' => $subject ? $subject->getMorphClass() : null,
                'subject_id' => $subject ? (string) $subject->getKey() : null,
                'endpoint_id' => $endpointId,
                'ip_address' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'metadata' => $metadata === [] ? null : $this->redact($metadata),
                'prev_hash' => $chain->last_hash,
            ];
            $row['hash'] = self::computeHash($row);
            $row['metadata'] = $row['metadata'] === null ? null : json_encode($row['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            DB::table('audit_logs')->insert($row);
            DB::table('audit_chain')->where('id', 1)->update(['last_seq' => $row['seq'], 'last_hash' => $row['hash']]);
        });
    }

    /**
     * Hash canônico do registro. Usado na gravação e na verificação — não alterar sem migrar a cadeia.
     *
     * @param  array<string, mixed>  $row  metadata como array|null (ou string JSON, que será decodificada)
     */
    public static function computeHash(array $row): string
    {
        $metadata = $row['metadata'] ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return hash('sha256', implode('|', [
            $row['prev_hash'],
            $row['seq'],
            $row['occurred_at'],
            $row['actor_type'] ?? '',
            $row['actor_id'] ?? '',
            $row['action'],
            $row['subject_type'] ?? '',
            $row['subject_id'] ?? '',
            $row['endpoint_id'] ?? '',
            self::canonicalJson($metadata),
        ]));
    }

    private static function canonicalJson(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $sort = function (mixed $v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_float($value)) {
                // Floats não sobrevivem de forma estável ao ciclo JSON/banco: normalizar para string.
                $data[$key] = (string) $value;
            }
        }

        return $data;
    }
}
