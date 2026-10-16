<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cabeçalhos de segurança em todas as respostas (docs/tasks/002). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), display-capture=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // Sem scripts/estilos inline de terceiros. Em desenvolvimento o servidor do Vite precisa de exceções.
        if (! app()->isLocal()) {
            $headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data:; "
                ."style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
        }

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        // Não sobrescreve nem duplica o que a resposta já definiu (ex.: download com nosniff explícito).
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
