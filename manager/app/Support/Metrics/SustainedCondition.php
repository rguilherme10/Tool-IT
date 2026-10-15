<?php

namespace App\Support\Metrics;

/**
 * "A condição vale há pelo menos N segundos?" sobre uma série de amostras (docs/tasks/007).
 * Função pura: nunca conclui nada a partir de poucos dados — leitura isolada, janela mal coberta, lacuna grande
 * ou dado velho resultam em FALSO. Assim um alerta nunca nasce (nem some) por uma única leitura.
 */
final class SustainedCondition
{
    /**
     * @param  list<array{0: int, 1: float}>  $points  [timestamp UNIX, valor], em ordem crescente
     * @param  callable(float): bool  $predicate
     * @param  int  $gapSeconds  maior intervalo aceitável entre amostras (e idade máxima da última)
     */
    public static function holds(array $points, callable $predicate, int $durationSeconds, int $now, int $gapSeconds): bool
    {
        if ($points === []) {
            return false;
        }

        $last = $points[array_key_last($points)];
        if ($now - $last[0] > $gapSeconds || ! $predicate($last[1])) {
            return false;
        }

        if ($durationSeconds <= 0) {
            return true;
        }

        $start = $now - $durationSeconds;
        $window = array_values(array_filter($points, fn (array $p) => $p[0] >= $start - $gapSeconds));

        // A janela precisa começar perto do início esperado (o agente já reportava há tempo suficiente).
        // A folga é proporcional à duração (no máximo $gapSeconds): com folga fixa, uma regra de 2 min abriria com
        // 30 s de histórico (a "persistência" valeria só a tolerância de lacuna).
        $startTolerance = min($gapSeconds, intdiv($durationSeconds, 4));
        if ($window === [] || $window[0][0] > $start + $startTolerance) {
            return false;
        }

        $previous = null;
        foreach ($window as [$timestamp, $value]) {
            if (! $predicate($value) || ($previous !== null && $timestamp - $previous > $gapSeconds)) {
                return false;
            }
            $previous = $timestamp;
        }

        return true;
    }
}
