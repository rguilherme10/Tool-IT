# Catálogo de tipos de tarefa remota (task 008)

Fonte da verdade: `App\Support\Tasks\TaskCatalog` (Manager) e `AgentToolit.Core.Execution.LocalTaskCatalog` + `TaskSchemaValidator` (Agent, espelho). Só o que está aqui pode ser criado; o Agent recusa (`rejected`) qualquer tipo ou parâmetro fora do catálogo local, mesmo que o Manager tenha aprovado. Não existe shell, PowerShell, arquivos nem tela neste ciclo.

## Tipos

| Tipo | Risco | Permissões para criar (todas) | Idempotente / tentativas | Timeout | Parâmetros |
|---|---|---|---|---|---|
| `system.info` | low | `tasks.create` | sim / 3 | 30 s | nenhum (`{}`) |
| `process.list` | low | `tasks.create` + `processes.view` | sim / 3 | 30 s | nenhum |
| `service.list` | low | `tasks.create` + `services.view` | sim / 3 | 30 s | nenhum |
| `inventory.refresh` | low | `tasks.create` | sim / 3 | 120 s | nenhum |
| `service.start` | medium (nome na allowlist) · high (fora) | `services.control` + `tasks.create` (medium) ou `tasks.create_high_risk` (high) | sim / 3 | 60 s | `name` |
| `service.stop` | idem | idem | sim / 3 | 60 s | `name` |
| `service.restart` | idem | idem | **não** / 1 | 60 s | `name` |
| `process.kill` | high | `tasks.create_high_risk` + `processes.kill` | **não** / 1 | 15 s | `pid`, `name` |
| `agent.restart` | high | `tasks.create_high_risk` | **não** / 1 | 30 s | nenhum |

Alto risco nasce `pending_approval` e só vai para `available` com aprovação de **outro** usuário com `tasks.approve` (separação de funções aplicada no `TaskService`, não só na tela). Low/medium nascem `available`.

## Schema dos parâmetros (estrito nos dois lados)
- Nenhuma chave extra, nenhuma chave duplicada, tipos exatos (sem coerção de string para número).
- `name` de serviço: `^[A-Za-z0-9_.$-]{1,128}\z` (nome do serviço, nunca o de exibição). `service.stop`/`service.restart` de serviço protegido são recusados já na criação (422) e de novo no Agent.
- `pid`: inteiro JSON ≥ 5 (0–4 são do sistema). `name` do processo: 1–128 caracteres, sem caracteres de controle; o Agent exige que o processo vivo naquele PID tenha esse nome (com ou sem `.exe`, sem diferenciar maiúsculas) — proteção contra reutilização de PID.
- No painel, o PID digitado (texto só com dígitos) vira inteiro; qualquer outra coisa segue como veio e é recusada pelo catálogo. Observação: o middleware `TrimStrings` do Laravel apara espaços/quebras de linha nas pontas dos campos do formulário antes da validação (`"Spooler\n"` vira `"Spooler"`); pela API interna (`TaskService`) o mesmo valor é recusado.

## Listas de serviços
- Allowlist (medium): padrão `Spooler`, `W32Time`, `BITS`, `wuauserv` (`config('toolit.tasks.service_allowlist')`), sobreposta por `system_settings.tasks.service_allowlist`.
- Protegidos (nunca parar/reiniciar): `RpcSs`, `DcomLaunch`, `LSM`, `SamSs`, `EventLog`, `BFE`, `mpssvc`, `WinDefend`, `CryptSvc`, `Winmgmt`, `ToolitAgent` (`toolit.tasks.service_protected` / `system_settings.tasks.service_protected`). O Agent tem a **mesma lista fixa** no código; mudar a lista no Manager não altera a do Agent.
- Ainda não há tela para editar essas listas (só `system_settings`/config) — L33.

## Processos protegidos (Agent; nunca encerrados, mesmo aprovados)
`System`, `Idle`/pseudo-processos do kernel, `csrss`, `wininit`, `winlogon`, `lsass`, `services`, `smss`, o próprio Agent, Microsoft Defender e o antivírus registrado no `SecurityCenter2` (ausente no Windows Server → só a lista fixa). Além disso o Agent consulta `IsProcessCritical` e **recusa** se o processo for crítico ou se não conseguir verificar. A checagem pelo nome pedido vem antes de olhar o PID: `{pid: X, name: "lsass"}` é `rejected` (`protected process`) sem tocar no processo X.

## Saída (stdout, ≤ 16 KiB)
| Tipo | Conteúdo |
|---|---|
| `system.info` | JSON: hostname, SO (descrição, versão, arquitetura), uptime, nº de processadores, runtime, PID do Agent, `collected_at` |
| `process.list` | JSON `{processes:[{pid,name,working_set_mb,cpu_seconds,started_at}], total, truncated}` — sem caminho do executável; mantém o que cabe em 16 KiB (E2E: 148 de 425) |
| `service.list` | JSON `{services:[{name,display_name,state,start_type}], total, truncated}` — em execução primeiro; corta no limite |
| `service.*` | texto `Serviço X iniciado/parado/reiniciado.` ou `failed` com o motivo (ex.: acesso negado, serviço inexistente) |
| `process.kill` | texto `Processo X (PID n) encerrado.`; `rejected` com `protected process`, `pid reuse: …` ou falha de verificação de criticidade; `failed` com `process not found` |
| `inventory.refresh` | confirmação; o inventário vai pelo envio normal (`POST /inventory`) |
| `agent.restart` | envia o resultado e só então encerra o host com código 75 para a recuperação do serviço reiniciá-lo (**não validado** em serviço real — L8, L36) |

O campo `truncated` do attempt indica corte de bytes pelo Manager/Agent; o `truncated` dentro do JSON de listas indica itens omitidos.

## Resultado → estado da tarefa
`completed` → `completed`; `failed` e `rejected` → `failed` (o `error` guarda o motivo); `cancelled` → `cancelled`. Lease vencida: tipo idempotente com tentativas restantes volta a `available` (`task.requeued`); senão `failed` (`lease_expired`). Contrato HTTP: [`agent-api-v1.md`](agent-api-v1.md) §tasks.
