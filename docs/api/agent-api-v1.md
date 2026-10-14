# API do Agent — v1

Base: `https://<manager>/api/agent/v1`. JSON UTF-8. Sempre HTTPS. Iniciada pelo Agent.

## 1. Autenticação por request (todas as rotas exceto `POST /enroll`)

Cabeçalhos obrigatórios:

| Header | Conteúdo |
|---|---|
| `X-Agent-Id` | UUID da instalação (`agent_installations.uuid`) |
| `X-Timestamp` | Unix epoch em segundos (UTC) |
| `X-Nonce` | 16–64 chars `[A-Za-z0-9_-]`, único por request |
| `X-Signature` | Base64 da assinatura ECDSA P-256/SHA-256 (DER, RFC 3279) |
| `X-Agent-Version` | SemVer do Agent (informativo) |

String assinada (UTF-8, `\n` separador, sem `\n` final):

```
v1
<METHOD em maiúsculas>
<PATH exato, ex.: /api/agent/v1/heartbeat>
<QUERY ordenada ou vazio>
<X-Timestamp>
<X-Nonce>
<hex minúsculo de SHA-256 do corpo bruto (vazio → hash de string vazia)>
```

Regras de canonicalização (implementadas e testadas; vetor em `tests/contracts/signature-vector.json`):
- `QUERY`: a query string **crua** (sem decodificar) dividida em `&`, ordenada em ordem ordinal de bytes e reunida com `&`; vazia se não houver.
- `PATH`: caminho exato do request (sem host, sem query).
- Corpo: bytes exatamente como enviados (o Agent deve assinar os mesmos bytes que transmite).
- **Um nonce novo a cada tentativa, inclusive em retentativas** (nonce repetido = replay → 401).

Validação no servidor (ordem real implementada): formato dos cabeçalhos → instalação existe → timestamp dentro de ±120 s → assinatura válida com a chave vigente (ou a anterior, durante a janela de rotação) → **só então** nonce inédito (guardado 5 min), estado revogado/desativado e rate limit por instalação. Antes de a assinatura ser comprovada, toda falha devolve a mesma resposta `401 auth.invalid` (sem revelar se a instalação existe ou está revogada); falhas de relógio acrescentam `details.server_time`. As rejeições são registradas em log estruturado; tentativas **autenticadas** de uso após revogação vão para a trilha de auditoria (`agent.revoked_access_attempt`).

`Content-Length` máximo: 1 MiB (inventário: 4 MiB via `Content-Encoding: gzip` opcional, limite aplicado após descompressão).

## 2. Formato de erro

```json
{ "error": { "code": "auth.invalid", "message": "…", "details": {}, "request_id": "uuid" } }
```

| HTTP | code | Significado | Ação do Agent |
|---|---|---|---|
| 400 | `request.malformed` | JSON inválido | descartar, logar |
| 401 | `auth.invalid` | Assinatura/timestamp/nonce inválidos | corrigir relógio; se persistir, alertar |
| 403 | `agent.revoked` | Instalação revogada | **parar** todas as operações, manter apenas serviço ocioso e log |
| 403 | `endpoint.disabled` | Endpoint desativado pelo administrador | ocioso; tentar novamente em 15 min |
| 403 | `enrollment.invalid` | Token inválido/expirado/esgotado/revogado (resposta idêntica para todos) | abortar instalação |
| 404 | `request.not_found` / 405 `request.method_not_allowed` | Rota/método inexistente | corrigir cliente |
| 409 | `task.conflict` | Tentativa/estado divergente | descartar resultado |
| 413 | `payload.too_large` | Corpo acima do limite | reduzir/chunk |
| 422 | `validation.failed` | Schema inválido (`details` por campo) | corrigir; não repetir igual |
| 426 | `agent.upgrade_required` | Versão do protocolo/agente não suportada | atualizar |
| 429 | `rate.limited` | `Retry-After` | respeitar com jitter |
| 503 | `server.unavailable` | Indisponível | backoff exponencial 5 s → 15 min, jitter ±20 % |

Compatibilidade: mudanças aditivas mantêm `v1`; mudanças incompatíveis criam `v2` e `v1` permanece por ≥ 2 versões de Agent. `GET /api/agent/v1/meta` (não autenticado) retorna `{ "protocol":"v1", "min_agent_version":"…", "server_time":"…" }`.

## 3. Endpoints

### POST /enroll  (não assinado; autenticado pelo token)
Request:
```json
{
  "enrollment_token": "tk_…",
  "public_key": "<base64 SubjectPublicKeyInfo DER, ECDSA P-256>",
  "hostname": "PC-001",
  "os": { "name": "Windows 11 Pro", "version": "10.0.26100", "build": "26100", "arch": "x64" },
  "agent_version": "0.1.0",
  "machine_fingerprint": "sha256-hex"   // dado auxiliar, NÃO é identidade
}
```
Resposta `201`:
```json
{
  "agent_id": "uuid",
  "endpoint_id": "uuid",
  "server_time": "2026-10-14T12:00:00Z",
  "config": { "heartbeat_seconds": 60, "metrics_seconds": 60, "inventory_hours": 24, "max_payload_bytes": 1048576 }
}
```
Erros: `403 enrollment.invalid`, `422`, `429`. Rate limit: 10/min por IP.
Reativação/substituição: token emitido com `replaces_endpoint_id` associa a nova instalação ao endpoint existente e revoga a anterior (auditado).

