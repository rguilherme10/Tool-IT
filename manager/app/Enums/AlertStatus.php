<?php

namespace App\Enums;

enum AlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberto',
            self::Acknowledged => 'Reconhecido',
            self::Resolved => 'Resolvido',
        };
    }

    /** @return list<string> estados em que o alerta ainda está ativo (bloqueia duplicatas) */
    public static function activeValues(): array
    {
        return [self::Open->value, self::Acknowledged->value];
    }
}
