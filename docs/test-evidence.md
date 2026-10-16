# Evidências de testes

Cada linha é um resultado **observado** (2026-10-14). Ambiente: Windows 11 Home Single Language 10.0.26300, **sessão sem privilégios de Administrador**, PHP 8.4.1, Composer 2.8.10, Laravel 13, PHPUnit 12 (SQLite em memória), .NET SDK 9.0.308 compilando `net8.0`, 12 CPUs lógicas, 36 GB de RAM. **Sem MySQL, Redis ou Laragon.**

## Manager — histórico de mudanças do inventário (2026-10-15)

| Teste | Comando | Resultado |
|---|---|---|
| Suíte do Manager | `cd manager && php artisan test` | **395 aprovados / 2 falhas** (1721 asserções; depois de fixar `APP_URL` no `phpunit.xml`: **397 aprovados / 0 falhas**), SQLite em memória. As 2 falhas (`TrustedProxiesTest` "ignored from anyone else" e `HeadersTest` "security headers are present") já ocorriam **antes** destas mudanças (baseline do mesmo dia: 375 aprovados / 2 falhas) e **não** foram investigadas — ver L28 |
| Diff do inventário | `tests/Unit/InventoryDiffTest` (10) | ignora espaço livre/uso, IP e boot; memória/disco/GPU adicionados, removidos e trocados; teto de 100 entradas |
| Ingestão | `InventoryIngestTest` (+4 novos) | `changes` com diff de memória/SO/rede, nomes de software (teto 50), `initial` na primeira coleta, `changes` nulo sem mudança, seção indisponível não gera diff. Um bug real foi pego pelo teste: nomes saíam como objeto JSON (chaveados por hash) |
| Aba Histórico | `EndpointTabsTest` (+5) | estado vazio, dados reais, escape de HTML, perfil Consulta não vê software/rede, escopo por grupo (404), paginação |
| Estilo | `vendor/bin/pint --test app tests` | limpo |
| **Não verificado** | — | aba Histórico não foi vista no navegador; MySQL (L1) não exercitado; nenhum teste com um Agent real |

## Manager — alertas e administração pela interface (2026-10-14)

| Teste | Comando | Resultado |
|---|---|---|
| Suíte do Manager | `cd manager && php artisan test` | **375 aprovados / 0 falhas** (1620 asserções), SQLite em memória |
| Estilo / vulnerabilidades | `vendor/bin/pint`; `composer audit` | aplicado; nenhuma vulnerabilidade |
| Avaliador de alertas | `AlertEvaluationTest` (24) + `SustainedConditionTest` | duração mínima, histerese, repetição, reconhecimento, resolução, dedupe, escopo, endpoint desativado/removido |
| Telas | `AlertsUiTest`, `AlertRulesUiTest`, `GroupsUiTest`, `UsersUiTest`, `AuditUiTest`, `EndpointManagementTest` | 403/404 por papel e escopo, validação, auditoria dos efeitos, escape de HTML, filtros server-side |
| **E2E com Agent real** (`AgentToolit.exe` publicado + `schedule:work`) | execução manual | alerta de memória abriu **2 min 05 s** após a 1ª amostra (regra de 2 min); alerta *agente offline* abriu **77 s** após o último contato e **resolveu sozinho 47 s** após o retorno; cadeia de auditoria íntegra; reconhecimento pela UI via curl (login + CSRF: 419 sem token, 302 com) |

### Bugs reais encontrados e corrigidos
1. **E2E:** regras curtas nunca abriam — a janela tinha só ~30 s de dados quando a duração era alcançada. `SustainedCondition` passou a tolerar `min(lacuna, duração/4)` no início da janela + testes de regressão.
2. **E2E:** a variável `DB_DATABASE` exportada no shell vazou para o PHPUnit e apagou o banco do E2E → `force="true"` no `phpunit.xml` e recusa no `TestCase` se o banco não for o de teste (verificado).
3. Título de alerta offline "há 1 min (limite 1 min)" → exibe segundos quando < 120 s.
4. **Criação de usuário:** e-mail igual a um existente diferindo só em maiúsculas passava na validação e causava erro 500 (SQLite diferencia maiúsculas; MySQL não) → e-mail normalizado antes de validar.
5. Rotas das telas administrativas sem `use` dos controladores (erro 500 em todos os testes de tela).

