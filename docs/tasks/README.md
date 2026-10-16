# Planejamento de tarefas

Leia antes: `docs/architecture.md`, `docs/decisions.md`, `docs/threat-model.md`, `docs/status.md`.

Regras para o agente executor:
1. Leia a tarefa e **todas as dependências** antes de começar.
2. Implemente em etapas pequenas; use geradores (`php artisan make:*`, `dotnet new`, `npm`) antes de escrever à mão.
3. Rode testes a cada etapa. Só marque `[x]` o que foi **executado e validado**; registre a evidência em `docs/test-evidence.md`.
4. Ao terminar: atualizar checklist da tarefa, `docs/status.md`, docs afetadas.
5. Não use dados fictícios para simular funcionalidades prontas; módulo não implementado deve aparecer como "não implementado" na UI.

| # | Tarefa | Fase | Depende de | Estado |
|---|---|---|---|---|
| 001 | Arquitetura e requisitos | 1 | – | ✔ docs |
| 002 | Infraestrutura e segurança | 1 | 001 | parcial (headers, rate limits) |
| 003 | Modelo de dados e autenticação | 1 | 001,002 | ✔ em SQLite · MySQL pendente |
| 004 | API de comunicação | 1 | 003 | ✔ enroll/heartbeat/rotação/revogação · demais rotas nas tasks 006–012 |
| 005 | Agent Windows e provisionamento | 2 | 004 | ✔ código e testes · serviço real pendente (sem Administrador) |
| 006 | Inventário hardware/software | 2 | 005 | ✔ inventário · política de arquivos pendente |
| 007 | Monitoramento e alertas | 2 | 005,006 | parcial (métricas) · alertas pendentes |
| 008 | Tarefas e comandos remotos | 3 | 004,005 | parcial: tarefas tipadas prontas; PowerShell fora do ciclo; serviço real não validado |
| 009 | Transferência de arquivos | 3 | 008 | pendente |
| 010 | Suporte remoto de tela | 4 | 005,008 | pendente |
| 011 | Interface administrativa | 2–5 (incremental) | 003 | pendente |
| 012 | Instalação, atualização, distribuição | 5 | 005 | parcial: MSI + serviço (não validado em instalação real) · assinatura, atualização assinada, GPO e anéis pendentes |
| 013 | Testes e hardening | 5 | todas | pendente |
| 014 | Backup, observabilidade, operação | 5 | 002 | pendente |

Inventário de arquivos (política) e processos/serviços entram em 006 e 008 respectivamente (não há tarefa própria para evitar redundância).
