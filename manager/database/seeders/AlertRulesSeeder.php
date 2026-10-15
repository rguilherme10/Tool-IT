<?php

namespace Database\Seeders;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use Illuminate\Database\Seeder;

/**
 * Regras de referência (padrões conservadores, editáveis em "Regras de alerta"). Só cria se NÃO existir nenhuma
 * regra: reexecutar o seeder nunca recria regras que o administrador apagou ou alterou.
 */
class AlertRulesSeeder extends Seeder
{
    public function run(): void
    {
        if (AlertRule::query()->exists()) {
            return;
        }

        $defaults = [
            ['type' => AlertRuleType::DiskUsage, 'name' => 'Disco quase cheio', 'severity' => AlertSeverity::Critical,
                'threshold' => 90, 'clear_threshold' => 85, 'min_duration_seconds' => 600],
            ['type' => AlertRuleType::MemoryUsage, 'name' => 'Memória alta e persistente', 'severity' => AlertSeverity::Warning,
                'threshold' => 90, 'clear_threshold' => 80, 'min_duration_seconds' => 900],
            ['type' => AlertRuleType::CpuUsage, 'name' => 'CPU alta e persistente', 'severity' => AlertSeverity::Warning,
                'threshold' => 90, 'clear_threshold' => 75, 'min_duration_seconds' => 900],
            ['type' => AlertRuleType::AgentOffline, 'name' => 'Agente sem comunicação', 'severity' => AlertSeverity::Critical,
                'threshold' => 300, 'clear_threshold' => 120, 'min_duration_seconds' => 0],
            ['type' => AlertRuleType::AgentOutdated, 'name' => 'Agente desatualizado', 'severity' => AlertSeverity::Info,
                'threshold' => 0, 'clear_threshold' => null, 'min_duration_seconds' => 0, 'params' => ['min_version' => config('toolit.agent.min_version')]],
        ];

        foreach ($defaults as $rule) {
            AlertRule::create($rule + ['clear_duration_seconds' => 120, 'repeat_interval_seconds' => 3600, 'enabled' => true]);
        }
    }
}
