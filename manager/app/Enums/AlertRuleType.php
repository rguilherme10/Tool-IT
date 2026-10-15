<?php

namespace App\Enums;

enum AlertRuleType: string
{
    case DiskUsage = 'disk_usage';
    case MemoryUsage = 'memory_usage';
    case CpuUsage = 'cpu_usage';
    case AgentOffline = 'agent_offline';
    case AgentOutdated = 'agent_outdated';

    public function label(): string
    {
        return match ($this) {
            self::DiskUsage => 'Disco acima de % de uso (por volume)',
            self::MemoryUsage => 'Memória acima de % de uso',
            self::CpuUsage => 'CPU acima de % de uso',
            self::AgentOffline => 'Agente sem comunicação',
            self::AgentOutdated => 'Versão do agente desatualizada',
        };
    }

    /** Regras calculadas a partir do histórico de métricas (exigem duração mínima e histerese). */
    public function usesMetrics(): bool
    {
        return in_array($this, [self::DiskUsage, self::MemoryUsage, self::CpuUsage], true);
    }

    /** Unidade do limite: '%' (métricas) ou 's' (tempo sem comunicação). */
    public function unit(): string
    {
        return match ($this) {
            self::AgentOffline => 's',
            self::AgentOutdated => '',
            default => '%',
        };
    }
}
