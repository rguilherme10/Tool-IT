<?php

namespace App\Exceptions;

use App\Support\Api\AgentApiError;
use Exception;
use Illuminate\Http\JsonResponse;

/** Resultado/progresso de um attempt que não é o vigente (antigo, encerrado com outro resultado, lease vencida ou de outra tarefa). */
class TaskConflictException extends Exception
{
    public static function staleAttempt(): self
    {
        return new self('A tentativa informada não é a vigente para esta tarefa.');
    }

    public function render(): JsonResponse
    {
        return AgentApiError::response('task.conflict', $this->getMessage(), 409);
    }
}
