<?php

/*
| Smoke test REAL das rotas de transferência do Agent (docs/api/agent-api-v1.md §transfers) contra um Manager em
| execução (HTTP de verdade). Simula um Agent de forma independente do C#: enroll, poll curto, metadados, chunks
| assinados (GET e PUT com X-Chunk-Sha256), begin/complete e result.
|
|   php tests/integration/transfers-smoke.php http://127.0.0.1:8000 tk_TOKEN [segundos-de-espera] [--allow-remote]
|
| Atenção: registra (enroll) um computador descartável no Manager alvo; revogue-o depois. Só aceita URL de loopback
| (127.0.0.1, localhost, ::1), a menos que se passe --allow-remote. Nada é gravado em disco por este script: os bytes
| do envio são só baixados e conferidos em memória, e os da recuperação são gerados aleatoriamente aqui.
| Depois do enroll o script imprime o endpoint_id e espera (padrão 180 s), nesta ordem:
|   1. um ENVIO (file.send) para esse computador criado no painel (qualquer arquivo pequeno, > 2 MiB de preferência);
|   2. uma RECUPERAÇÃO (file.fetch) de qualquer caminho permitido pela política (o conteúdo vem deste script).
| Sai com código 0 se todas as verificações passarem.
*/

$args = array_slice($argv, 1);
$allowRemote = in_array('--allow-remote', $args, true);
[$base, $token, $waitSeconds] = array_values(array_diff($args, ['--allow-remote'])) + [null, null, '180'];
if (! $base || ! $token) {
    fwrite(STDERR, "uso: php transfers-smoke.php <url-base> <enrollment-token> [segundos-de-espera] [--allow-remote]\n");
    exit(2);
}
$base = rtrim($base, '/');
$host = strtolower(trim((string) parse_url($base, PHP_URL_HOST), '[]'));
if (! $allowRemote && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "recusado: '{$base}' não é loopback; passe --allow-remote se for intencional.\n");
    exit(2);
}
$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.($ok || $detail === '' ? '' : "  -> {$detail}")."\n";
    $failures += $ok ? 0 : 1;
}

/** @return array{0: int, 1: string, 2: array<string, string>} status, corpo bruto, cabeçalhos (minúsculos) */
function http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $raw, $responseHeaders];
}

function code(string $raw): string
{
    return (string) (json_decode($raw, true)['error']['code'] ?? '');
}

