# Estado do projeto

Atualizado em 2026-10-14. **Só consta como "concluído" o que foi executado e validado** (evidências em [`test-evidence.md`](test-evidence.md)).

## Fase 1 — Fundação (Manager)

| Item | Estado | Observação |
|---|---|---|
| Requisitos, arquitetura, ADRs, modelo de ameaças, matriz de permissões, contrato da API, modelo de dados, planos 001–014 | ✔ escritos | Revisar ao fim de cada fase |
| Scaffold Laravel 13 / PHP ≥ 8.3 | ✔ | `manager/` |
| Migrations (usuários, papéis, permissões, grupos, endpoints, instalações, tokens, auditoria, configurações) | ✔ SQLite · ⚠ MySQL não testado | Ver L1 |
| RBAC com escopo por grupo (`AccessResolver`, Gates, `EndpointPolicy`) | ✔ | Matriz papel × permissão testada integralmente |
| Auditoria append-only com hash encadeado + `audit:verify` | ✔ SQLite · ⚠ triggers MySQL não testados | |
| Login, TOTP (Fortify), MFA obrigatório para admins, sem auto-cadastro | ✔ | Reset de senha por e-mail: não implementado (sem SMTP) |
| Telas: login/2FA, painel, lista/detalhe de computadores, novo agente (token), revogar agente, segurança | ✔ mínimas | Demais módulos aparecem como "Não implementado" |
| API do Agent v1: `meta`, `enroll`, `heartbeat`, `keys/rotate`; assinatura por request; anti-replay; revogação imediata | ✔ | `inventory`, `metrics`, `events`, `tasks/*`, `updates/check` **não implementados** |
| Vetor de contrato PHP↔C# | ✔ lado PHP | Lado C# pendente (task 005) |
| Comandos: `toolit:create-admin`, `toolit:enrollment-token`, `audit:verify` | ✔ | |
| Smoke real por HTTP (`tests/integration/agent-smoke.php`) | ✔ | Contra `artisan serve` + SQLite |

## Pendente (por ordem)
1. **Validar em MySQL + Redis no Laragon** (executar a suíte com `DB_CONNECTION=mysql`) — task 013, risco L1.
2. Task 002: rate limiters/headers já feitos; falta log estruturado com redação, `deploy/` e guia de servidor validado.
3. Fase 2: Agent C# (005), inventário (006), métricas e alertas (007).
4. Fase 3: tarefas (008), arquivos (009). Fase 4: tela (010, começa pela PoC de sessões). Fase 5: 012–014.

## Riscos abertos
Ver [`known-limitations.md`](known-limitations.md). Principais: nada rodou em MySQL/Redis ainda; .NET 10 SDK ausente; PHP do host sem `openssl.cnf` (os testes usam um mínimo próprio — o PHP do Laragon normalmente o inclui).
