<?php

namespace App\Services\Alerts;

use App\Enums\AlertRuleType;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Máquina de estados dos alertas. Regras (docs/tasks/007):
 *  - VIOLATING sem alerta ativo → abre (no máximo um ativo por regra+computador+assunto: UNIQUE no banco);
 *  - VIOLATING com alerta ativo → só atualiza; repete (contador) a cada `repeat_interval` enquanto não reconhecido;
 *  - CLEAR (abaixo da histerese, sustentado) → resolve automaticamente;
 *  - NEUTRAL (dados insuficientes ou dentro da faixa de histerese) → não muda nada.
 * Abrir, reconhecer e resolver geram auditoria.
 */
class AlertService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<Evaluation>  $evaluations
     * @return array{opened: int, resolved: int, repeated: int}
     */
    public function apply(AlertRule $rule, array $evaluations, CarbonImmutable $now): array
    {
        $stats = ['opened' => 0, 'resolved' => 0, 'repeated' => 0];
        $active = Alert::query()->active()->where('alert_rule_id', $rule->id)->get()->keyBy('dedupe_hash');

        foreach ($evaluations as $evaluation) {
            $hash = Alert::dedupeHash($rule->id, $evaluation->endpointId, $evaluation->subject);
            $alert = $active->get($hash);

            if ($evaluation->state === Evaluation::VIOLATING) {
                if ($alert === null) {
                    $stats['opened'] += $this->open($rule, $evaluation, $hash, $now) ? 1 : 0;
                } else {
                    $stats['repeated'] += $this->refresh($rule, $alert, $evaluation, $now) ? 1 : 0;
                }
            } elseif ($evaluation->state === Evaluation::CLEAR && $alert !== null) {
                $this->resolve($alert, 'auto', $now);
                $stats['resolved']++;
            }
        }

        return $stats;
    }

    /** Reconhecer = "estou ciente": para as repetições, mas o alerta só fecha quando a condição some. */
    public function acknowledge(Alert $alert, User $user): bool
    {
        if ($alert->status !== AlertStatus::Open) {
            return false;
        }

        $alert->forceFill([
            'status' => AlertStatus::Acknowledged, 'acknowledged_at' => now(), 'acknowledged_by' => $user->id,
        ])->save();

        $this->audit->log('alert.acknowledged', $user, $alert, $alert->endpoint_id, [
            'rule' => $alert->rule_name, 'subject' => $alert->subject,
        ]);

        return true;
    }

    /** Fecha alertas cujo motivo deixou de existir (computador inativo/removido, regra desligada/apagada). */
    public function resolveOrphans(CarbonImmutable $now): int
    {
        $count = 0;

        $byRule = Alert::query()->active()
            ->where(fn ($q) => $q->whereNull('alert_rule_id')->orWhereHas('rule', fn ($r) => $r->where('enabled', false)))
            ->with('rule:id,enabled')->get();
        foreach ($byRule as $alert) {
            $this->resolve($alert, $alert->rule === null ? 'rule_removed' : 'rule_disabled', $now);
            $count++;
        }

        $inactive = Alert::query()->active()
            ->whereDoesntHave('endpoint', fn ($e) => $e->where('status', 'active')->whereHas('activeInstallation'))
            ->get();
        foreach ($inactive as $alert) {
            $this->resolve($alert, 'endpoint_inactive', $now);
            $count++;
        }

        return $count;
    }

    private function open(AlertRule $rule, Evaluation $evaluation, string $hash, CarbonImmutable $now): bool
    {
        $alert = new Alert;
        $alert->forceFill([
            'endpoint_id' => $evaluation->endpointId,
            'alert_rule_id' => $rule->id,
            'rule_type' => $rule->type,
            'rule_name' => $rule->name,
            'subject' => $evaluation->subject,
            'dedupe_hash' => $hash,
            'status' => AlertStatus::Open,
            'severity' => $rule->severity,
            'title' => $this->title($rule, $evaluation),
            'details' => $evaluation->details,
            'opened_at' => $now,
            'last_seen_at' => $now,
        ]);

        try {
            $alert->save();
        } catch (UniqueConstraintViolationException) {
            return false; // outro avaliador abriu primeiro: a UNIQUE garante um único alerta ativo
        }

        $this->audit->log('alert.opened', null, $alert, $alert->endpoint_id, [
            'rule' => $rule->name, 'type' => $rule->type->value, 'subject' => $alert->subject,
            'severity' => $rule->severity->value, 'value' => $evaluation->details['value'] ?? null,
        ]);

        return true;
    }

    /** @return bool true se registrou uma repetição */
    private function refresh(AlertRule $rule, Alert $alert, Evaluation $evaluation, CarbonImmutable $now): bool
    {
        $alert->last_seen_at = $now;
        $alert->details = $evaluation->details;
        $alert->title = $this->title($rule, $evaluation);

        $repeated = false;
        if ($alert->status === AlertStatus::Open && $rule->repeat_interval_seconds > 0) {
            $reference = $alert->last_repeated_at ?? $alert->opened_at;
            if ($now->timestamp - $reference->timestamp >= $rule->repeat_interval_seconds) {
                $alert->repeat_count++;
                $alert->last_repeated_at = $now;
                $repeated = true;
            }
        }

        $alert->save();

        return $repeated;
    }

    private function resolve(Alert $alert, string $reason, CarbonImmutable $now): void
    {
        $alert->forceFill(['status' => AlertStatus::Resolved, 'resolved_at' => $now, 'resolved_reason' => $reason])->save();

        $this->audit->log('alert.resolved', null, $alert, $alert->endpoint_id, [
            'rule' => $alert->rule_name, 'subject' => $alert->subject, 'reason' => $reason,
        ]);
    }

    /** Abaixo de 2 min mostra segundos ("77 s"); acima, minutos ("10 min"). */
    private function duration(int $seconds): string
    {
        return $seconds < 120 ? "{$seconds} s" : intdiv($seconds, 60).' min';
    }

    private function title(AlertRule $rule, Evaluation $evaluation): string
    {
        $fmt = fn (float $n) => number_format($n, 1, ',', '.');
        $d = $evaluation->details;

        return match ($rule->type) {
            AlertRuleType::DiskUsage => sprintf('Disco %s com %s %% de uso (limite %s %%)', $evaluation->subject, $fmt((float) $evaluation->value), $fmt($rule->threshold)),
            AlertRuleType::MemoryUsage => sprintf('Memória com %s %% em uso (limite %s %%)', $fmt((float) $evaluation->value), $fmt($rule->threshold)),
            AlertRuleType::CpuUsage => sprintf('CPU com %s %% de uso (limite %s %%)', $fmt((float) $evaluation->value), $fmt($rule->threshold)),
            AlertRuleType::AgentOffline => sprintf('Sem comunicação há %s (limite %s)', $this->duration((int) $evaluation->value), $this->duration((int) $rule->threshold)),
            AlertRuleType::AgentOutdated => sprintf('Agente %s desatualizado (mínimo %s)', $d['version'] ?? '?', $d['min_version'] ?? '?'),
        };
    }
}
