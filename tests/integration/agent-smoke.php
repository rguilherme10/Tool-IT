<?php

/*
| Smoke test REAL contra um Manager em execução (HTTP de verdade, sem o cliente de teste do Laravel).
| Implementa o protocolo de docs/api/agent-api-v1.md de forma independente, como um Agent faria.
|
|   php tests/integration/agent-smoke.php http://127.0.0.1:8000 tk_TOKEN
|
| Sai com código 0 se todas as verificações passarem. Não grava nada fora do Manager alvo.
*/

[$self, $base, $token] = $argv + [null, null, null];
if (! $base || ! $token) {
    fwrite(STDERR, "uso: php agent-smoke.php <url-base> <enrollment-token>\n");
    exit(2);
}
$base = rtrim($base, '/');
$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.($ok || $detail === '' ? '' : "  -> {$detail}")."\n";
    $failures += $ok ? 0 : 1;
}

function http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode((string) $raw, true) ?? []];
}

$config = __DIR__.'/../../manager/tests/Support/openssl.cnf';
$key = openssl_pkey_new(['config' => $config, 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$pem = openssl_pkey_get_details($key)['key'];
$publicKey = base64_encode(base64_decode(trim(preg_replace('/-----[A-Z ]+-----/', '', $pem))));

$sign = function (string $method, string $path, string $body, ?string $nonce = null, ?int $ts = null) use ($key): array {
    $ts ??= time();
    $nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    openssl_sign("v1\n{$method}\n{$path}\n\n{$ts}\n{$nonce}\n".hash('sha256', $body), $sig, $key, OPENSSL_ALGO_SHA256);

    return [$ts, $nonce, base64_encode($sig)];
};

$call = function (string $path, array $payload, ?string $agentId, ?string $nonce = null, ?string $tamperBody = null) use ($base, $sign): array {
    $body = json_encode($payload);
    [$ts, $n, $sig] = $sign('POST', $path, $tamperBody ?? $body, $nonce);

    return http('POST', $base.$path, $body, [
        "X-Agent-Id: {$agentId}", "X-Timestamp: {$ts}", "X-Nonce: {$n}", "X-Signature: {$sig}",
    ]);
};

[$s, $meta] = http('GET', "{$base}/api/agent/v1/meta");
check('GET /meta responde protocolo v1', $s === 200 && ($meta['protocol'] ?? '') === 'v1', "status={$s}");

[$s, $enroll] = http('POST', "{$base}/api/agent/v1/enroll", json_encode([
    'enrollment_token' => $token, 'public_key' => $publicKey, 'hostname' => gethostname() ?: 'SMOKE-HOST',
    'os' => ['name' => PHP_OS_FAMILY, 'version' => php_uname('r'), 'arch' => 'x64'], 'agent_version' => '0.0.1',
]));
check('POST /enroll cria a instalação (201)', $s === 201 && isset($enroll['agent_id']), "status={$s} ".json_encode($enroll));
if ($s !== 201) {
    exit(1);
}
$agentId = $enroll['agent_id'];

[$s2] = http('POST', "{$base}/api/agent/v1/enroll", json_encode(['enrollment_token' => $token, 'public_key' => $publicKey, 'hostname' => 'X']));
check('token de uso único não pode ser reutilizado (403/422)', in_array($s2, [403, 422], true), "status={$s2}");

$path = '/api/agent/v1/heartbeat';
[$s, $hb] = $call($path, ['uptime_seconds' => 42, 'ip_addresses' => ['127.0.0.1']], $agentId, 'nonce-fixo-smoke-0001');
check('heartbeat assinado é aceito (200)', $s === 200 && isset($hb['server_time']), "status={$s} ".json_encode($hb));

[$s] = $call($path, ['uptime_seconds' => 42], $agentId, 'nonce-fixo-smoke-0001');
check('replay do mesmo nonce é rejeitado (401)', $s === 401, "status={$s}");

[$s] = $call($path, ['uptime_seconds' => 1], $agentId, null, '{"uptime_seconds":2}');
check('corpo adulterado após a assinatura é rejeitado (401)', $s === 401, "status={$s}");

[$s] = http('POST', "{$base}{$path}", '{}');
check('request sem assinatura é rejeitado (401)', $s === 401, "status={$s}");

echo "\nagent_id={$agentId}\n";
echo $failures === 0 ? "OK: todas as verificações passaram.\n" : "FALHAS: {$failures}\n";
exit($failures === 0 ? 0 : 1);
