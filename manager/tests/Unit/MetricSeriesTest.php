<?php

namespace Tests\Unit;

use App\Support\Metrics\MetricSeries;
use PHPUnit\Framework\TestCase;

class MetricSeriesTest extends TestCase
{
    private function row(?float $cpu, int $total = 1000, int $available = 500, string $at = '2026-01-01 00:00:00'): object
    {
        return (object) ['sampled_at' => $at, 'cpu_percent' => $cpu, 'mem_total_mb' => $total, 'mem_available_mb' => $available];
    }

    public function test_bucket_keeps_small_series_untouched(): void
    {
        $points = MetricSeries::bucket([$this->row(10.0), $this->row(20.0)], 120);

        $this->assertCount(2, $points);
        $this->assertSame(10.0, $points[0]['cpu']);
        $this->assertSame(50.0, $points[0]['mem']);
    }

    public function test_bucket_averages_groups_and_caps_the_number_of_points(): void
    {
        $rows = array_map(fn ($i) => $this->row((float) $i), range(1, 100));

        $points = MetricSeries::bucket($rows, 10);

        $this->assertCount(10, $points);
        $this->assertSame(5.5, $points[0]['cpu']);   // média de 1..10
        $this->assertSame(95.5, $points[9]['cpu']);
    }

    public function test_bucket_ignores_null_cpu_and_zero_memory(): void
    {
        $points = MetricSeries::bucket([$this->row(null, 0, 0), $this->row(40.0, 0, 0)], 1);

        $this->assertSame(40.0, $points[0]['cpu']);
        $this->assertNull($points[0]['mem']);

        $this->assertNull(MetricSeries::bucket([$this->row(null)], 1)[0]['cpu']);
    }

    public function test_bucket_of_nothing_is_empty(): void
    {
        $this->assertSame([], MetricSeries::bucket([], 10));
        $this->assertSame([], MetricSeries::bucket([$this->row(1.0)], 0));
    }

    public function test_polyline_scales_to_the_box_with_inverted_y(): void
    {
        $this->assertSame('0,100 50,50 100,0', MetricSeries::polyline([0.0, 50.0, 100.0], 100, 100));
    }

    public function test_polyline_clamps_out_of_range_and_skips_gaps(): void
    {
        $this->assertSame('0,100 100,0', MetricSeries::polyline([-10.0, null, 250.0], 100, 100));
        $this->assertSame('', MetricSeries::polyline([], 100, 100));
        $this->assertSame('50,50', MetricSeries::polyline([50.0], 100, 100));
    }
}
