<?php

namespace App\Enums;

/**
 * Estados da transferência de arquivo. A máquina de estados é aplicada por App\Services\Transfers\TransferService
 * (UPDATE condicional, nunca direto no model).
 *
 * Envio: uploading -> ready (upload montado + tarefa criada) -> transferring (primeiro chunk lido pelo Agent) -> completed.
 * Recuperação: ready (+ tarefa) -> transferring (begin) -> verifying (complete recebido) -> completed | failed.
 */
enum TransferStatus: string
{
    case Uploading = 'uploading';
    case Ready = 'ready';
    case Transferring = 'transferring';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Uploading => 'Recebendo do navegador',
            self::Ready => 'Pronta',
            self::Transferring => 'Transferindo',
            self::Verifying => 'Verificando',
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
            self::Uploading => [self::Ready, self::Cancelled, self::Expired, self::Failed],
            self::Ready => [self::Transferring, self::Cancelled, self::Expired, self::Failed],
            // Transferring -> Ready: o Agent recomeça a transferência (nova tentativa, arquivo com outro tamanho).
            self::Transferring => [self::Verifying, self::Completed, self::Failed, self::Cancelled, self::Expired, self::Ready],
            self::Verifying => [self::Completed, self::Failed],
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

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => ! $s->isFinal())
        ));
    }

    /** @return list<string> */
    public static function finalValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->isFinal())
        ));
    }
}
