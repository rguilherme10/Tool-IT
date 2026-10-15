<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:evaluate')]
#[Description('Avalia as regras de alerta (agendado a cada minuto)')]
class EvaluateAlerts extends Command
{
    public function handle(AlertEvaluator $evaluator): int
    {
        $s = $evaluator->run();

        $this->info(sprintf(
            'Regras: %d · abertos: %d · resolvidos: %d · repetições: %d · órfãos fechados: %d · regras com erro: %d',
            $s['rules'], $s['opened'], $s['resolved'], $s['repeated'], $s['orphans'], $s['failed'],
        ));

        return $s['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
