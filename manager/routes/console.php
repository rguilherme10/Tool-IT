<?php

use Illuminate\Support\Facades\Schedule;

// Retenção do histórico de métricas (docs/data-model.md). Exige `schedule:run` a cada minuto (docs/deployment/server.md).
Schedule::command('metrics:prune')->dailyAt('03:15')->onOneServer()->withoutOverlapping();
