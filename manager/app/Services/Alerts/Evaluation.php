<?php

namespace App\Services\Alerts;

/** Resultado da avaliação de UMA regra para UM computador (e assunto, p.ex. um volume). */
final readonly class Evaluation
{
    public const VIOLATING = 'violating';   // condição sustentada: abrir/manter alerta

    public const CLEAR = 'clear';           // abaixo do limite de histerese, sustentado: resolver

    public const NEUTRAL = 'neutral';       // dados insuficientes ou dentro da faixa de histerese: não mudar nada

    /** @param array<string, mixed> $details */
    public function __construct(
        public int $endpointId,
        public ?string $subject,
        public string $state,
        public ?float $value = null,
        public array $details = [],
    ) {}
}
