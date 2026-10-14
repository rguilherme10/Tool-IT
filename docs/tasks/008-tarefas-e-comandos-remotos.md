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
Long-poll `GET /tasks/next` no contrato; sem tabelas de tarefas.

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
- [ ] Tarefa read-only ponta a ponta com agente real - [ ] Reinício de serviço autorizado com resultado - [ ] Tentativa de matar `lsass` rejeitada - [ ] Duplicação impossível por teste de concorrência - [ ] Tudo auditado

## Documentação
`api/task-types.md`, matriz de permissões, manual do administrador.

## Riscos
Handlers que alteram o sistema precisam de máquina de teste isolada.

## Checklist
- [ ] Estados - [ ] Claim - [ ] Handlers - [ ] Aprovação - [ ] UI - [ ] PowerShell (separado) - [ ] Testes - [ ] Docs
