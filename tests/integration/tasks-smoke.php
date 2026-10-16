<?php

/*
| Smoke test REAL do ciclo de tarefas remotas contra um Manager em execução (HTTP de verdade).
| Simula um Agent de forma independente (docs/api/agent-api-v1.md §tasks): enroll, poll curto
| `GET /tasks/next?wait=0` assinado (query entra na assinatura), `result` e suas recusas.
|
|   php tests/integration/tasks-smoke.php http://127.0.0.1:8000 tk_TOKEN [segundos-de-espera] [--allow-remote]
|
| Atenção: o script registra (enroll) um computador descartável no Manager alvo; revogue-o depois.
| Só aceita URL de loopback (127.0.0.1, localhost, ::1), a menos que se passe --allow-remote.
| Depois do enroll o script imprime o endpoint_id e espera (padrão 120 s) até alguém criar, no painel,
| uma tarefa de BAIXO risco (ex.: "Informações do sistema") para esse computador. Nada é executado
| de verdade: o script só devolve um resultado fictício. Sai com código 0 se todas as verificações passarem.
*/

$args = array_slice($argv, 1);
$allowRemote = in_array('--allow-remote', $args, true);
[$base, $token, $waitSeconds] = array_values(array_diff($args, ['--allow-remote'])) + [null, null, '120'];
if (! $base || ! $token) {
    fwrite(STDERR, "uso: php tasks-smoke.php <url-base> <enrollment-token> [segundos-de-espera] [--allow-remote]\n");
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

function http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
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

/** Canônica v1: método, caminho, query com pares ordenados, timestamp, nonce, sha256(corpo). */
$sign = function (string $method, string $path, string $query, string $body) use ($key): array {
    $ts = time();
    $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $parts = $query === '' ? [] : explode('&', $query);
    sort($parts, SORT_STRING);
    openssl_sign("v1\n{$method}\n{$path}\n".implode('&', $parts)."\n{$ts}\n{$nonce}\n".hash('sha256', $body), $sig, $key, OPENSSL_ALGO_SHA256);

    return [$ts, $nonce, base64_encode($sig)];
};

$agentId = null;
$call = function (string $method, string $path, string $query = '', ?array $payload = null, ?string $signedQuery = null) use ($base, $sign, &$agentId): array {
    $body = $payload === null ? '' : json_encode($payload);
    [$ts, $n, $sig] = $sign($method, $path, $signedQuery ?? $query, $body);

    return http($method, $base.$path.($query === '' ? '' : "?{$query}"), $payload === null ? null : $body, [
        "X-Agent-Id: {$agentId}", "X-Timestamp: {$ts}", "X-Nonce: {$n}", "X-Signature: {$sig}",
    ]);
};

[$s, $enroll] = http('POST', "{$base}/api/agent/v1/enroll", json_encode([
    'enrollment_token' => $token, 'public_key' => $publicKey, 'hostname' => 'TASKS-SMOKE-'.bin2hex(random_bytes(3)),
    'os' => ['name' => PHP_OS_FAMILY, 'version' => php_uname('r'), 'arch' => 'x64'], 'agent_version' => '0.1.0',
]));
check('POST /enroll cria a instalação (201)', $s === 201 && isset($enroll['agent_id']), "status={$s}");
if ($s !== 201) {
    exit(1);
}
$agentId = $enroll['agent_id'];

$next = '/api/agent/v1/tasks/next';
[$s] = $call('GET', $next, 'wait=0');
check('GET /tasks/next?wait=0 sem tarefa → 204', $s === 204, "status={$s}");

[$s] = $call('GET', $next, 'wait=5', null, 'wait=0');
check('query diferente da assinada é rejeitada (401)', $s === 401, "status={$s}");

[$s] = http('GET', "{$base}{$next}?wait=0");
check('GET /tasks/next sem assinatura é rejeitado (401)', $s === 401, "status={$s}");

echo "\nendpoint_id={$enroll['endpoint_id']}\nCrie agora, no painel, uma tarefa de baixo risco para este computador (espera de {$waitSeconds} s)...\n";
$task = null;
$deadline = time() + (int) $waitSeconds;
while (time() < $deadline) {
    [$s, $body] = $call('GET', $next, 'wait=0');
    if ($s === 200) {
        $task = $body;
        break;
    }
    sleep(2);
}
check('tarefa entregue (200)', $task !== null, 'nenhuma tarefa no prazo');
if ($task === null) {
    exit(1);
}

$expected = ['task_id', 'attempt_id', 'type', 'params', 'timeout_seconds', 'lease_expires_at', 'risk'];
$keys = array_keys($task);
sort($keys);
sort($expected);
check('corpo de next tem exatamente os 7 campos do contrato', $keys === $expected, implode(',', array_keys($task)));
check('tarefa entregue é de baixo risco', ($task['risk'] ?? '') === 'low', 'risk='.($task['risk'] ?? '?'));
if (($task['risk'] ?? '') !== 'low') {
    // Nunca devolver resultado fictício para uma tarefa que alteraria o sistema: o painel mostraria algo que não aconteceu.
    fwrite(STDERR, "abortado: a tarefa entregue ({$task['type']}, risco ".($task['risk'] ?? '?').") não é de baixo risco; nenhum resultado foi enviado. Cancele-a no painel.\n");
    exit(1);
}

[$s] = $call('GET', $next, 'wait=0');
check('a mesma tarefa não é entregue duas vezes (204)', $s === 204, "status={$s}");

$resultPath = "/api/agent/v1/tasks/{$task['task_id']}/result";
$other = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFF), random_int(0, 0xFFFFFFFFFFF));
[$s, $body] = $call('POST', $resultPath, '', ['attempt_id' => $other, 'status' => 'completed']);
check('resultado de attempt que não é o vigente → 409 task.conflict', $s === 409 && ($body['error']['code'] ?? '') === 'task.conflict', "status={$s}");

$result = ['attempt_id' => $task['attempt_id'], 'status' => 'completed', 'exit_code' => 0, 'stdout' => '{"smoke":true}', 'stderr' => '', 'truncated' => false];
[$s, $body] = $call('POST', $resultPath, '', $result);
check('resultado do attempt vigente → 200 completed', $s === 200 && ($body['state'] ?? '') === 'completed', "status={$s} ".json_encode($body));

[$s, $body] = $call('POST', $resultPath, '', $result);
check('reenvio idêntico é idempotente (200)', $s === 200 && ($body['state'] ?? '') === 'completed', "status={$s}");

[$s] = $call('POST', $resultPath, '', ['status' => 'failed', 'error' => 'outro'] + $result);
check('resultado divergente para attempt encerrado → 409', $s === 409, "status={$s}");

[$s] = $call('POST', "/api/agent/v1/tasks/{$task['task_id']}/progress", '', ['attempt_id' => $task['attempt_id'], 'percent' => 50]);
check('progress depois do resultado → 409', $s === 409, "status={$s}");

[$s, $hb] = $call('POST', '/api/agent/v1/heartbeat', '', ['uptime_seconds' => 42]);
check('heartbeat: tasks_available=false e cancel_task_ids vazio', $s === 200 && ($hb['tasks_available'] ?? null) === false && ($hb['cancel_task_ids'] ?? null) === [], "status={$s} ".json_encode($hb));

echo "\nagent_id={$agentId} task_id={$task['task_id']}\n";
echo $failures === 0 ? "OK: todas as verificações passaram.\n" : "FALHAS: {$failures}\n";
exit($failures === 0 ? 0 : 1);