## Implantação em Docker — o que foi e o que NÃO foi verificado (2026-10-14)
**Verificado:** `sh -n` nos 3 scripts (entrypoint, init do MySQL, certificado); `compose.yaml` lido por um parser YAML (7 serviços, âncoras/merges resolvidos: `migrate` usa `DB_MIGRATE_*` e a aplicação `DB_*`; só o `web` publica portas); `make-selfsigned.sh` executado de verdade (gera EC P-256 com SAN; no Git Bash do Windows precisa de `MSYS_NO_PATHCONV=1`, que converte `/CN=` — no Linux não); `composer check-platform-reqs --no-dev` lista só extensões já presentes na imagem PHP; `TrustedProxiesTest` (2 testes: cabeçalhos X-Forwarded-* honrados só de proxy confiável); suíte do Manager completa (377 aprovados).
**NÃO verificado:** `docker build` (nenhum Docker disponível), `docker compose up`, `nginx -t`, init do MySQL, *healthchecks*, criação de *triggers* com o usuário de migração, MySQL e Redis reais. Ver L25.

### Revisão visual no navegador (2026-10-14)
Servidor `php artisan serve` com banco SQLite **separado e descartável** (4 computadores, 3 usuários, grupos em árvore, 3 alertas gerados pelo avaliador real; nada disso é dado do projeto). Login com senha + TOTP, depois painel, alertas, regras, grupos, usuários (lista e edição), auditoria (com "Verificar integridade da cadeia": *"Cadeia íntegra: 12 registros verificados"*), computadores e detalhe com "Gerenciar computador". Problemas encontrados e corrigidos:
1. `/alerts` ultrapassava a largura no celular (563 px em 375 px): `<span class="sr-only">` (posição absoluta) escapava do `overflow-x-auto` da tabela → `relative` no `<th>` (também na aba de alertas do computador). Depois: 9 páginas medidas a 375 px, nenhuma com rolagem horizontal.
2. Tempos relativos em inglês ("2 minutes ago") → `Carbon::setLocale('pt_BR')`.
3. "Último acesso: nunca UTC" → só acrescenta UTC quando há data.
4. Campos de data da auditoria espremidos (`dd/mm/…` cortado) → ocupam 2 colunas.
5. Nome clicável (grupos, regras, usuários) parecia texto simples → cor de link.
Observação: a barra de navegação passa para 2 linhas abaixo de ~1000 px (aceitável, sem quebra).

### Não validado
Chrome/Edge/Firefox reais e dispositivo físico (L24); MySQL/Redis (L1); encerramento de sessões com driver diferente de `database` (L20); agendador em produção (precisa de `schedule:run` a cada minuto, L21).

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

## Tarefas remotas — painel (Tarefas, Aprovações, detalhe) — 2026-10-15
| Verificação | Resultado |
|---|---|
| `TasksUiTest` (Manager) | 26 testes / 244 asserções: aba só com `tasks.view`, formulário só com tipos permitidos, idempotência (mesma chave em outro endpoint/tipo é recusada), 13 parâmetros hostis → erro de validação sem criar tarefa, criador sem botão Aprovar e POST forçado vira erro, aprovação por outro admin auditada, BOLA (grupo A × grupo B → 404 em criar/ver/cancelar/aprovar/rejeitar), operador não aprova (403), stdout/params com HTML escapados, sem PowerShell/arquivos/tela na UI, paginação 20. Suíte completa do Manager: **571 aprovados / 0 falhas** |
| Navegador embutido (SQLite descartável com dados fictícios, apagado depois) | Vistos: Aprovações, aba Tarefas (formulários, lista, 2ª página), detalhe com stdout `<script>` (0 `<script>` no DOM, texto literal). 375 px sem overflow da página (tabela rola dentro do cartão). **Ressalva:** `npm run build` falha neste ambiente (rollup nativo); o CSS compilado é antigo e sem algumas classes novas, então o layout foi visto com o Tailwind CDN v3 injetado como aproximação. Refazer a conferência com o build real. |

