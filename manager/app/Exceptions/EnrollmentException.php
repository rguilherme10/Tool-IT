<?php

namespace App\Exceptions;

use App\Support\Api\AgentApiError;
use Exception;
use Illuminate\Http\JsonResponse;

/** Token de enrollment inválido, expirado, revogado ou esgotado — sempre a mesma resposta. */
class EnrollmentException extends Exception
{
    public static function invalidToken(): self
    {
        return new self('Token de enrollment inválido.');
    }

    public function render(): JsonResponse
    {
        return AgentApiError::response('enrollment.invalid', $this->getMessage(), 403);
    }
}
