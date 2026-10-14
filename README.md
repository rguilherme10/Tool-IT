# Toolit — inventário e administração remota de endpoints Windows

Plataforma própria para 50–100 PCs/notebooks Windows em rede corporativa local:

- **Manager** (`manager/`) — Laravel 13: painel web, API do Agent, usuários/RBAC, auditoria.
- **Agent** (`agent/`) — serviço Windows em C#/.NET *(ainda não implementado — Fase 2)*.

> Estado real, módulo a módulo: [`docs/status.md`](docs/status.md). Nada é marcado como pronto sem teste executado ([`docs/test-evidence.md`](docs/test-evidence.md)).

## Arquitetura em uma tela

```
Navegador ──HTTPS──► Manager (Laravel + MySQL + Redis) ◄──HTTPS (iniciado pelo Agent)── Agent Windows (serviço)
```

- O **Agent sempre inicia a conexão**; nenhuma porta de entrada nos endpoints.
- Identidade do Agent = **par de chaves ECDSA P-256 por instalação**; cada request é **assinado** (sem segredo trafegando), com proteção de replay. Registro por **token de uso único**. Revogação individual e imediata.
- Autorização **sempre no backend**: RBAC com escopo por grupo de endpoints.
- Toda ação administrativa gera **auditoria append-only com hash encadeado**.
- Sem shell livre: tarefas tipadas e auditadas (Fase 3). Tela remota somente leitura (Fase 4).

Detalhes: [`docs/architecture.md`](docs/architecture.md) · decisões: [`docs/decisions.md`](docs/decisions.md) · ameaças: [`docs/threat-model.md`](docs/threat-model.md).

## Pré-requisitos

| Componente | Versão | Observação |
|---|---|---|
| PHP | ≥ 8.3 | extensões `openssl`, `pdo_mysql`, `sodium`, `intl`, `mbstring`, `curl`, `zip` |
| Composer | 2.x | **atenção:** um `platform.php` global antigo faz o Composer escolher Laravel antigo (ADR-001) |
| MySQL | 8.0+ (ou MariaDB 10.6+) | alvo de produção (Laragon) — **ainda não validado**, ver `known-limitations.md` |
| Redis | 6+ | produção; opcional em desenvolvimento |
| Node | 20+ | build do front-end |
| .NET SDK | 10 (LTS) | Agent — Fase 2 |

## Início rápido (desenvolvimento, SQLite)

```bash
cd manager
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed --seeder=RolesAndPermissionsSeeder
npm install && npm run build
php artisan toolit:create-admin --name="Seu Nome" --email=voce@empresa.local   # pede a senha (mín. 12 caracteres)
php artisan serve
```

Entre em `http://127.0.0.1:8000`; o administrador será levado a ativar o TOTP antes de usar o painel.
Para registrar um agente: **Novo agente** no painel (ou `php artisan toolit:enrollment-token --user=voce@empresa.local`).
Teste ponta a ponta do protocolo sem Agent C#: `php tests/integration/agent-smoke.php http://127.0.0.1:8000 <token>`.

### Testes

```bash
cd manager && php artisan test        # SQLite em memória
composer audit && npm audit
```

## Estrutura do repositório

```
manager/    aplicação Laravel (código, migrations, testes)
agent/      solução .NET (Fase 2)
docs/       arquitetura, API, segurança, planos (docs/tasks), estado e evidências
deploy/     scripts e modelos de servidor (Fase 5)
installer/  empacotamento do Agent (Fase 5)
tests/      contratos compartilhados (tests/contracts) e testes de integração (tests/integration)
```

## Documentação

| Documento | Conteúdo |
|---|---|
| [`docs/status.md`](docs/status.md) | O que está feito, pendente e arriscado |
| [`docs/tasks/`](docs/tasks/README.md) | Plano de execução 001–014, autossuficiente por tarefa |
| [`docs/architecture.md`](docs/architecture.md) | Componentes, fluxos, Session 0 / captura de tela |
| [`docs/decisions.md`](docs/decisions.md) | ADRs (MySQL, Redis, assinatura por request, RBAC, auditoria…) |
| [`docs/threat-model.md`](docs/threat-model.md) | Ameaças e controles |
| [`docs/permissions-matrix.md`](docs/permissions-matrix.md) | Papéis × permissões |
| [`docs/api/agent-api-v1.md`](docs/api/agent-api-v1.md) | Contrato da API do Agent |
| [`docs/data-model.md`](docs/data-model.md) | Tabelas, índices, retenção |
| [`docs/deployment/server.md`](docs/deployment/server.md) | Instalação do servidor (Laragon) |
| [`docs/known-limitations.md`](docs/known-limitations.md) | Limitações conhecidas |
| [`docs/test-evidence.md`](docs/test-evidence.md) | Resultados reais de testes |
