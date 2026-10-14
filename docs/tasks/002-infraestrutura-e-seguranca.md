# 002 — Infraestrutura e segurança de base

## Objetivo e contexto
Preparar o Manager com configuração segura, filas, agendador, logs estruturados, rate limiting, cabeçalhos de segurança, e documentação de servidor (TLS, firewall, DNS).

## Problema a resolver
Segurança "desde a concepção": evitar defaults inseguros (debug, cookies, sessão, CSP).

## Escopo
Incluído: config de produção (`.env.example`), cookies `secure/httponly/samesite=lax`, middleware de cabeçalhos (HSTS, CSP, X-Frame-Options, Referrer-Policy), log em JSON (channel `daily` + formatter), rate limiters nomeados, `queue:work`/`schedule:run` documentados, política de senha, scripts em `deploy/`. Excluído: provisionar servidor real, Reverb (tarefa 010).

## Dependências
001.

## Análise da arquitetura atual
Laravel 13 skeleton; `.env` com SQLite, sessão/cache/fila em `database`.

## Arquivos prováveis
`bootstrap/app.php`, `app/Providers/AppServiceProvider.php` (RateLimiter, Password::defaults), `app/Http/Middleware/SecurityHeaders.php`, `config/logging.php`, `.env.example`, `deploy/*`, `docs/deployment/server.md`.

## Requisitos funcionais
Rate limiters: `login` (5/min por email+IP), `agent-enroll` (10/min por IP), `agent-api` (120/min por instalação), `web` geral.

## Requisitos não funcionais
Produção com `APP_DEBUG=false`; logs sem segredos (redação de `password`, `token`, `signature`, `authorization`).

## Regras de negócio
Senha ≥ 12 caracteres, comum/vazada bloqueada (`Password::uncompromised` em produção), sessão idle 30 min.

## Contratos
N/A.

## Segurança
Ver T13, T14. CSP sem `unsafe-inline` para scripts (nonce via Vite quando UI existir).

## Estratégia
1. Middleware de headers + teste. 2. Rate limiters + testes. 3. Redação de logs + teste. 4. `deploy/` (nginx, supervisor, systemd/NSSM, cron) como **modelos não validados** até teste real. 5. Documento de servidor.

## Cenários de erro
Proxy reverso sem `X-Forwarded-Proto` → cookies secure falham: documentar `TrustProxies`.

## Testes
Headers presentes; rate limit retorna 429; log não contém token.

## Critérios de aceite
- [ ] Headers testados - [ ] Rate limits testados - [ ] Redação testada - [ ] `docs/deployment/server.md` revisado em ambiente de teste (marcar só após execução real).

## Documentação
`docs/deployment/server.md`, `.env.example`.

## Riscos
Scripts de deploy sem validação em servidor real; marcar como "não validado".

## Checklist
- [x] Headers (`SecurityHeaders`, testado) - [x] Rate limiters (login, 2FA, enroll, API por instalação/IP, testado) - [x] Política de senha (12+, mixed case, números; `uncompromised` em produção)
- [ ] Logs JSON com redação de segredos - [ ] Cookies/sessão endurecidos em produção (`.env.example` já orienta) - [ ] `deploy/` (NSSM, agendador) - [ ] Guia de servidor **validado** (`docs/deployment/server.md` é rascunho) - [ ] CSP testada em navegador (sem inline; só HTML verificado)
