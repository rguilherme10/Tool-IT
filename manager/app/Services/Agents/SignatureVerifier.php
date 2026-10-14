<?php

namespace App\Services\Agents;

use Illuminate\Http\Request;

/**
 * Verificação do protocolo de assinatura por request (ADR-004, docs/api/agent-api-v1.md §1).
 * Qualquer alteração aqui quebra o contrato com o Agent: versionar (v2), nunca mudar em silêncio.
 */
class SignatureVerifier
{
    public const VERSION_TAG = 'v1';

    public function canonicalString(Request $request, string $timestamp, string $nonce): string
    {
        return $this->canonical(
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $request->server('QUERY_STRING', ''),
            $timestamp,
            $nonce,
            $request->getContent(),
        );
    }

    /** Também usado pelos testes e pelo vetor cruzado com o Agent C#. */
    public function canonical(string $method, string $path, string $query, string $timestamp, string $nonce, string $body): string
    {
        $parts = $query === '' ? [] : explode('&', $query);
        sort($parts, SORT_STRING);

        return implode("\n", [
            self::VERSION_TAG,
            strtoupper($method),
            $path,
            implode('&', $parts),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public function verify(PublicKey $key, string $canonical, string $signatureBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || $signature === '' || strlen($signature) > 128) {
            return false;
        }

        return openssl_verify($canonical, $signature, $key->pem, OPENSSL_ALGO_SHA256) === 1;
    }
}
