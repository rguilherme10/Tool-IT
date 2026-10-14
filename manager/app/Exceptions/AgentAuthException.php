<?php

namespace App\Exceptions;

use App\Support\Api\AgentApiError;
use Exception;
use Illuminate\Http\JsonResponse;

/** Falha de autenticação/estado do Agent. Mensagens deliberadamente genéricas (sem oráculo). */
class AgentAuthException extends Exception
{
    /** @param array<string, mixed> $details */
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $details */
    public static function invalid(array $details = []): self
    {
        return new self('auth.invalid', 'Autenticação inválida.', 401, $details);
    }

    public static function revoked(): self
    {
        return new self('agent.revoked', 'Instalação revogada.', 403);
    }

    public static function endpointDisabled(): self
    {
        return new self('endpoint.disabled', 'Endpoint desativado.', 403);
    }

    public function render(): JsonResponse
    {
        return AgentApiError::response($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
