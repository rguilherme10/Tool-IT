# 008 — Tarefas remotas, processos e serviços

## Objetivo e contexto
Execução remota por tarefas persistidas, tipadas e rastreáveis, incluindo consulta/controle de processos e serviços.

## Problema
Dar capacidade administrativa sem criar um shell aberto.

## Escopo
Incluído: catálogo de tipos (`system.info`, `process.list`, `process.kill`, `service.list`, `service.start|stop|restart`, `inventory.refresh`, `agent.restart`, `files.*` na 009), máquina de estados, claim atômico, lease/timeout, retries, cancelamento, idempotência, aprovação para alto risco, módulo PowerShell **separado e desligado por padrão**. Excluído: shell interativo.

## Dependências
004, 005.

## Análise da arquitetura atual
Antes desta tarefa: long-poll `GET /tasks/next` no contrato e nenhuma tabela. **Implementado (2026-10-15):** poll curto `wait=0` (ADR-021), `remote_tasks`/`remote_task_attempts`, `TaskService`, catálogo nos dois lados, API do Agent, painel e handlers no Agent. PowerShell fora deste ciclo.

## Arquivos
Manager: `RemoteTask`, `RemoteTaskAttempt`, `TaskCatalog` (schema por tipo), `TaskService`, `TaskPolicy`, `TaskController`, `TasksApiController`, `ExpireTasksJob`. Agent: `Execution/{TaskRunner,Handlers/*,TaskSchemaValidator}`, `ProtectedProcessList`.

## Requisitos funcionais
Estados: pending → available → running → completed|failed|cancelled|expired. Campos conforme enunciado §7. Resultado: stdout/stderr limitados (16 KiB), exit_code, duração. Processos: nome, PID, caminho, usuário, CPU/mem, início. Serviços: estado, start type.

## Não funcionais
Concorrência por endpoint = 1 (configurável ≤3); timeout por tipo; limite global de tarefas/min.

## Regras
- Risco `low` (leitura), `medium` (reiniciar serviço allowlist), `high` (matar processo, serviços fora da allowlist, PowerShell) ⇒ exige `tasks.create_high_risk` e **aprovação por outro usuário** (`tasks.approve`).
- Processos críticos (`System`, `csrss`, `wininit`, `winlogon`, `lsass`, `services`, `smss`, o próprio Agent, antivírus registrado) nunca podem ser encerrados; serviços críticos protegidos por lista editável só por Admin.
- Idempotência: `Idempotency-Key` por operador; repetição devolve a mesma tarefa.
- Validação dupla: Manager (schema) e Agent (schema + allowlist local + política).

## Contratos
`agent-api-v1.md` §tasks; catálogo de tipos em `docs/api/task-types.md` (criar nesta tarefa).

## Segurança
Operador, política e estado do recurso verificados na criação **e** no claim; resultado só da tentativa vigente (anti-replay); parâmetros nunca interpolados em linha de comando (usar APIs .NET/`ServiceController`, não `cmd`); PowerShell: script armazenado, hash, aprovação, `ConstrainedLanguage` quando possível, auditoria integral.

## Estratégia
1. Modelo/estado e testes de transição. 2. Claim/lease/expiração. 3. Handlers read-only. 4. Handlers de controle. 5. Aprovação. 6. UI. 7. PowerShell (opcional, após revisão de segurança).

## Erros
Endpoint offline (expira), processo já encerrado, serviço inexistente/protegido, timeout, agente reinicia no meio (lease expira → retry se idempotente), resultado duplicado.

## Testes
Máquina de estados, corrida de claim (dois consumidores), idempotência, permissão×risco×aprovação (matriz), proteção de processos críticos (Agent), cancelamento, truncamento de saída, auditoria.

## Aceite
Evidências: `docs/test-evidence.md` §“Tarefas remotas — E2E com Agent real (2026-10-15)”.
- [x] Tarefa read-only ponta a ponta com agente real (`system.info`, `process.list`, `service.list`, `inventory.refresh`: `completed` com saída real, 2–11 s)
- [ ] Reinício de serviço autorizado com resultado — **não validado**: só *fakes* (testes do Agent); serviço real do Windows exige Administrador/VM (L32). Encerrar processo aprovado por outro Admin foi validado em processo descartável
- [x] Tentativa de matar `lsass` rejeitada pelo nome na lista de protegidos (E2E com PID descartável: `{pid: <ping descartável>, name: "lsass"}` → `rejected`, processo intacto; também por testes de unidade)
- [ ] Tentativa de matar `lsass` pelo PID real do `lsass` numa VM (não executado)
- [ ] Duplicação impossível por teste de concorrência — provado sequencialmente em SQLite (claim condicional; no E2E a mesma tarefa não foi entregue duas vezes); `[ ] concorrência real do claim em MySQL` (L30, task 013)
- [x] Tudo auditado (`task.created|approved|claimed|completed|failed|cancelled`; `audit:verify` íntegro, 65 registros)

## Documentação
`api/task-types.md`, matriz de permissões, manual do administrador.

## Riscos
Handlers que alteram o sistema precisam de máquina de teste isolada.

## Checklist
- [x] Estados (tabela de transições + E2E)
- [x] Claim (UPDATE condicional, lease, expiração/requeue; E2E com Agent real)
- [x] Handlers de leitura (`system.info`, `process.list`, `service.list`, `inventory.refresh`) — E2E real
- [x] `process.kill` com proteção (lista protegida, guarda de PID reutilizado, processo crítico) — E2E em processo descartável
- [ ] Handlers de serviço (`service.start|stop|restart`) e `agent.restart` em serviço real — só *fakes* (L32, L36)
- [ ] reinício de serviço real do Windows (sem VM/Administrador)
- [x] Aprovação com separação de funções (criador não aprova; operador não aprova; outro Admin aprova) — testes + E2E
- [x] UI (aba Tarefas, Aprovações, detalhe) — testes + navegador embutido com ressalva do CSS (L38)
- [ ] PowerShell (separado) — fora deste ciclo
- [ ] concorrência real do claim em MySQL (L30)
- [x] Testes (Manager e Agent; ver `test-evidence.md`)
- [x] Docs (`api/task-types.md`, `agent-api-v1.md` §tasks, ADR-021, modelo de dados, matriz, limitações L29–L41)
