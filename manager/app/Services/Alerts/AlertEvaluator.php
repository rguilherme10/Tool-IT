<?php

namespace App\Services\Alerts;

use App\Enums\EndpointStatus;
use App\Models\AlertRule;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avalia todas as regras ativas (executado a cada minuto por `alerts:evaluate`). Uma regra com defeito
 * não impede as demais: o erro é registrado em log e contado.
 */
class AlertEvaluator
{
    public function __construct(
        private readonly MetricThresholdEvaluator $metrics,
        private readonly AgentStatusEvaluator $status,
        private readonly AlertService $alerts,
    ) {}

    /** @return array{rules: int, opened: int, resolved: int, repeated: int, orphans: int, failed: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $stats = ['rules' => 0, 'opened' => 0, 'resolved' => 0, 'repeated' => 0, 'orphans' => 0, 'failed' => 0];

        foreach (AlertRule::query()->enabled()->get() as $rule) {
            $stats['rules']++;
            try {
                $evaluations = $this->evaluate($rule, $now);
                foreach ($this->alerts->apply($rule, $evaluations, $now) as $key => $n) {
                    $stats[$key] += $n;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::error('alerts.rule_failed', ['rule' => $rule->uuid, 'type' => $rule->type->value, 'error' => $e->getMessage()]);
            }
        }

        $stats['orphans'] = $this->alerts->resolveOrphans($now);

        return $stats;
    }

    /** @return list<Evaluation> */
    private function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $ids = $this->scope($rule);

        return $rule->type->usesMetrics()
            ? $this->metrics->evaluate($rule, $ids, $now)
            : $this->status->evaluate($rule, $ids, $now);
    }

    /**
     * Computadores avaliados: ativos, com agente ativo (revogado/desativado não alerta) e dentro do escopo da regra.
     *
     * @return Collection<int, int>
     */
    private function scope(AlertRule $rule): Collection
    {
        return Endpoint::query()
            ->where('status', EndpointStatus::Active->value)
            ->whereHas('activeInstallation')
            ->when($rule->endpoint_group_id !== null, fn ($q) => $q->whereIn(
                'endpoint_group_id', EndpointGroup::expandWithDescendants([(int) $rule->endpoint_group_id]),
            ))
            ->pluck('id');
    }
}
