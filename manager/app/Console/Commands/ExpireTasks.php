<?php

namespace App\Console\Commands;

use App\Services\Tasks\TaskService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:expire')]
#[Description('Expira tarefas não pegas e trata leases vencidas (agendado a cada minuto)')]
class ExpireTasks extends Command
{
    public function handle(TaskService $tasks): int
    {
        $this->info('Tarefas tratadas: '.$tasks->expireDue());

        return self::SUCCESS;
    }
}
