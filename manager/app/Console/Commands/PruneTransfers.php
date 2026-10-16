<?php

namespace App\Console\Commands;

use App\Services\Transfers\TransferService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transfers:prune {--days= : Retenção em dias (padrão: toolit.transfers.retention_days)}')]
#[Description('Expira uploads abandonados e apaga os arquivos de transferências encerradas (os metadados ficam)')]
class PruneTransfers extends Command
{
    public function handle(TransferService $transfers): int
    {
        $option = $this->option('days');
        $days = (int) ($option === null || $option === '' ? config('toolit.transfers.retention_days') : $option);
        if ($days < 1) {
            $this->error('A retenção deve ser de pelo menos 1 dia.');

            return self::FAILURE;
        }

        $this->info('Arquivos de transferências apagados: '.$transfers->prune($days)." (encerradas há mais de {$days} dias).");

        return self::SUCCESS;
    }
}
