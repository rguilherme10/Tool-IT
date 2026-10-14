<?php

use App\Http\Middleware\EnsureTwoFactorForAdmins;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\LimitAgentPayload;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyAgentSignature;
use App\Support\Api\AgentApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent.signed' => VerifyAgentSignature::class,
            'agent.payload' => LimitAgentPayload::class,
            'active' => EnsureUserIsActive::class,
            'mfa.admin' => EnsureTwoFactorForAdmins::class,
        ]);

        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toda a superfície /api/agent/* responde no formato de erro do contrato.
        $isAgentApi = fn (Request $request) => $request->is('api/agent/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isAgentApi($request) || $request->expectsJson());

        $exceptions->render(function (ValidationException $e, Request $request) use ($isAgentApi) {
            return $isAgentApi($request)
                ? AgentApiError::response('validation.failed', 'Payload inválido.', 422, ['fields' => $e->errors()])
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isAgentApi) {
            return $isAgentApi($request)
                ? AgentApiError::response('rate.limited', 'Muitas requisições.', 429, [], array_intersect_key($e->getHeaders(), ['Retry-After' => 1]))
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isAgentApi) {
            if (! $isAgentApi($request)) {
                return null;
            }

            return match ($e->getStatusCode()) {
                404 => AgentApiError::response('request.not_found', 'Recurso não encontrado.', 404),
                405 => AgentApiError::response('request.method_not_allowed', 'Método não permitido.', 405),
                413 => AgentApiError::response('payload.too_large', 'Payload grande demais.', 413),
                default => null,
            };
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isAgentApi) {
            return $isAgentApi($request) ? AgentApiError::response('auth.invalid', 'Autenticação inválida.', 401) : null;
        });
    })->create();