## Tarefas remotas — E2E com Agent real (2026-10-15)
Executado em 2026-10-15 22:52–23:13 (horário local, UTC−4; 02:52–03:13 UTC de 16/10). Mesmo ambiente do topo (sessão **sem Administrador**).
**Montagem:** `AgentToolit.exe` **publicado** (`dotnet publish Toolit\AgentToolit.csproj -c Release -o <temp local>`), modo console (sem serviço), `TOOLIT_AGENT_DATA` em pasta temporária, `enroll --server http://127.0.0.1:8765` com token novo (`toolit:enrollment-token --user=<operador>`). Manager: `php artisan serve --port=8765` com `APP_ENV=e2etest` → arquivo `.env.e2etest` temporário (SQLite descartável `e2e-test.sqlite`, MFA de admin desligado só nesse processo; `manager/.env` intocado). Usuários de teste: 2 Admins (A, B) e 1 Operador TI, senhas geradas localmente. Tarefas criadas/aprovadas pelo `TaskService` (o mesmo que o painel chama) e, nos casos marcados, pelo **painel via HTTP** (login Fortify + CSRF). Primeira metade com o Manager lendo o código do compartilhamento `Z:` (2–37 s por requisição); depois de uma cópia local do mesmo commit (`robocopy`, sem `.env`): 0,2–1 s por requisição. Tudo apagado no fim (banco, cópia, `.env.e2etest`, publicação, dados do Agent); nenhum `php`/`AgentToolit`/`PING` ficou rodando e a porta 8765 ficou sem listener.

| Cenário | Resultado observado |
|---|---|
| Poll curto | `GET /tasks/next?wait=0` a cada **10–11 s** (log do servidor: 23:10:06, :16, :26, :37, :47) |
| (a) leitura, criada pelo Operador | `system.info` → `completed` em **8 s** (Manager em `Z:`) e **2 s / 11 s** (local); `process.list` **9 s** e **11 s** (16 291 bytes, 148 de 425 processos, `truncated` interno da lista = true); `service.list` **11 s** (16 365 bytes); `inventory.refresh` criada pelo painel HTTP → `completed` e inventário reenviado. Saída real (hostname, SO 10.0.26300, 12 CPUs). Latência = `created_at` → estado final no banco |
| (b) `process.kill` de `ping.exe` descartável (PID 9308) criado pelo Operador | nasceu `pending_approval`; Operador tentando aprovar → `AuthorizationException` (sem `tasks.approve`; 403 no painel); não foi entregue em 20 s pendente; Admin B aprovou → `completed` **15 s** após a aprovação (`Processo PING (PID 9308) encerrado.`), `tasklist` não acha mais o PID. Repetido com o Manager local (PID 16664, `name: "PING.EXE"`, aprovado pelo Admin A): **9 s**, processo encerrado |
| Separação de funções | Admin A criou e tentou aprovar a própria tarefa → `Quem criou a tarefa não pode aprová-la nem rejeitá-la.` |
| Guarda de PID reutilizado | `{pid: 16664, name: "notepad"}` aprovado → `rejected` `pid reuse: pid 16664 is now 'PING'`; processo intacto |
| (c) processo protegido | `{pid: <ping descartável 16664>, name: "lsass"}` criado pelo Admin A, aprovado pelo Admin B → `rejected` (`protected process`) **2 s** após a aprovação; o `ping` continuou rodando. **Nenhum PID real de `lsass`/`csrss`/`winlogon` foi usado** |
| (d) parâmetros hostis pelo painel HTTP (`Accept: application/json`) | 10 casos → **422** com o campo certo e nenhuma tarefa criada: tipo `shell.exec`, `powershell.run`, PID `4`, `1234; calc.exe`, `-1`, nome de processo com `\n`, serviço `Spooler & calc`, `WinDefend` (protegido), nome em array, sem `idempotency_key`. **Exceção:** `service.stop` com `"Spooler\n"` foi aparado pelo `TrimStrings` do Laravel para `Spooler` (allowlist, risco médio, sem aprovação) e **criou** a tarefa — ver incidente abaixo |
| Cancelamento de tarefa disponível | `system.info` criada e cancelada na sequência → `cancelled`; 15 s depois nenhuma tentativa (nunca entregue) |
| (e) revogação pelo painel HTTP | `POST /endpoints/{uuid}/revoke` (Operador) → tarefa pendente cancelada com `reason: agent_revoked`; instalação `revoked`; o Agent fez **uma** última consulta (403), registrou "tarefas suspensas" e não fez mais nenhuma requisição nos 67 s seguintes; `state.json` com `Revoked: true` |
| `tests/integration/tasks-smoke.php` (simulador independente do protocolo) | **14/14 PASS**: 204 sem tarefa, query diferente da assinada → 401, sem assinatura → 401, entrega com exatamente os 7 campos, não entrega duas vezes (204), attempt alheio → 409 `task.conflict`, resultado → 200 `completed`, reenvio idêntico → 200, divergente → 409, `progress` após o fim → 409, heartbeat `tasks_available=false`/`cancel_task_ids=[]` |
| Auditoria | `audit:verify`: *Cadeia íntegra: 65 registros*; `task.created`=16, `task.approved`=6, `task.claimed`=14, `task.completed`=9, `task.failed`=5, `task.cancelled`=2; metadados com tamanho da saída (`stdout_bytes`), sem conteúdo |

