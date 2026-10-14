<?php

namespace App\Services\Metrics;

use App\Models\Endpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MetricsIngestService
{
    /** Tolerância para relógio adiantado do endpoint. */
    private const FUTURE_TOLERANCE_MINUTES = 10;

    /**
     * Idempotente por (endpoint, sample_id). Amostras fora da janela de retenção ou do futuro são recusadas
     * individualmente (sem derrubar o lote inteiro).
     *
     * @param  list<array<string, mixed>>  $samples  já validadas
     * @return array{accepted: int, duplicates: int, rejected: int}
     */
    public function ingest(Endpoint $endpoint, array $samples): array
    {
        $now = CarbonImmutable::now('UTC');
        $oldest = $now->subDays((int) config('toolit.retention.metrics_days'));
        $newest = $now->addMinutes(self::FUTURE_TOLERANCE_MINUTES);

        $rows = [];
        $rejected = 0;
        foreach ($samples as $sample) {
            $at = CarbonImmutable::parse($sample['at'])->utc();
            if ($at->lt($oldest) || $at->gt($newest)) {
                $rejected++;

                continue;
            }

            $rows[] = [
                'endpoint_id' => $endpoint->id,
                'sample_id' => $sample['sample_id'],
                'sampled_at' => $at->format('Y-m-d H:i:s'),
                'received_at' => $now->format('Y-m-d H:i:s'),
                'cpu_percent' => isset($sample['cpu_percent']) ? round((float) $sample['cpu_percent'], 1) : null,
                'mem_total_mb' => $sample['mem_total_mb'],
                'mem_available_mb' => $sample['mem_available_mb'],
                'uptime_seconds' => $sample['uptime_seconds'],
                'volumes' => json_encode($this->volumes($sample['volumes']), JSON_UNESCAPED_UNICODE),
                'temperature_c' => isset($sample['temperature_c']) ? round((float) $sample['temperature_c'], 1) : null,
            ];
        }

        $inserted = $rows === [] ? 0 : DB::table('metric_samples')->insertOrIgnore($rows);

        return ['accepted' => $inserted, 'duplicates' => count($rows) - $inserted, 'rejected' => $rejected];
    }

    /**
     * @param  list<array<string, mixed>>  $volumes
     * @return list<array{mount: string, total_gb: float, free_gb: float}>
     */
    private function volumes(array $volumes): array
    {
        return array_map(fn (array $v) => [
            'mount' => $v['mount'],
            'total_gb' => round((float) $v['total_gb'], 2),
            'free_gb' => round((float) $v['free_gb'], 2),
        ], $volumes);
    }
}
