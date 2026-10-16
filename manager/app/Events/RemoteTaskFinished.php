<?php

namespace App\Events;

use App\Models\RemoteTask;

/**
 * Uma tarefa remota chegou a um estado final (completed, failed, cancelled, expired).
 *
 * Disparado por TaskService DENTRO da transação da transição (síncrono, sem fila): um listener que lança
 * desfaz a transição inteira. Os listeners filtram pelo tipo. Existe para que TransferService reaja ao fim das
 * tarefas file.* sem que TaskService dependa dele (evita ciclo de injeção).
 */
final class RemoteTaskFinished
{
    public function __construct(public readonly RemoteTask $task) {}
}
