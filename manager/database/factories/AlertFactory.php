<?php

namespace Database\Factories;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Somente para testes: alertas reais nascem do AlertEvaluator. */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [];
    }

    public function forEndpoint(Endpoint $endpoint, ?AlertRule $rule = null, ?string $subject = null, AlertStatus $status = AlertStatus::Open): Alert
    {
        $rule ??= AlertRule::factory()->create();
        $alert = new Alert;
        $alert->forceFill([
            'uuid' => (string) Str::uuid(),
            'endpoint_id' => $endpoint->id,
            'alert_rule_id' => $rule->id,
            'rule_type' => $rule->type ?? AlertRuleType::CpuUsage,
            'rule_name' => $rule->name,
            'subject' => $subject,
            'dedupe_hash' => Alert::dedupeHash($rule->id, $endpoint->id, $subject),
            'status' => $status,
            'severity' => $rule->severity ?? AlertSeverity::Warning,
            'title' => 'Alerta de teste em '.$endpoint->hostname,
            'details' => ['value' => 95],
            'opened_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        return $alert;
    }
}
