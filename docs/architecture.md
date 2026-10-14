# Arquitetura

Estado: Fase 1 em implementação. Este documento descreve o **alvo**; o que está de fato implementado e testado consta em [`docs/status.md`](status.md).

## 1. Visão geral

```
┌────────────── Navegador do operador ──────────────┐
│  Blade + Livewire/Alpine (sessão web, CSRF, MFA)  │
└───────────────┬───────────────────────────────────┘
                │ HTTPS (cookie de sessão)
┌───────────────▼───────────────────────────────────┐
│ Manager (Laravel 13, PHP 8.3+)                    │
│  web/        painel administrativo                │
│  api/agent/v1  API do Agent (assinada por request)│
│  filas (database) · scheduler · Reverb (WS)       │
│  MySQL 8.0+ (Laragon no Windows)                  │
└───────────────▲───────────────────────────────────┘
                │ HTTPS, iniciado SEMPRE pelo Agent
┌───────────────┴───────────────────────────────────┐
│ Endpoint Windows                                  │
│  Agent Service (C#, .NET LTS, Windows Service)    │
│    ├─ coleta (Registro/WMI limitado/PerfCounters) │
│    ├─ cliente HTTPS + fila local (SQLite)         │
│    ├─ executor de tarefas tipadas                 │
│    └─ IPC (named pipe c/ ACL) ──► Screen Capture  │
│                                    Helper (sessão │
│                                    interativa)    │
└───────────────────────────────────────────────────┘
```

Princípios:

1. **Agent inicia toda conexão** (polling longo + WebSocket opcional). Nenhuma porta de entrada nos endpoints.
2. **Tarefas tipadas**, nunca shell livre. PowerShell é módulo separado, desligado por padrão.
3. **Autorização sempre no servidor** (Gate/Policy + escopo por grupo de endpoints). A UI só reflete.
4. **Tudo que muda estado gera auditoria** encadeada por hash.
5. **Estado atual separado de histórico**; histórico com retenção e agregação.

## 2. Componentes do Manager

| Camada | Local | Responsabilidade |
|---|---|---|
| HTTP web | `app/Http/Controllers/Web` | Telas; finos, delegam a serviços |
| HTTP API Agent | `app/Http/Controllers/Api/Agent/V1` | Endpoints do protocolo do Agent |
| Middleware | `app/Http/Middleware` | `VerifyAgentSignature`, rate limit, `EnsureMfa` |
| Domínio/serviços | `app/Services/*` | Enrollment, Inventário, Tarefas, Transferências, Sessões, Alertas, Auditoria |
| Autorização | `app/Policies`, `app/Support/Authorization` | RBAC com escopo por grupo |
| Persistência | `app/Models`, `database/migrations` | Eloquent + constraints |
| Jobs | `app/Jobs` | Expiração de tarefas, retenção, avaliação de alertas |

## 3. Componentes do Agent (C#)

Solução `agent/Toolit/Toolit.sln` (ADR-014):

- `Toolit/` (`AgentToolit.csproj`) — host Worker Service (`UseWindowsService`), comandos `enroll`, `status`, `rotate-key`, workers.
- `AgentToolit.Core` — protocolo (assinatura, DTOs), identidade (ECDSA + DPAPI), `ManagerClient`, fila SQLite, logs com redação, `HeartbeatService`, `SenderService`.
- `AgentToolit.Collectors` — coletores de inventário (sistema, hardware, software, rede, periféricos) e de métricas.
- `AgentToolit.Tests` — xUnit (inclui leituras reais do Windows e o vetor de contrato com o Manager).
- Planejado, não implementado: executor de tarefas (008), `CaptureHelper` (010), atualizador (012).

## 4. Identidade e autenticação do Agent (resumo; ver ADR-004)

1. Operador gera um **token de enrollment** (uso único, expira, hash no banco, vinculado a grupo de endpoints).
2. Instalação gera par de chaves **ECDSA P-256**; chave privada protegida por DPAPI (escopo máquina) e **nunca sai do equipamento**.
3. `POST /api/agent/v1/enroll` envia token + chave pública + dados básicos. O Manager cria `Endpoint` + `AgentInstallation` e consome o token.
4. Toda chamada posterior é **assinada** (método, path, timestamp, nonce, hash do corpo). Sem bearer secret trafegando; proteção de replay por janela de tempo + nonce.
5. Revogação = `agent_installations.revoked_at`; efeito imediato (chaves são consultadas a cada request).
6. Rotação de chave: `POST /keys/rotate` assinado com a chave antiga.
7. Substituição de equipamento: nova instalação com novo enrollment; vínculo ao `Endpoint` existente por reativação explícita do operador (não por hostname/MAC).

