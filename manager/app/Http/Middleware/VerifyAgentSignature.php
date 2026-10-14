<?php

namespace App\Http\Middleware;

use App\Enums\EndpointStatus;
use App\Exceptions\AgentAuthException;
use App\Models\AgentInstallation;
use App\Services\Agents\PublicKey;
use App\Services\Agents\SignatureVerifier;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica o Agent pela assinatura do request (ADR-004). Ordem deliberada:
 * identidade → relógio → assinatura → (só então) nonce e estado. Antes de a assinatura ser
 * comprovada, nenhuma resposta revela se a instalação existe, está revogada ou qual check falhou.
 */
class VerifyAgentSignature
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Agent-Id');
        $timestamp = (string) $request->header('X-Timestamp');
        $nonce = (string) $request->header('X-Nonce');
        $signature = (string) $request->header('X-Signature');

        if (! Str::isUuid($id) || ! ctype_digit($timestamp) || strlen($timestamp) > 12
            || ! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) || $signature === '') {
            throw $this->reject($request, 'malformed');
        }

        $installation = AgentInstallation::with('endpoint')->where('uuid', $id)->first();
        if ($installation === null) {
            throw $this->reject($request, 'unknown_installation');
        }

        $skew = (int) config('toolit.agent.timestamp_skew_seconds');
        if (abs(now()->timestamp - (int) $timestamp) > $skew) {
            throw $this->reject($request, 'clock_skew', ['server_time' => now()->utc()->toIso8601String()]);
        }

        if (! $this->signatureIsValid($request, $installation, $timestamp, $nonce, $signature)) {
            throw $this->reject($request, 'bad_signature');
        }

        // A partir daqui o chamador provou possuir a chave: já é seguro dar respostas específicas.
        if (! Cache::add("agent-nonce:{$id}:{$nonce}", 1, (int) config('toolit.agent.nonce_ttl_seconds'))) {
            throw $this->reject($request, 'replay');
        }

        if (! $installation->isActive()) {
            $this->audit->log('agent.revoked_access_attempt', $installation, $installation, $installation->endpoint_id, [
                'path' => $request->getPathInfo(),
            ]);
            throw AgentAuthException::revoked();
        }

        $endpoint = $installation->endpoint;
        if ($endpoint === null || $endpoint->trashed() || $endpoint->status === EndpointStatus::Disabled) {
            throw AgentAuthException::endpointDisabled();
        }

        $request->attributes->set('agent_installation', $installation);
        $request->attributes->set('agent_installation_uuid', $installation->uuid);

        return $next($request);
    }

    private function signatureIsValid(Request $request, AgentInstallation $installation, string $timestamp, string $nonce, string $signature): bool
    {
        $canonical = $this->verifier->canonicalString($request, $timestamp, $nonce);

        try {
            if ($this->verifier->verify(PublicKey::fromBase64Der($installation->public_key), $canonical, $signature)) {
                return true;
            }

            // Janela de transição após rotação de chave.
            if ($installation->previous_public_key !== null
                && $installation->previous_key_valid_until?->isFuture()) {
                return $this->verifier->verify(PublicKey::fromBase64Der($installation->previous_public_key), $canonical, $signature);
            }
        } catch (InvalidArgumentException) {
            return false;
        }

        return false;
    }

    /** @param array<string, mixed> $details */
    private function reject(Request $request, string $reason, array $details = []): AgentAuthException
    {
        // Log estruturado (não a trilha de auditoria: requests não autenticados não podem encher o banco).
        Log::warning('agent.auth_rejected', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'agent_id' => Str::isUuid((string) $request->header('X-Agent-Id')) ? $request->header('X-Agent-Id') : null,
            'path' => $request->getPathInfo(),
        ]);

        return AgentAuthException::invalid($details);
    }
}
