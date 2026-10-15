<?php

namespace App\Services\Alerts;

use App\Enums\AlertRuleType;
use App\Models\AlertRule;
use App\Support\Metrics\SustainedCondition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras de disco (por volume), memória e CPU: "acima de X % por N minutos", com histerese.
 * Lê só o histórico de `metric_samples` (nunca consulta o endpoint).
 */
class MetricThresholdEvaluator
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

        $gap = (int) config('toolit.alerts.gap_seconds');
        $window = max($rule->min_duration_seconds, $rule->clear_duration_seconds) + $gap;
        $from = $now->subSeconds($window)->utc()->format('Y-m-d H:i:s');
        $limit = $now->addMinutes(10)->utc()->format('Y-m-d H:i:s');

        $byEndpoint = DB::table('metric_samples')
            ->whereIn('endpoint_id', $endpointIds->all())
            ->where('sampled_at', '>=', $from)->where('sampled_at', '<=', $limit)
            ->orderBy('sampled_at')
            ->get(['endpoint_id', 'sampled_at', 'cpu_percent', 'mem_total_mb', 'mem_available_mb', 'volumes'])
            ->groupBy('endpoint_id');

        $threshold = $rule->threshold;
        $clear = $rule->effectiveClearThreshold();
        $evaluations = [];

        foreach ($byEndpoint as $endpointId => $samples) {
            foreach ($this->subjects($rule, $samples) as $subject) {
                $points = $this->series($rule, $samples, $subject);
                if ($points === []) {
                    continue;
                }

                $nowTs = $now->timestamp;
                $value = $points[array_key_last($points)][1];
                $state = match (true) {
                    SustainedCondition::holds($points, fn (float $v) => $v > $threshold, $rule->min_duration_seconds, $nowTs, $gap) => Evaluation::VIOLATING,
                    SustainedCondition::holds($points, fn (float $v) => $v <= $clear, $rule->clear_duration_seconds, $nowTs, $gap) => Evaluation::CLEAR,
                    default => Evaluation::NEUTRAL,
                };

                $evaluations[] = new Evaluation((int) $endpointId, $subject, $state, $value, [
                    'value' => round($value, 1), 'threshold' => $threshold, 'clear_threshold' => $clear,
                ]);
            }
        }

        return $evaluations;
    }

    /** @return list<?string> */
    private function subjects(AlertRule $rule, Collection $samples): array
    {
        if ($rule->type !== AlertRuleType::DiskUsage) {
            return [null];
        }

        $latest = json_decode($samples->last()->volumes, true) ?? [];

        return array_values(array_unique(array_map(fn ($v) => (string) $v['mount'], $latest)));
    }

    /** @return list<array{0: int, 1: float}> */
    private function series(AlertRule $rule, Collection $samples, ?string $subject): array
    {
        $points = [];
        foreach ($samples as $sample) {
            $value = match ($rule->type) {
                AlertRuleType::CpuUsage => $sample->cpu_percent === null ? null : (float) $sample->cpu_percent,
                AlertRuleType::MemoryUsage => $sample->mem_total_mb > 0 ? (1 - ($sample->mem_available_mb / $sample->mem_total_mb)) * 100 : null,
                AlertRuleType::DiskUsage => $this->diskUsed($sample->volumes, $subject),
                default => null,
            };

            if ($value !== null) {
                $points[] = [CarbonImmutable::parse($sample->sampled_at, 'UTC')->timestamp, $value];
            }
        }

        return $points;
    }

    private function diskUsed(string $volumesJson, ?string $mount): ?float
    {
        foreach (json_decode($volumesJson, true) ?? [] as $volume) {
            if ((string) $volume['mount'] === $mount && $volume['total_gb'] > 0) {
                return (1 - ($volume['free_gb'] / $volume['total_gb'])) * 100;
            }
        }

        return null;
    }
}
