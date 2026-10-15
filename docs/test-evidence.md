# Evidências de testes

Cada linha é um resultado **observado** (2026-10-14). Ambiente: Windows 11 Home Single Language 10.0.26300, **sessão sem privilégios de Administrador**, PHP 8.4.1, Composer 2.8.10, Laravel 13, PHPUnit 12 (SQLite em memória), .NET SDK 9.0.308 compilando `net8.0`, 12 CPUs lógicas, 36 GB de RAM. **Sem MySQL, Redis ou Laragon.**

## Fase 2 — Agent + Manager

| Teste | Comando | Resultado |
|---|---|---|
| Suíte do Manager | `cd manager && php artisan test` | **278 aprovados / 0 falhas** (1092 asserções, ≈ 41 s) |
| Suíte do Agent | `cd agent/Toolit && dotnet test Toolit.sln` | **112 aprovados / 0 falhas** (inclui 6 testes de leitura real do Windows e os do instalador de serviço) |
| Vetor de contrato PHP↔C# | `SignatureContractTests` + `ContractVectorTest` | String canônica idêntica byte a byte; **assinatura gerada pelo OpenSSL do PHP verificada no .NET** |
| Vulnerabilidades PHP | `composer audit` | nenhuma |
| Vulnerabilidades .NET | `dotnet list package --vulnerable --include-transitive --source https://api.nuget.org/v3/index.json` | **nenhuma** após trocar o SQLite embutido pelo `winsqlite3` e fixar 2 pacotes transitivos de teste (antes: 3 avisos *High*). A fonte NuGet corporativa do host (`nexus.tce.go.gov.br`) é inacessível — usar a fonte pública para o scan. |
| Estilo | `vendor/bin/pint` | aplicado |

### Teste ponta a ponta com o executável real (sem simulação)
Manager (`php artisan serve` + SQLite temporário) e o `AgentToolit.exe` **publicado** (`dotnet publish -c Release`), modo console, intervalos de 15 s:

| Verificação | Resultado |
|---|---|
| `enroll` com token real | Registrado; segundo uso do mesmo token → recusado; HTTP não-loopback → recusado; segundo `enroll` sem `--force` → recusado |
| Segredos em disco | token: **0 ocorrências** nos arquivos do Agent; `identity.key` é blob DPAPI (cabeçalho `01 00 00 00 D0 8C 9D DF`) |
| Inventário recebido | 5/5 seções `ok`; 109 softwares; 9 interfaces; hardware (CPU, 36 565 MB, 2 volumes), `not_detected` vazio; fabricante/modelo/serial/SO preenchidos no endpoint |
| Métricas | amostras chegando a cada ciclo; CPU, memória e volumes coerentes com a máquina |
| **Queda do Manager (≈ 55 s)** | Agent continuou coletando; log mostra backoff 17 s → 41 s e fila 2 → 4 itens; ao religar, tudo entregue: **11 amostras, 0 lacunas > 25 s, 0 duplicatas** |
| **Revogação com o Agent rodando** | Agent suspendeu coleta e envio em ≈ 1 s, **preservou a fila**, gravou `Revogado=true` (sobrevive a reinício); o Manager não aceitou mais nenhuma amostra (11 → 11 em 40 s); auditoria registrou `agent.revoked` e `agent.revoked_access_attempt`; `audit:verify` íntegro (8 registros) |
| Reinstalação (`enroll --force`) | Nova identidade; fila antiga descartada (não é atribuída à nova instalação) |
| Pacote final sem SQLite embutido | publicação caiu de 28 MB para 3,7 MB; fila funciona com o `winsqlite3` do sistema |

### Consumo de recursos medido (processo real, operação normal: heartbeat/métricas 60 s, inventário no início)
Janela de 120 s em 12 CPUs lógicas: **CPU total 1,36 s** ⇒ média **0,09 % da máquina (1,1 % de um núcleo)**; **working set 66–68 MB**, memória privada ≈ 18 MB, 15 threads, 509 handles. A maior parte da CPU ocorre na coleta inicial do inventário (~1 s). **Não é uma promessa de capacidade:** medido em uma única máquina de desenvolvimento; falta medir em PCs modestos e sob carga (task 013).
Coleta isolada (esta máquina): inventário completo ≈ 1 s e 19 KiB; software (109 itens) ≈ 21 ms; amostra de métricas ≈ 513 ms na primeira (500 ms são a espera deliberada para ter dois pontos de CPU); as seguintes não foram cronometradas isoladamente.

