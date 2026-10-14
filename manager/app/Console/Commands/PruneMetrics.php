<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('metrics:prune {--days= : Retenção em dias (padrão: toolit.retention.metrics_days)}')]
#[Description('Remove amostras de métricas mais antigas que a retenção configurada')]
class PruneMetrics extends Command
{
    private const BATCH = 5000;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('toolit.retention.metrics_days'));
        if ($days < 1) {
            $this->error('A retenção deve ser de pelo menos 1 dia.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s');
        $deleted = 0;

        // Em lotes por id: evita transações gigantes e bloqueios longos na tabela de maior volume.
        do {
            $ids = DB::table('metric_samples')->where('sampled_at', '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table('metric_samples')->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::BATCH);

        $this->info("Amostras removidas: {$deleted} (anteriores a {$cutoff} UTC).");

        return self::SUCCESS;
    }
}
