<?php

namespace App\Http\Middleware;

use App\Support\Api\AgentApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teto de tamanho do corpo (413 payload.too_large), aplicado ANTES da verificação da assinatura
 * para que corpos gigantes nunca sejam lidos/hasheados. Uso: `agent.payload:4194304`.
 */
class LimitAgentPayload
{
    public function handle(Request $request, Closure $next, ?int $maxBytes = null): Response
    {
        $max = $maxBytes ?? (int) config('toolit.agent.max_body_bytes');

        $declared = (int) $request->header('Content-Length', 0);
        if ($declared > $max || ($declared === 0 && strlen($request->getContent()) > $max)) {
            return AgentApiError::response('payload.too_large', 'Payload grande demais.', 413, ['max_bytes' => $max]);
        }

        return $next($request);
    }
}