### POST /heartbeat
```json
{ "uptime_seconds": 12345, "service_state": "running", "agent_version": "0.1.0",
  "current_user": "DOMINIO\\usuario" , "ip_addresses": ["10.0.0.5"],
  "pending_events": 0 }
```
Resposta: `{ "server_time": "…", "config_version": 3, "tasks_available": true, "cancel_task_ids": [], "update_available": null }`

### POST /inventory   (idempotente por `snapshot_id`)
Contém seções com estado de coleta (`ok | unavailable | error`) por seção:
```json
{ "snapshot_id": "uuid", "collected_at": "…",
  "sections": {
    "system":   { "status":"ok", "data": { "manufacturer":"", "model":"", "serial":"", "os":{…}, "boot_time":"…", "domain":"", "arch":"x64" } },
    "hardware": { "status":"ok", "data": { "cpus":[…], "memory_total_mb":16384, "disks":[…], "volumes":[…], "gpus":[…], "baseboard":{…}, "bios":{…}, "monitors":[…], "printers":[…] } },
    "software": { "status":"ok", "data": { "items":[ { "name":"", "version":"", "publisher":"", "install_date":"2025-01-01", "arch":"x64", "source":"HKLM64" } ] } },
    "network":  { "status":"ok", "data": { "interfaces":[ { "name":"", "mac":"", "ips":[""], "type":"Ethernet", "up":true } ] } },
    "peripherals": { "status":"unavailable", "reason":"access_denied" }
  } }
```
Resposta `202` `{ "accepted": true, "snapshot_id": "…" }`; repetição do mesmo `snapshot_id` → `200` sem reprocessar.

### POST /metrics   (lote, ≤ 120 amostras)
```json
{ "samples": [ { "sample_id":"uuid", "at":"…", "cpu_percent":12.5, "mem_total_mb":16384, "mem_available_mb":8000,
  "uptime_seconds":1234, "volumes":[{"mount":"C:","total_gb":476,"free_gb":210}], "temperature_c": null,
  "services":[{"name":"Spooler","state":"Running"}] } ] }
```
Idempotência por `sample_id`. Resposta `202 { "accepted": n, "duplicates": m }`.

### POST /events   (lote)
`{ "events":[ { "event_id":"uuid", "at":"…", "level":"error", "source":"System", "code":"7031", "message":"…(≤2 KiB)" } ] }`

### GET /tasks/next?wait=25
Long-poll (≤ 30 s). Faz *claim* atômico de **uma** tarefa disponível compatível. Resposta `200`:
```json
{ "task_id":"uuid","attempt_id":"uuid","type":"service.restart","params":{"name":"Spooler"},
  "timeout_seconds":60,"lease_expires_at":"…","risk":"low","nonce":"…","expires_at":"…" }
```
`204` se nada disponível. Agent valida `type` contra seu catálogo local e `params` contra schema local; tipo desconhecido → resultado `rejected`.

### POST /tasks/{task_id}/progress  (opcional, renova lease)
`{ "attempt_id":"…","percent":40 }`

### POST /tasks/{task_id}/result
```json
{ "attempt_id":"uuid","status":"completed|failed|rejected|cancelled","exit_code":0,
  "stdout":"…(≤16 KiB, truncado)","stderr":"…","truncated":false,"started_at":"…","finished_at":"…","duration_ms":1200,"error":null }
```
`409 task.conflict` se `attempt_id` não for o vigente.

### POST /keys/rotate
`{ "new_public_key":"…" }` assinado com a chave atual; a antiga permanece válida por 5 min.

### GET /updates/check?current=0.1.0  → `{ "available":false }` ou `{ "version":"…","url":"…","sha256":"…","signature":"…","mandatory":false }`

## 4. Esquemas de validação
Implementados em `App\Http\Requests\Api\Agent\*`; contratos espelhados em `Toolit.Agent.Contracts`. Testes de contrato em ambos os lados usam os mesmos JSONs em `tests/contracts/`.

## 5. Limites padrão (configuráveis em `system_settings`)
payload 1 MiB · 120 amostras/lote · heartbeat 60 s · métricas 60 s · inventário 24 h · rate limit 120 req/min por instalação · nonce TTL 5 min.

## 6. Indisponibilidade do servidor
Agent mantém fila local (SQLite, limitada por contagem e bytes; descarte do mais antigo de menor prioridade), reenvia em ordem com backoff exponencial + jitter, e nunca bloqueia coleta. Resultados de tarefas expiradas no servidor são rejeitados com 409 e descartados.
