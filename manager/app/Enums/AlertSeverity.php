<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Informação',
            self::Warning => 'Atenção',
            self::Critical => 'Crítico',
        };
    }
}
