# Evidências de testes

Cada linha é um resultado **observado**. Ambiente da Fase 1: Windows 11 Home 10.0.26300, PHP 8.4.1 (NTS, x64), Composer 2.8.10, Laravel 13, PHPUnit 12, SQLite (memória) — **sem MySQL nem Redis**.

## Fase 1 — Manager (2026-10-14)

| Teste | Comando | Resultado |
|---|---|---|
| Suíte completa do Manager | `cd manager && php artisan test` | **229 aprovados / 0 falhas** (802 asserções, ≈ 77 s — a maior parte é geração de chaves EC reais) |
| Dependências PHP | `composer audit` | Nenhuma vulnerabilidade conhecida |
| Dependências JS | `npm audit` | 0 vulnerabilidades |
| Build do front-end | `npm run build` | OK (Vite 7 / Tailwind 4) |
| Estilo | `vendor/bin/pint` | Aplicado |
| Smoke real por HTTP | `php artisan serve` + `php tests/integration/agent-smoke.php <url> <token>` | 7/7 verificações: meta, enroll 201, token não reutilizável, heartbeat 200, replay 401, corpo adulterado 401, sem assinatura 401 |
| Integridade da auditoria após o smoke | `php artisan audit:verify` | "Cadeia íntegra: 4 registros verificados" |

### O que a suíte cobre (resumo)
- **RBAC**: matriz completa papel × permissão (5 × 28), isolamento por grupo e descendentes, usuário inativo/excluído, último admin, unicidade de atribuição.
- **Auditoria**: sequência, cadeia, triggers bloqueando UPDATE/DELETE, detecção de registro alterado/removido/cauda removida, redação de segredos, rollback junto da ação.
- **API do Agent**: enroll (token uso único/multiuso/expirado/revogado/inexistente com resposta idêntica, chave inválida/duplicada, validação), substituição de equipamento, 1 instalação ativa por endpoint (nível de banco), assinatura (cada header, adulteração de corpo/caminho/método/query, ordem de query, janela de relógio, replay, nonce inválido, ausência de oráculo), heartbeat, revogação imediata, rotação de chave (janela de transição), rate limit por instalação e por IP, formato de erro.
- **Web**: login, falha auditada sem e-mail em claro, throttling, MFA TOTP completo (ativar → confirmar → desafio → anti-replay do código), MFA obrigatório para admin, isolamento entre grupos (listagem, detalhe → 404, revogação → 403/404), XSS (dados do endpoint escapados; varredura proíbe `{!! !!}`), token exibido uma vez, escopo/multiuso do enrollment, CSRF nos formulários.
- **Contrato**: vetor PHP da assinatura (`tests/contracts/signature-vector.json`).

### Não validado (honestamente)
- MySQL 8 / MariaDB: migrations, coluna gerada com índice único, triggers `SIGNAL`, `ESCAPE '!'`.
- Redis (fila/cache/sessão/nonce).
- Concorrência real de dois processos consumindo o mesmo token (o consumo é atômico por `UPDATE … WHERE uses < max_uses`, mas só foi exercitado sequencialmente).
- Navegador real (layout responsivo, QR code do TOTP) — apenas HTML verificado por testes.
- Agent C# (não existe ainda).
