# Spec — Tarefas remotas tipadas (task 008)

Sub-projeto C da sequência C→D→A→B→E→F→G. Base de 009 (arquivos) e 010 (tela). Contexto: `docs/tasks/008-tarefas-e-comandos-remotos.md`, `docs/api/agent-api-v1.md` §tasks, `docs/permissions-matrix.md`.

## Objetivo
Executar ações administrativas remotas por **tarefas persistidas, tipadas, aprovadas quando arriscadas e auditadas**, sem shell livre.

## Decisões fechadas com o dono do projeto
- **PowerShell fora deste ciclo.**
- **Entrega:** poll curto `GET /tasks/next?wait=0` (~10 s, configurável) + flag `tasks_available` no heartbeat que dispara a busca imediata. Não há long-poll (evita prender 1 worker PHP por agente). Latência esperada ≤ 10 s.

## Escopo
Incluído: catálogo de tipos, máquina de estados, claim atômico com lease, expiração, cancelamento, idempotência, aprovação de alto risco, API do Agent (`next`, `progress`, `result`), telas, handlers do Agent, proteção de processos críticos, auditoria.
Excluído: PowerShell, `files.*` (sub-projeto D), `screen.*` (E), notificações externas.

## Modelo de dados (Manager)
`remote_tasks`: `uuid`, `endpoint_id`, `type`, `params` (JSON), `risk` (low|medium|high), `state`, `created_by`, `approved_by` nullable, `approved_at`, `idempotency_key` (único por `created_by`), `expires_at`, `cancel_requested_at`, `result_summary` (JSON: status, exit_code, duração), timestamps.
`remote_task_attempts`: `uuid`, `remote_task_id`, `number`, `claimed_at`, `lease_expires_at`, `started_at`, `finished_at`, `status`, `exit_code`, `stdout`, `stderr` (≤ 16 KiB cada), `truncated`, `error`.
Índices: `(endpoint_id, state)`, `(state, expires_at)`, `(created_by, idempotency_key)` único.

## Estados e transições
`pending_approval → available` (aprovação) · `available → running` (claim) · `running → completed | failed | cancelled` · `available|pending_approval → cancelled | expired` · `running → available` (lease vencida e tipo idempotente) ou `→ failed` (não idempotente).
Tarefa de baixo/médio risco nasce `available`; alto risco nasce `pending_approval`. Transição inválida lança exceção (testada em tabela).

## Catálogo (`App\Support\Tasks\TaskCatalog`)
| Tipo | Risco | Permissão de criação | Idempotente |
|---|---|---|---|
| `system.info`, `process.list`, `service.list`, `inventory.refresh` | low | `tasks.create` (`processes.view`/`services.view` para os de lista) | sim |
| `service.start`, `service.stop`, `service.restart` (nome na allowlist) | medium | `services.control` | start/stop sim; restart não |
| `service.*` fora da allowlist, `process.kill`, `agent.restart` | high | `tasks.create_high_risk` (+ `processes.kill`/`services.control`) + aprovação por **outro** usuário com `tasks.approve` | não |
Cada entrada: schema dos parâmetros, timeout, nº máximo de tentativas. Allowlist de serviços e lista de serviços críticos em `system_settings`, editáveis só por `settings.manage`.

## API do Agent (assinada, rate limit existente)
- `GET /tasks/next?wait=0` → `200` com a tarefa + attempt (`task_id`, `attempt_id`, `type`, `params`, `timeout_seconds`, `lease_expires_at`, `risk`) ou `204`. O claim reconfere: endpoint ativo, instalação não revogada, estado `available`, não expirada, aprovação presente se alto risco.
- `POST /tasks/{id}/progress` renova a lease do attempt vigente.
- `POST /tasks/{id}/result` aceita só o `attempt_id` vigente (`409 task.conflict` caso contrário); trunca stdout/stderr em 16 KiB; reenvio idêntico do mesmo resultado é idempotente (200).
- Heartbeat passa a devolver `tasks_available` real e `cancel_task_ids` (tarefas `running` com cancelamento pedido).
- A rota GET assinada precisa incluir a query na assinatura: `ManagerClient.Sign` hoje fixa `query: ""`; ambos os lados (`RequestSigner`, `SignatureVerifier`) já suportam query; adicionar vetor de teste com query.

