<?php

namespace App\Enums;

/** Estados da tarefa remota. A máquina de estados é aplicada por App\Services\Tasks\TaskService (nunca direto no model). */
enum TaskState: string
{
    case PendingApproval = 'pending_approval';
    case Available = 'available';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Aguardando aprovação',
            self::Available => 'Disponível',
            self::Running => 'Em execução',
            self::Completed => 'Concluída',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Expirada',
        };
    }

    /** @return list<self> */
    private function allowedTargets(): array
    {
        return match ($this) {
            self::PendingApproval => [self::Available, self::Cancelled, self::Expired],
            self::Available => [self::Running, self::Cancelled, self::Expired],
            // Running -> Available: lease vencida de tarefa idempotente (nova tentativa).
            self::Running => [self::Completed, self::Failed, self::Cancelled, self::Available],
            self::Completed, self::Failed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTargets(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTargets() === [];
    }

    /** @return list<string> estados em que a tarefa ainda está aberta (não final) */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => ! $s->isFinal())
        ));
    }
}
