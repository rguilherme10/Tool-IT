<?php

namespace App\Support\Metrics;

/**
 * Redução de séries temporais para o painel (sem JavaScript: o gráfico é um SVG gerado no servidor).
 * Funções puras, sem acesso a banco.
 */
final class MetricSeries
{
    /**
     * Agrupa linhas ordenadas por tempo em no máximo $maxPoints pontos (média por grupo).
     *
     * @param  iterable<object{sampled_at: mixed, cpu_percent: mixed, mem_total_mb: mixed, mem_available_mb: mixed}>  $rows
     * @return list<array{at: mixed, cpu: ?float, mem: ?float}> mem = % de memória em uso
     */
    public static function bucket(iterable $rows, int $maxPoints): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        $count = count($rows);
        if ($count === 0 || $maxPoints < 1) {
            return [];
        }

        $size = (int) ceil($count / $maxPoints);
        $points = [];

        foreach (array_chunk($rows, $size) as $chunk) {
            $cpu = array_values(array_filter(array_map(fn ($r) => $r->cpu_percent === null ? null : (float) $r->cpu_percent, $chunk), fn ($v) => $v !== null));
            $mem = array_values(array_filter(array_map(
                fn ($r) => $r->mem_total_mb > 0 ? (1 - ($r->mem_available_mb / $r->mem_total_mb)) * 100 : null,
                $chunk,
            ), fn ($v) => $v !== null));

            $points[] = [
                'at' => $chunk[intdiv(count($chunk), 2)]->sampled_at,
                'cpu' => $cpu === [] ? null : round(array_sum($cpu) / count($cpu), 1),
                'mem' => $mem === [] ? null : round(array_sum($mem) / count($mem), 1),
            ];
        }

        return $points;
    }

    /**
     * Pontos "x,y x,y" de uma polyline SVG. Valores nulos viram lacunas (são pulados). Eixo Y invertido (0 embaixo).
     *
     * @param  list<?float>  $values
     */
    public static function polyline(array $values, int $width, int $height, float $min = 0.0, float $max = 100.0): string
    {
        $n = count($values);
        if ($n === 0 || $max <= $min) {
            return '';
        }

        $points = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }

            $x = $n === 1 ? $width / 2 : ($i / ($n - 1)) * $width;
            $clamped = min(max($value, $min), $max);
            $y = $height - ((($clamped - $min) / ($max - $min)) * $height);
            $points[] = round($x, 1).','.round($y, 1);
        }

        return implode(' ', $points);
    }
}