## Painel
- Aba **Tarefas** em `endpoints.show`: formulário por tipo (campos do schema), lista paginada, detalhe com resultado escapado, botão cancelar (`tasks.cancel`).
- Tela **Aprovações** (`/tasks/approvals`) para `tasks.approve`; separação de funções aplicada no serviço (não só na UI).
- `Idempotency-Key` gerado pelo formulário; repetição devolve a mesma tarefa.
- Módulos ainda ausentes (PowerShell, arquivos, tela) não aparecem como opção.

## Auditoria
`task.created`, `task.approved`, `task.rejected`, `task.cancelled`, `task.claimed`, `task.completed|failed|expired` — com endpoint, tipo, risco, operador/aprovador; parâmetros e saída resumidos sem segredos.

## Agent (C#)
`Execution/`: `TaskPoller` (loop com `Backoff`, respeita revogação), `TaskRunner` (concorrência 1, timeout por tipo, cancelamento via `CancellationToken`), `TaskSchemaValidator`, `TaskCatalog` local, `Handlers/*`, `ProtectedProcessList`.
- Tipo ou parâmetro fora do catálogo local → resultado `rejected` sem executar.
- Handlers usam `System.Diagnostics.Process`, `ServiceController` e APIs .NET; nada interpolado em linha de comando.
- `ProtectedProcessList`: `System`, `csrss`, `wininit`, `winlogon`, `lsass`, `services`, `smss`, o próprio Agent e antivírus registrado nunca são encerrados, mesmo com aprovação do Manager.
- Serviços críticos protegidos pela lista recebida/local; `service.*` valida nome existente.

## Tratamento de erros
Endpoint offline → expira; processo/serviço inexistente → `failed` com motivo; timeout → `failed`; Agent reinicia no meio → lease vence (retry só se idempotente); resultado duplicado ou de attempt antigo → 409/200 idempotente; revogação → tarefas abertas do endpoint são canceladas.

## Testes
Manager: tabela de transições; corrida de claim (2 consumidores, 1 vencedor); matriz permissão × risco × aprovação (inclui criador ≠ aprovador e Auditor sem `tasks.create*`); idempotência; anti-replay de resultado; truncamento; isolamento por grupo (BOLA); auditoria; expiração/lease (relógio simulado); contrato JSON compartilhado em `tests/contracts`.
Agent: validador de schema; `ProtectedProcessList` (`lsass` rejeitado); truncamento; handlers de leitura reais; `process.kill`/`service.*` só contra processo/serviço descartável criado pelo teste (nunca serviços reais da máquina); cancelamento.
E2E: Agent real contra Manager local para uma tarefa read-only (critério de aceite). Registrar evidências em `docs/test-evidence.md`.

## Aceite (da task 008)
Tarefa read-only ponta a ponta com agente real · reinício de serviço autorizado com resultado (em serviço descartável; serviço real do Windows só em VM — não validado aqui) · tentativa de matar `lsass` rejeitada · duplicação impossível por teste de concorrência · tudo auditado.

## Documentação a atualizar
`docs/api/task-types.md` (novo), `agent-api-v1.md`, `data-model.md`, `permissions-matrix.md`, `decisions.md` (ADR-021: poll curto + estados), `known-limitations.md`, `status.md`, task 008 e `test-evidence.md`.

## Limitações previstas (registrar)
Latência de entrega de até ~10 s; handlers que alteram serviços reais não validados em máquina de produção; sem notificação externa de aprovação pendente.