Detalhes do contrato: [`docs/api/agent-api-v1.md`](api/agent-api-v1.md).

## 5. Fluxo de tarefas

`pending → available → running → completed | failed | cancelled | expired`

- Criação: valida permissão + política + parâmetros tipados; grava `idempotency_key` única por operador.
- Entrega: Agent chama `GET /tasks/next`; o Manager faz *claim* atômico (`UPDATE ... WHERE status='available'`) e retorna `lease_expires_at`.
- Execução duplicada evitada por claim atômico + `attempt_id`; resultado só aceito para a tentativa vigente.
- Timeout: lease expirada devolve a `available` (até `max_attempts`) ou `failed`.
- Cancelamento: flag consultada no heartbeat/long-poll; Agent cancela via `CancellationToken`.

## 6. Suporte remoto de tela (somente leitura)

**Restrição crítica: Session 0.** Serviços Windows rodam na Session 0, isolada das sessões interativas; um serviço não captura a área de trabalho do usuário. Solução: o serviço (SYSTEM) usa `WTSGetActiveConsoleSessionId` + `WTSQueryUserToken` + `CreateProcessAsUser` para iniciar o **CaptureHelper** na sessão do usuário, com token restrito. O Helper só captura e devolve quadros por **named pipe** com ACL (somente SYSTEM + SID do serviço) e não aceita comandos além de `start/stop/set-quality`.

Estratégias comparadas (decisão em ADR-008):

| | Capturas periódicas (JPEG a 0,5–2 fps) | Transmissão contínua (≥10 fps) |
|---|---|---|
| Custo de implementação | Baixo: GDI/`Windows.Graphics.Capture` + JPEG; HTTP/WS simples | Alto: Desktop Duplication/WGC + codec (H.264/VP8) ou deltas, controle de congestionamento |
| CPU/rede no endpoint | Baixo (≈ dezenas de KB/s) | Médio/alto (centenas de KB/s a Mb/s) |
| Latência percebida | 0,5–2 s | <200 ms |
| Telas protegidas (UAC/Winlogon) | Não capturáveis do helper de usuário; documentado | Idem |
| Adequação para 100 endpoints, suporte pontual | **Alta** | Desnecessária inicialmente |

Escolha inicial: **capturas periódicas** via WebSocket (Reverb) com qualidade/FPS/resolução ajustáveis; arquitetura permite trocar o codec depois. Sem teclado/mouse. Sem gravação. Valores medidos serão registrados em `docs/test-evidence.md`.

O módulo **só inicia após** a prova de conceito das sessões (task 010) validar bloqueio, múltiplos usuários e RDP.

## 7. Tempo real

Laravel Reverb para (a) atualizar o painel e (b) relay de quadros de tela. Canais privados autorizados por endpoint/operador, tokens de sessão de curta duração (≤5 min, renovável), limite de conexões por usuário. Fallback: polling HTTP no painel.

## 8. Dados e retenção (resumo; ver `data-model.md`)

- Estado atual: `endpoints`, `hardware_inventory`, `software_inventory`, `network_interfaces` (upsert por snapshot).
- Histórico: `metric_samples` (raw 30 s–60 s por 7 dias → agregados horários por 13 meses), `audit_logs` (retenção configurável, padrão 24 meses), `process_snapshots` somente sob demanda (TTL curto, não coletado periodicamente).
- Jobs diários aplicam retenção.

## 9. Infra

- Servidor: Windows com **Laragon** (Apache/Nginx + PHP 8.3+ + MySQL 8), `queue:work` e `reverb:start` como processos persistentes (NSSM/Agendador de Tarefas), `schedule:run` a cada minuto (ADR-013). Linux continua possível, pois nada no código depende do SO.
- Redis (Laragon) para fila, cache, sessão, nonces e rate limit em produção (ADR-003); em dev/testes neste host usa-se `database`/`array`. Memcached não é utilizado.
- TLS interno: CA corporativa (ou ACME interno) com o certificado do Manager; Agent faz *pinning* opcional da CA.
- Ambiente de desenvolvimento atual: SQLite (testes) — MySQL/Laragon não está instalado neste host; ver limitações.

## 10. Estrutura do repositório

```
manager/    Laravel
agent/      Solução .NET
docs/       arquitetura, API, segurança, tarefas
deploy/     scripts/configs do servidor
installer/  empacotamento do Agent
tests/      integração ponta a ponta
```
