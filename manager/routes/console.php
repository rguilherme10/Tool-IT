<?php

use Illuminate\Support\Facades\Schedule;

// Exige `schedule:run` a cada minuto (docs/deployment/server.md).
Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Retenção (docs/data-model.md).
Schedule::command('metrics:prune')->dailyAt('03:15')->onOneServer()->withoutOverlapping();
Schedule::command('alerts:prune')->dailyAt('03:30')->onOneServer()->withoutOverlapping();
