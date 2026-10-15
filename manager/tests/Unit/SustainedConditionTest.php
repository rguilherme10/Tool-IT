<?php

namespace Tests\Unit;

use App\Support\Metrics\SustainedCondition;
use PHPUnit\Framework\TestCase;

class SustainedConditionTest extends TestCase
{
    private const NOW = 100000;

    private const GAP = 150;

    /** Série a cada 60 s terminando em NOW, com os valores dados (do mais antigo ao mais novo). */
    private function series(array $values): array
    {
        $n = count($values);

        return array_map(fn ($i) => [self::NOW - ($n - 1 - $i) * 60, (float) $values[$i]], array_keys($values));
    }

    private function above90(array $points, int $duration): bool
    {
        return SustainedCondition::holds($points, fn (float $v) => $v > 90, $duration, self::NOW, self::GAP);
    }

    public function test_a_single_reading_never_satisfies_a_duration(): void
    {
        $this->assertFalse($this->above90($this->series([99]), 600));
        $this->assertFalse($this->above90($this->series([99, 99]), 600));
    }

    public function test_zero_duration_uses_only_the_latest_fresh_reading(): void
    {
        $this->assertTrue($this->above90($this->series([10, 99]), 0));
        $this->assertFalse($this->above90($this->series([99, 10]), 0));
    }

    public function test_sustained_over_the_whole_window_holds(): void
    {
        $this->assertTrue($this->above90($this->series(array_fill(0, 11, 95)), 600));   // 11 amostras = 10 min cobertos
    }

    public function test_a_single_dip_inside_the_window_breaks_it(): void
    {
        $values = array_fill(0, 11, 95);
        $values[5] = 70;

        $this->assertFalse($this->above90($this->series($values), 600));
    }

    public function test_window_not_yet_covered_does_not_hold(): void
    {
        $this->assertFalse($this->above90($this->series(array_fill(0, 6, 95)), 600));   // só 5 min de histórico
    }

    public function test_short_durations_still_need_real_history(): void
    {
        // Regra de 2 min com 30 s de histórico (3 amostras de 15 s) NÃO é persistência.
        $recent = array_map(fn ($i) => [self::NOW - (2 - $i) * 15, 95.0], [0, 1, 2]);
        $this->assertFalse($this->above90($recent, 120));
        $this->assertFalse($this->above90([[self::NOW, 95.0]], 120));
        $this->assertFalse($this->above90($this->series([95, 95]), 120));          // só 1 min de histórico

        $this->assertTrue($this->above90($this->series([95, 95, 95]), 120));        // 2 min cobertos
    }

    public function test_sampling_interval_of_one_minute_satisfies_short_windows(): void
    {
        $this->assertTrue($this->above90($this->series([95, 95]), 60));
        $this->assertTrue($this->above90($this->series(array_fill(0, 6, 95)), 300));
    }

    public function test_a_large_gap_inside_the_window_breaks_it(): void
    {
        $points = $this->series(array_fill(0, 11, 95));
        unset($points[4], $points[5], $points[6]);                                      // ~4 min sem amostras

        $this->assertFalse($this->above90(array_values($points), 600));
    }

    public function test_small_gaps_are_tolerated(): void
    {
        $points = $this->series(array_fill(0, 11, 95));
        unset($points[5]);                                                              // 1 amostra perdida (2 min entre vizinhas)

        $this->assertTrue($this->above90(array_values($points), 600));
    }

    public function test_stale_data_concludes_nothing(): void
    {
        $points = array_map(fn ($p) => [$p[0] - 600, $p[1]], $this->series(array_fill(0, 11, 95)));   // tudo 10 min atrás

        $this->assertFalse($this->above90($points, 600));
        $this->assertFalse($this->above90($points, 0));
    }

    public function test_empty_series_is_false(): void
    {
        $this->assertFalse($this->above90([], 0));
    }

    public function test_threshold_boundary_is_strict(): void
    {
        $this->assertFalse($this->above90($this->series(array_fill(0, 11, 90)), 600));   // 90 não é "acima de 90"
    }
}
