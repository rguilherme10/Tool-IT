<?php

namespace Tests\Support;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * Agent simulado para testes: gera chave ECDSA P-256 real e assina requests de forma
 * INDEPENDENTE da implementação do Manager (a string canônica é montada aqui, à mão),
 * para que mudanças acidentais no contrato quebrem os testes.
 */
class FakeAgent
{
    public ?string $agentId = null;

    public string $publicKeyB64;

    private OpenSSLAsymmetricKey $key;

    public function __construct(private readonly TestCase $test)
    {
        [$this->key, $this->publicKeyB64] = self::generateKey();
    }

    /** @return array{0: OpenSSLAsymmetricKey, 1: string} chave privada e SPKI DER em Base64 */
    public static function generateKey(): array
    {
        // O PHP do Windows costuma não ter openssl.cnf; o arquivo mínimo evita a falha na geração de chaves.
        $key = openssl_pkey_new(['config' => self::opensslConfig(), 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new falhou: '.openssl_error_string());
        }
        $pem = openssl_pkey_get_details($key)['key'];
        $der = base64_decode(trim(preg_replace('/-----[A-Z ]+-----/', '', $pem)));

        return [$key, base64_encode($der)];
    }

    public static function opensslConfig(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'openssl.cnf';
    }

    public function enrollBody(string $token, array $overrides = []): array
    {
        return array_replace_recursive([
            'enrollment_token' => $token,
            'public_key' => $this->publicKeyB64,
            'hostname' => 'PC-TESTE-01',
            'os' => ['name' => 'Windows 11 Pro', 'version' => '10.0.26100', 'build' => '26100', 'arch' => 'x64'],
            'agent_version' => '0.1.0',
            'machine_fingerprint' => hash('sha256', 'maquina-teste'),
        ], $overrides);
    }

    public function enroll(string $token, array $overrides = []): TestResponse
    {
        $response = $this->test->postJson('/api/agent/v1/enroll', $this->enrollBody($token, $overrides));

        if ($response->status() === 201) {
            $this->agentId = $response->json('agent_id');
        }

        return $response;
    }

    public function replaceKey(): string
    {
        [$this->key, $this->publicKeyB64] = self::generateKey();

        return $this->publicKeyB64;
    }

    /** Request assinado. $tweak permite corromper um aspecto específico. */
    public function request(string $method, string $uri, ?array $body = null, array $tweak = []): TestResponse
    {
        $parts = parse_url($uri);
        $path = $parts['path'];
        $query = $parts['query'] ?? '';
        $content = $body === null ? '' : json_encode($body);

        $timestamp = (string) ($tweak['timestamp'] ?? now()->timestamp);
        $nonce = $tweak['nonce'] ?? rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

        $signedMethod = $tweak['signed_method'] ?? $method;
        $signedPath = $tweak['signed_path'] ?? $path;
        $signedQuery = $tweak['signed_query'] ?? $query;
        $signedBody = $tweak['signed_body'] ?? $content;

        $signature = $tweak['signature'] ?? $this->sign($signedMethod, $signedPath, $signedQuery, $timestamp, $nonce, $signedBody);

        $headers = array_filter([
            'HTTP_X_AGENT_ID' => $tweak['agent_id'] ?? $this->agentId,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_AGENT_VERSION' => '0.1.0',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], fn ($v) => $v !== null);

        foreach ($tweak['drop_headers'] ?? [] as $drop) {
            unset($headers[$drop]);
        }

        return $this->test->call($method, $uri, [], [], [], $headers, $content);
    }

    public function post(string $uri, array $body = [], array $tweak = []): TestResponse
    {
        return $this->request('POST', $uri, $body, $tweak);
    }

    public function sign(string $method, string $path, string $query, string $timestamp, string $nonce, string $body): string
    {
        $params = $query === '' ? [] : explode('&', $query);
        sort($params, SORT_STRING);

        $canonical = "v1\n".strtoupper($method)."\n{$path}\n".implode('&', $params)."\n{$timestamp}\n{$nonce}\n".hash('sha256', $body);

        openssl_sign($canonical, $der, $this->key, OPENSSL_ALGO_SHA256);

        return base64_encode($der);
    }

    public static function nonce(): string
    {
        return Str::random(24);
    }
}
