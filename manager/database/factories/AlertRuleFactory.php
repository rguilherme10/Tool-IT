<?php

namespace Database\Factories;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertRule> */
class AlertRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => AlertRuleType::CpuUsage,
            'name' => 'Regra '.fake()->unique()->bothify('??##'),
            'severity' => AlertSeverity::Warning,
            'threshold' => 90,
            'clear_threshold' => 80,
            'min_duration_seconds' => 600,
            'clear_duration_seconds' => 120,
            'repeat_interval_seconds' => 0,
            'enabled' => true,
        ];
    }

    public function type(AlertRuleType $type, float $threshold, ?float $clear = null, int $minDuration = 0): static
    {
        return $this->state(fn () => [
            'type' => $type, 'threshold' => $threshold, 'clear_threshold' => $clear, 'min_duration_seconds' => $minDuration,
        ]);
    }
}
