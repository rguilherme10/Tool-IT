# 013 — Testes integrados, carga e hardening

## Objetivo e contexto
Garantir qualidade proporcional ao risco, validar em MySQL/Redis reais (Laragon) e medir capacidade.

## Problema
Testes em SQLite/array não provam comportamento de produção (triggers, locks, Redis).

## Escopo
Incluído: suíte rodando contra MySQL 8 e Redis (CI local no Laragon), testes de integração ponta a ponta (fluxo de 12 passos do enunciado §17), carga com 100 agentes simulados, varredura de dependências (`composer audit`, `dotnet list package --vulnerable`, `npm audit`), revisão de segurança, ZAP/ferramenta equivalente opcional.

## Dependências
Todas as anteriores (executa-se em paralelo, incrementalmente).

## Análise da arquitetura atual
Testes unitários/feature em SQLite. Nenhuma validação em MySQL.

## Arquivos
`manager/phpunit.xml` (perfil `mysql`), `tests/integration/*`, `tests/load/*` (simulador de agentes em .NET ou PHP), `docs/test-evidence.md`, `docs/performance.md`.

## Requisitos funcionais
Cenários do enunciado §17 (Laravel, Agent, Integração). Simulador gera N agentes com chaves reais e carga realista (heartbeat 60 s, métricas 60 s, inventário 24 h).

## Não funcionais
Registrar método, ambiente (CPU/RAM/SO/versões), resultados (p50/p95/p99, erros, CPU/RAM do servidor) e limitações. Não prometer capacidade sem medição.

## Regras
Item só vira `[x]` com evidência (comando, data, resultado) em `test-evidence.md`.

## Contratos
Mesmos JSONs de contrato (PHP e C#).

## Segurança
Testes negativos: BOLA, replay, assinatura inválida, traversal, XSS armazenado, CSRF, rate limit, escalação de papel, auditoria adulterada.

## Estratégia
1. Perfil MySQL/Redis. 2. Corrigir divergências. 3. Integração E2E. 4. Carga. 5. Hardening (config, headers, privilégios do usuário MySQL sem UPDATE/DELETE em `audit_logs`). 6. Auditoria de dependências.

## Erros a provocar
Redis fora do ar, MySQL reiniciando, Manager indisponível durante envio do Agent, corrida de claim, relógio deslocado.

## Testes obrigatórios
Ver enunciado §17 (lista completa).

## Aceite
- [ ] Suíte verde em MySQL+Redis - [ ] E2E dos 12 passos com evidências - [ ] Carga 100 agentes medida - [ ] Auditorias de dependências sem críticas abertas - [ ] Restauração de backup testada (014)

## Documentação
`test-evidence.md`, `performance.md`, `known-limitations.md`.

## Riscos
Ambiente de teste Windows disponível é limitado; documentar o que não foi testado (ARM64, Windows 10 antigo).

## Checklist
- [ ] MySQL - [ ] Redis - [ ] E2E - [ ] Carga - [ ] Dependências - [ ] Hardening - [ ] Docs