$config = __DIR__.'/../../manager/tests/Support/openssl.cnf';
$key = openssl_pkey_new(['config' => $config, 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$pem = openssl_pkey_get_details($key)['key'];
$publicKey = base64_encode(base64_decode(trim(preg_replace('/-----[A-Z ]+-----/', '', $pem))));
$agentId = null;

/** Request assinado (canônica v1: método, caminho, query ordenada, timestamp, nonce, sha256(corpo)). */
$call = function (string $method, string $path, string $query = '', ?string $body = null, array $extra = [], string $type = 'application/json') use ($base, $key, &$agentId): array {
    $ts = time();
    $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $parts = $query === '' ? [] : explode('&', $query);
    sort($parts, SORT_STRING);
    openssl_sign("v1\n{$method}\n{$path}\n".implode('&', $parts)."\n{$ts}\n{$nonce}\n".hash('sha256', $body ?? ''), $sig, $key, OPENSSL_ALGO_SHA256);

    return http($method, $base.$path.($query === '' ? '' : "?{$query}"), $body, array_merge([
        'Accept: application/json, application/octet-stream', "Content-Type: {$type}",
        "X-Agent-Id: {$agentId}", "X-Timestamp: {$ts}", "X-Nonce: {$nonce}", 'X-Signature: '.base64_encode($sig),
    ], $extra));
};

$json = fn (string $method, string $path, ?array $payload = null) => $call($method, $path, '', $payload === null ? null : json_encode($payload));

[$s, $raw] = http('POST', "{$base}/api/agent/v1/enroll", json_encode([
    'enrollment_token' => $token, 'public_key' => $publicKey, 'hostname' => 'TRANSFERS-SMOKE-'.bin2hex(random_bytes(3)),
    'os' => ['name' => PHP_OS_FAMILY, 'version' => php_uname('r'), 'arch' => 'x64'], 'agent_version' => '0.1.0',
]), ['Accept: application/json', 'Content-Type: application/json']);
$enroll = json_decode($raw, true) ?? [];
check('POST /enroll cria a instalação (201)', $s === 201 && isset($enroll['agent_id']), "status={$s}");
if ($s !== 201) {
    exit(1);
}
$agentId = $enroll['agent_id'];
$endpointId = $enroll['endpoint_id'];

$waitTask = function (string $type) use ($call, $waitSeconds): ?array {
    $deadline = time() + (int) $waitSeconds;
    while (time() < $deadline) {
        [$s, $raw] = $call('GET', '/api/agent/v1/tasks/next', 'wait=0');
        if ($s === 200) {
            $task = json_decode($raw, true);
            if (($task['type'] ?? '') === $type) {
                return $task;
            }
            fwrite(STDERR, "tarefa inesperada ({$task['type']}) — nenhum resultado enviado; cancele-a no painel.\n");

            return null;
        }
        sleep(2);
    }

    return null;
};

// --- 1. envio (file.send) ---------------------------------------------------------------------------------
echo "\nendpoint_id={$endpointId}\nCrie agora, no painel, um ENVIO de arquivo para este computador (espera de {$waitSeconds} s)...\n";
$task = $waitTask('file.send');
check('tarefa file.send entregue com params só {transfer_id}', $task !== null && array_keys($task['params'] ?? []) === ['transfer_id'], json_encode($task));
if ($task === null) {
    exit(1);
}
$id = $task['params']['transfer_id'];
$transfer = "/api/agent/v1/transfers/{$id}";

[$s] = http('GET', $base.$transfer, null, ['Accept: application/json']);
check('GET /transfers/{id} sem assinatura → 401', $s === 401, "status={$s}");

[$s, $raw] = $call('GET', $transfer);
$meta = json_decode($raw, true) ?? [];
$keys = array_keys($meta);
sort($keys);
$expected = ['chunk_size', 'chunks_total', 'direction', 'endpoint_id', 'overwrite', 'received_chunks', 'remote_path', 'sha256', 'size', 'transfer_id'];
check('metadados: 200 com exatamente as 10 chaves do contrato', $s === 200 && $keys === $expected, "status={$s} ".implode(',', $keys));
check('metadados: endpoint_id deste computador, direção send, chunk de 2 MiB',
    ($meta['endpoint_id'] ?? '') === $endpointId && ($meta['direction'] ?? '') === 'send' && ($meta['chunk_size'] ?? 0) === 2097152, $raw);

$other = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFF), random_int(0, 0xFFFFFFFFFFF));
[$s, $raw] = $call('GET', "/api/agent/v1/transfers/{$other}");
check('transferência inexistente/de outro computador → 404 request.not_found', $s === 404 && code($raw) === 'request.not_found', "status={$s}");

$total = (int) ($meta['chunks_total'] ?? 0);
[$s, $raw] = $call('GET', "{$transfer}/chunks/{$total}");
check('chunk n = chunks_total → 422', $s === 422, "status={$s} ".code($raw));

// Rajada acima do orçamento de agent-api (120/min): o limite próprio agent-transfer (600/min) não devolve 429.
$burst = 130;
$statuses = [];
$hashOk = true;
$t0 = microtime(true);
for ($i = 0; $i < $burst; $i++) {
    [$s, $raw, $h] = $call('GET', "{$transfer}/chunks/0");
    $statuses[$s] = ($statuses[$s] ?? 0) + 1;
    $hashOk = $hashOk && ($h['x-chunk-sha256'] ?? '') === hash('sha256', $raw);
}
$elapsed = microtime(true) - $t0;
check(sprintf('%d GETs de chunk em %.1f s: todos 200, nenhum 429', $burst, $elapsed), $statuses === [200 => $burst], json_encode($statuses));
check('X-Chunk-Sha256 confere com o corpo em todas as respostas', $hashOk);

$ctx = hash_init('sha256');
$size = 0;
for ($n = 0; $n < $total; $n++) {
    [$s, $raw, $h] = $call('GET', "{$transfer}/chunks/{$n}");
    if ($s !== 200 || ($h['x-chunk-sha256'] ?? '') !== hash('sha256', $raw)) {
        check("chunk {$n}", false, "status={$s}");
        break;
    }
    hash_update($ctx, $raw);
    $size += strlen($raw);
}
$sha = hash_final($ctx);
check('SHA-256 e tamanho do arquivo montado conferem com os metadados', $sha === ($meta['sha256'] ?? '') && $size === ($meta['size'] ?? -1), "{$sha} {$size}");

