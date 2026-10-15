<?php

namespace App\Console\Commands;

use App\Enums\AlertStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('alerts:prune {--days= : Retenção em dias (padrão: toolit.retention.alerts_days)}')]
#[Description('Remove alertas RESOLVIDOS mais antigos que a retenção (alertas ativos nunca são removidos)')]
class PruneAlerts extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('toolit.retention.alerts_days'));
        if ($days < 1) {
            $this->error('A retenção deve ser de pelo menos 1 dia.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s');
        $deleted = DB::table('alerts')
            ->where('status', AlertStatus::Resolved->value)->where('resolved_at', '<', $cutoff)->delete();

        $this->info("Alertas resolvidos removidos: {$deleted} (anteriores a {$cutoff} UTC).");

        return self::SUCCESS;
    }
}
