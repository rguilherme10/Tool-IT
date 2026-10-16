<?php

namespace App\Enums;

enum TaskRisk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixo',
            self::Medium => 'Médio',
            self::High => 'Alto',
        };
    }

    /** Alto risco nasce pendente e exige aprovação de outro usuário. */
    public function requiresApproval(): bool
    {
        return $this === self::High;
    }
}