[$s, $raw] = $json('POST', "{$transfer}/complete", ['sha256' => $sha, 'size' => $size]);
check('POST complete (envio) → 200 completed', $s === 200 && (json_decode($raw, true)['status'] ?? '') === 'completed', "status={$s} {$raw}");

[$s, $raw] = $json('POST', "/api/agent/v1/tasks/{$task['task_id']}/result", ['attempt_id' => $task['attempt_id'], 'status' => 'completed', 'exit_code' => 0, 'stdout' => 'smoke', 'stderr' => '', 'truncated' => false]);
check('result da tarefa file.send → 200 completed', $s === 200 && (json_decode($raw, true)['state'] ?? '') === 'completed', "status={$s}");

[$s, $raw] = $call('GET', $transfer);
check('transferência concluída não é mais servida ao Agent (409 transfer.conflict)', $s === 409 && code($raw) === 'transfer.conflict', "status={$s}");

// --- 2. recuperação (file.fetch) --------------------------------------------------------------------------
echo "\nCrie agora, no painel, uma RECUPERAÇÃO de arquivo deste computador (espera de {$waitSeconds} s)...\n";
$task = $waitTask('file.fetch');
check('tarefa file.fetch entregue', $task !== null);
if ($task === null) {
    exit(1);
}
$id = $task['params']['transfer_id'];
$transfer = "/api/agent/v1/transfers/{$id}";
[$s, $raw] = $call('GET', $transfer);
$meta = json_decode($raw, true) ?? [];
check('metadados da recuperação: size/sha256/chunks_total nulos, received_chunks vazio',
    $s === 200 && $meta['size'] === null && $meta['sha256'] === null && $meta['chunks_total'] === null && $meta['received_chunks'] === [], $raw);

$content = random_bytes(2097152 + 123457);
$chunks = str_split($content, 2097152);
[$s, $raw] = $json('POST', "{$transfer}/begin", ['size' => strlen($content)]);
check('POST begin {size} → 200', $s === 200, "status={$s} {$raw}");

[$s, $raw] = $call('PUT', "{$transfer}/chunks/0", '', $chunks[0], ['X-Chunk-Sha256: '.hash('sha256', 'outro conteúdo')], 'application/octet-stream');
check('PUT chunk com X-Chunk-Sha256 errado → 422 transfer.chunk_hash', $s === 422 && code($raw) === 'transfer.chunk_hash', "status={$s} ".code($raw));

[$s, $raw] = $call('PUT', "{$transfer}/chunks/1", '', $chunks[1], [], 'application/octet-stream');
check('PUT chunk sem X-Chunk-Sha256 → 4xx', $s >= 400 && $s < 500, "status={$s}");

foreach ([1, 0, 1] as $n) {   // fora de ordem e repetido (idempotente)
    [$s, $raw] = $call('PUT', "{$transfer}/chunks/{$n}", '', $chunks[$n], ['X-Chunk-Sha256: '.hash('sha256', $chunks[$n])], 'application/octet-stream');
    check("PUT chunk {$n} → 200", $s === 200, "status={$s} {$raw}");
}

[$s, $raw] = $json('POST', "{$transfer}/complete", ['sha256' => hash('sha256', $content), 'size' => strlen($content)]);
check('POST complete (recuperação) → 200 completed', $s === 200 && (json_decode($raw, true)['status'] ?? '') === 'completed', "status={$s} {$raw}");

[$s] = $json('POST', "/api/agent/v1/tasks/{$task['task_id']}/result", ['attempt_id' => $task['attempt_id'], 'status' => 'completed', 'exit_code' => 0, 'stdout' => 'smoke', 'stderr' => '', 'truncated' => false]);
check('result da tarefa file.fetch → 200', $s === 200, "status={$s}");

echo "\nagent_id={$agentId} recuperação={$id} sha256_enviado=".hash('sha256', $content)."\n";
echo $failures === 0 ? "OK: todas as verificações passaram.\n" : "FALHAS: {$failures}\n";
exit($failures === 0 ? 0 : 1);
