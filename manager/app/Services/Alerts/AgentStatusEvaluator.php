<?php

namespace App\Services\Alerts;

use App\Enums\AlertRuleType;
use App\Models\AlertRule;
use App\Models\Endpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Regras calculadas pelo servidor a partir do estado do endpoint: sem comunicação e versão desatualizada. */
class AgentStatusEvaluator
{
    /**
     * @param  Collection<int, int>  $endpointIds
     * @return list<Evaluation>
     */
    public function evaluate(AlertRule $rule, Collection $endpointIds, CarbonImmutable $now): array
    {
        if ($endpointIds->isEmpty()) {
            return [];
        }

        $endpoints = Endpoint::query()->whereIn('id', $endpointIds->all())->get(['id', 'last_seen_at', 'agent_version']);

        return $rule->type === AlertRuleType::AgentOffline
            ? $this->offline($rule, $endpoints, $now)
            : $this->outdated($rule, $endpoints);
    }

    /** @return list<Evaluation> */
    private function offline(AlertRule $rule, Collection $endpoints, CarbonImmutable $now): array
    {
        $limit = $rule->threshold;
        $clear = $rule->effectiveClearThreshold();
        $evaluations = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint->last_seen_at === null) {
                continue; // nunca se comunicou: ainda não há o que dizer sobre "ficar sem comunicação"
            }

            $age = max(0, $now->timestamp - $endpoint->last_seen_at->timestamp);
            $state = match (true) {
                $age > $limit => Evaluation::VIOLATING,
                $age <= $clear => Evaluation::CLEAR,
                default => Evaluation::NEUTRAL,
            };

            $evaluations[] = new Evaluation($endpoint->id, null, $state, (float) $age, [
                'value' => $age, 'threshold' => $limit, 'last_seen_at' => $endpoint->last_seen_at->toIso8601String(),
            ]);
        }

        return $evaluations;
    }

    /** @return list<Evaluation> */
    private function outdated(AlertRule $rule, Collection $endpoints): array
    {
        $minimum = (string) ($rule->params['min_version'] ?? config('toolit.agent.min_version'));
        $evaluations = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint->agent_version === null) {
                continue;
            }

            $old = version_compare($endpoint->agent_version, $minimum, '<');
            $evaluations[] = new Evaluation($endpoint->id, null, $old ? Evaluation::VIOLATING : Evaluation::CLEAR, null, [
                'version' => $endpoint->agent_version, 'min_version' => $minimum,
            ]);
        }

        return $evaluations;
    }
}