### Achados do E2E
1. **Resposta de `next` perdida (L37):** com o Manager lento em `Z:`, um `GET /tasks/next` levou 32 s; o Agent desistiu em 30 s, mas o servidor concluiu o claim. O `process.kill` aprovado (alvo: `ping` descartável) ficou `running` sem executor e virou `failed` (`lease_expired`) só quando `tasks:expire` foi rodado à mão (135 s após a criação). Comportamento seguro (não executa duas vezes), mas a tarefa se perde. Não corrigido: decisão de projeto (ADR-021), registrado.
2. **Incidente de teste (não é defeito do código):** o caso hostil `"Spooler\n"` virou um `service.stop Spooler` **real**, entregue ao Agent em 5 s, que falhou com *"Cannot open 'Spooler' service on computer '.'. Acesso negado."* (sessão sem Administrador). `sc query Spooler` depois: `RUNNING`. O aparo de espaços nas pontas é do Laravel e torna o nome válido; o catálogo recusa o mesmo valor quando chamado direto. Os cenários seguintes não usaram nomes de serviços reais.
3. Nenhum defeito de código encontrado; nenhuma correção necessária.

### Não executado / não validado
- `process.kill` contra o `lsass` (ou `csrss`/`winlogon`) **real**: proibido aqui (um defeito derrubaria a máquina) — fazer em VM.
- `service.start|stop|restart` com sucesso em serviço real e `agent.restart` (recuperação pelo SCM, código 75): só *fakes* (L32, L36, L8).
- Cancelamento de tarefa **em execução** via `cancel_task_ids` e `progress`: só testes automatizados (as tarefas reais terminam em < 1 s; o Agent não envia `progress`) — L41.
- Corrida de dois consumidores no claim em MySQL (L30); agendador real (`tasks:expire` foi rodado manualmente).

### Suítes e varreduras (2026-10-15, depois do E2E)
| Verificação | Resultado |
|---|---|
| `cd manager && php artisan test` | **571 aprovados / 0 falhas** (2578 asserções, 351 s, no compartilhamento `Z:`) |
| Agent (`dotnet build` em `Z:` + `dotnet test <cópia local>/AgentToolit.Tests.dll` com `tests/contracts` copiado — L39) | **149 aprovados / 0 falhas** |
| `composer audit` | nenhuma vulnerabilidade |
| `dotnet list <projeto> package --vulnerable --include-transitive --source https://api.nuget.org/v3/index.json` (4 projetos, um por vez — L18) | nenhum pacote vulnerável |
| `php vendor/bin/pint --test` (Manager e `tests/integration/tasks-smoke.php`) | passou |