### Bugs reais encontrados pelos testes e corrigidos nesta fase
1. `enroll` aplicava a ACL restrita (SYSTEM/Administradores) a um usuário comum e o **trancava fora do próprio diretório** depois de o token ser consumido → ACL só quando elevado; teste de escrita **antes** de gastar o token; mensagem de recuperação com `agent_id` se o estado local falhar depois do registro.
2. Item corrompido na fila derrubaria o envio com `JsonException` → `TryDeserialize` descarta o item.
3. Backoff inicial (5 s) acelerava as tentativas abaixo do intervalo normal (10 s) → piso no intervalo.
4. `enroll --force` reaproveitaria a fila da instalação anterior → fila limpa no registro.
5. Seção de auditoria do detalhe do endpoint não aparecia (merge de arrays) — pego pelo teste de isolamento.
6. SQLite nativo embutido com aviso de segurança alto → `winsqlite3` do sistema.

### Não validado (honestamente)
- **Serviço Windows real**: `sc create`, início automático, recuperação após falha, reinício do PC, instalação/atualização/desinstalação com o script. A sessão não é Administrador; o script só foi validado em `-DryRun` e nas guardas (HTTPS obrigatório, payload ausente, exige elevação).
- MySQL 8 / MariaDB e Redis (migrations, coluna gerada, triggers `SIGNAL`, `ESCAPE '!'`, filas).
- Concorrência real entre processos (token, snapshot).
- Windows 10, ARM64, PCs sem WMI/privilégios, máquinas com 1000+ softwares ou sem usuário conectado.
- Navegador real (layout das novas abas, SVG do gráfico) — apenas HTML verificado por testes.
- HTTPS com CA corporativa (os testes usaram `http://127.0.0.1`, permitido só em loopback).

## Fase 1 — Manager (histórico)
229 testes aprovados na época (RBAC, auditoria, API do Agent, web); smoke HTTP 7/7; `composer audit`/`npm audit` limpos; `audit:verify` íntegro. Hoje coberto pelos 278 testes acima.

## Instalador (ToolitSetup + serviço) — 2026-10-14
Sessão **sem Administrador**. Tudo abaixo foi observado; a instalação real do serviço **não** foi executada.

| Verificação | Resultado |
|---|---|
| Agent: `service install/uninstall` | 12 testes novos (sequência de `sc.exe`, `create` vs `config`, tolerância a 1056/1062/1060, falha de passo, timeout até `RUNNING`, validação do caminho, desinstalação); `sc.exe query` **real** de serviço inexistente detectado sem Administrador. Suíte do Agent: **112 aprovados / 0 falhas** |
| Guardas do comando no `.exe` publicado | sem Administrador → código 3; sem token / token fora do formato `tk_…` (`abc; calc.exe`) → código 2; verbo desconhecido → 2; `--dry-run` lista os comandos `sc.exe` sem executar; o token **não** aparece em `install.log` |
| Build do MSI headless (`devenv.com … /Project ToolitSetup\ToolitSetup.vdproj`) | 4 projetos OK; evento pós-build `Patch-ToolitMsi: OK (8 ações, diálogo com 12 controles)`; `ToolitSetup.msi` ≈ 33 MB |
| Tabelas do MSI final (lidas por script independente) | `Template=x64;1046`; `TARGETDIR=[ProgramFiles64Folder][Manufacturer]\[ProductName]`; 229 arquivos (runtime autossuficiente, `winsqlite3`); condição “.NET instalado” removida; 4 pares de ações (imediata + deferida/rollback) com tipos 3170/3362/3106 e sequências 3450/3950/4050/4100; fluxo Folder → **ToolitConfigForm** → Confirm; `SecureCustomProperties` e `MsiHiddenProperties` com o token; tabela Upgrade intacta |
| Idempotência do patch | executado 2× sobre o mesmo MSI → mesmo resultado, verificação interna OK |
| Extração real do pacote (`msiexec /a … /qn`, sem elevação) | código 0; 230 arquivos; o `AgentToolit.exe` extraído roda (`--version` = 0.1.0) **sem .NET instalado como dependência** e executa `service install --dry-run` |
| Varredura de pacotes .NET | nenhum pacote vulnerável nos 4 projetos (a solução não pode mais ser varrida de uma vez por causa do `.vdproj`: L18) |

### Não validado
`msiexec /i` de verdade (UI e silencioso), criação/início do serviço pelo SCM, recuperação automática após falha, reinício do PC, atualização para versão maior, desinstalação, rollback, GPO/`.mst`, comportamento do enroll sob a conta SYSTEM, e a aparência real do diálogo (só as tabelas foram inspecionadas).
