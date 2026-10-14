# 004 — API de comunicação Manager ↔ Agent

## Objetivo e contexto
Implementar `api/agent/v1` conforme `docs/api/agent-api-v1.md`: enrollment, autenticação por assinatura, heartbeat, rotação e revogação; esqueleto dos demais endpoints à medida que as tarefas dependentes avançam.

## Problema
Identidade verificável do agente, proteção contra replay/forja e revogação imediata.

## Escopo
Incluído (Fase 1): `GET /meta`, `POST /enroll`, `POST /heartbeat`, `POST /keys/rotate`, middleware `VerifyAgentSignature`, revogação (serviço + UI/comando), rate limits, auditoria. Demais rotas (`inventory`, `metrics`, `events`, `tasks/*`, `updates/check`) entram nas tarefas 006/007/008/012. Excluído: mTLS.

## Dependências
003.

## Análise da arquitetura atual
Ver 003. API routes habilitadas com `php artisan install:api` (apenas rotas; **sem** Sanctum para o Agent).

## Arquivos
`routes/api.php` (prefixo `agent/v1`), `app/Http/Controllers/Api/Agent/V1/*`, `app/Http/Middleware/VerifyAgentSignature.php`, `app/Services/Agents/{EnrollmentService,AgentKeyService,SignatureVerifier,RevocationService}.php`, `app/Http/Requests/Api/Agent/*`, `tests/Feature/Api/Agent/*`, `tests/contracts/*.json`.

## Requisitos funcionais
Conforme contrato §1–3. Reativação/substituição por token com `replaces_endpoint_id`. Heartbeat atualiza `last_seen_at`, versão, IPs, usuário.

## Não funcionais
Verificação de assinatura < 5 ms; resposta de erro uniforme (sem oráculo de qual verificação falhou); corpo bruto lido uma vez.

## Regras
Token uso único consumido em transação com `lockForUpdate`; 1 instalação ativa por endpoint; chave antiga válida 5 min após rotação; revogada ⇒ 403 `agent.revoked` mesmo com assinatura correta.

## Contratos
`docs/api/agent-api-v1.md` (fonte da verdade). Mudança aqui ⇒ atualizar o doc e os JSONs de contrato.

## Segurança
Comparações em tempo constante; nonce em cache atômico (`Cache::add`); relógio: ±120 s; limite de payload; sem log de assinatura/corpo sensível; nunca aceitar HTTP em produção.

## Estratégia
1. `php artisan install:api` (remover Sanctum se não usado). 2. `make:request`, `make:controller`, `make:middleware`. 3. SignatureVerifier com testes usando chaves geradas pelo `openssl`. 4. Enrollment. 5. Heartbeat. 6. Rotação. 7. Revogação. 8. Rate limit.

## Cenários de erro
Timestamp antigo/futuro, nonce repetido, assinatura trocada, método/path/query/corpo alterados, agente revogado, token expirado/usado/revogado/de outro grupo, payload gigante, JSON inválido, chave pública inválida (curva errada), corrida de dois enrollments com o mesmo token.

## Testes obrigatórios
Todos os cenários acima; teste de contrato com JSON gerado; teste de concorrência do token (dois processos/transações); auditoria de enroll/revogação.

## Aceite
- [ ] Enroll válido cria endpoint+instalação e consome token - [ ] Replay rejeitado - [ ] Revogado não opera - [ ] Rotação funciona - [ ] Tudo auditado - [ ] Contrato verificado também por vetor de teste cruzado com C# (task 005)

## Documentação
`api/agent-api-v1.md`, `threat-model.md`, `status.md`.

## Riscos
Diferenças de codificação DER/P1363 entre .NET e OpenSSL (mitigação: formato DER explícito + vetor de teste cruzado). Relógio desajustado do endpoint (mitigação: `server_time` no erro e sincronização).

## Checklist
- [x] Middleware `VerifyAgentSignature` - [x] Enroll - [x] Heartbeat - [x] Rotação - [x] Revogação (serviço + UI + teste) - [x] Testes (SQLite) - [x] Smoke real por HTTP - [x] Vetor de contrato (lado PHP) - [x] Docs
- [ ] Vetor cruzado verificado pelo Agent C# (task 005) · [ ] teste de concorrência com 2 processos no MySQL (013)
- [ ] Endpoints restantes (`inventory`, `metrics`, `events`, `tasks/*`, `updates/check`) — tasks 006/007/008/012
- [ ] `Content-Encoding: gzip` e limite de payload em middleware (1 MiB hoje só no contrato/validação) — task 006
