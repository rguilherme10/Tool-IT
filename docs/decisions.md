# Registro de decisões arquiteturais (ADR)

Formato: contexto → decisão → consequências. Novas decisões entram no fim; decisões substituídas são marcadas, não apagadas.

## ADR-001 — Laravel 13 / PHP 8.3+ (dev: 8.4)
**Contexto:** exigência de versão estável e suportada. Laravel 13 é a versão estável corrente; requer PHP ≥ 8.3 (host tem 8.4.1).
**Nota operacional:** o Composer global deste host tem `platform.php=8.1` forçado, o que faz `create-project` resolver Laravel 10 (EOL). O scaffold foi feito com `COMPOSER_HOME` isolado e o `composer.json` do projeto fixa `config.platform.php`.
**Consequências:** testes com PHPUnit 12 (padrão do skeleton).

## ADR-002 — Banco: MySQL 8.0+ (ou MariaDB 10.6+) em produção — decisão do dono do projeto
**Contexto:** o dono do projeto definiu MySQL como banco alvo, executado em Laragon (Windows). Substitui a proposta inicial de PostgreSQL.
**Decisão:** MySQL 8.0+ / InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`. SQLite em memória para testes rápidos. As migrations usam apenas recursos portáveis entre MySQL e SQLite:
- unicidade "no máximo 1 instalação ativa por endpoint" via **coluna gerada** (`virtualAs`) + índice único (MySQL não tem índice parcial);
- `audit_logs` imutável via **triggers** `BEFORE UPDATE/DELETE` (MySQL: `SIGNAL SQLSTATE '45000'`; SQLite: `RAISE(ABORT)`), criados por migration condicional ao driver;
- claim de tarefas por `UPDATE … WHERE status='available'` atômico (não depende de `SKIP LOCKED`);
- JSON nativo (`json`), datas `datetime(6)` onde precisão importa.
**Consequências:** a suíte deve ser executada contra MySQL real antes de produção (task 013). Nenhum MySQL/Laragon existe no host de desenvolvimento atual, então **a validação em MySQL está pendente** (ver `known-limitations.md`); o usuário do app em produção não deve ter `UPDATE/DELETE` em `audit_logs`, e a migration exige privilégio `TRIGGER`.

## ADR-013 — Hospedagem do Manager no Laragon (Windows)
**Contexto:** o Manager rodará no Laragon (Apache ou Nginx + PHP + MySQL).
**Decisão/consequências:** (1) PHP ≥ 8.3 com extensões `openssl`, `pdo_mysql`, `sodium`, `intl`, `mbstring`, `curl`, `zip`; (2) HTTPS obrigatório para os Agents — usar certificado de CA interna (Laragon gera certificado autoassinado para dev; **o Agent não aceita certificado não confiável**, então a CA deve ser instalada nos endpoints, p.ex. por GPO); (3) `queue:work`, `schedule:run` e `reverb:start` como processos persistentes do Windows (NSSM ou Agendador de Tarefas), documentados em `docs/deployment/server.md`; (4) MySQL com senha no `root`, `bind-address=127.0.0.1` se app e banco estiverem na mesma máquina, usuário dedicado da aplicação; (5) Laragon é voltado a desenvolvimento: para produção, rodar Apache/Nginx e MySQL como serviços do Windows, firewall liberando apenas 443 (e porta do Reverb se separada), backups agendados do MySQL (`mysqldump`/`mysqlpump`). Reverb funciona no Windows, mas deve ser validado sob carga (task 010/013).

## ADR-003 — Redis para fila, cache, sessão e nonces (produção); banco/array em dev e testes
**Contexto:** o ambiente Laragon do dono do projeto terá Redis e Memcached disponíveis (a escala de ~100 endpoints não os exigiria, mas já existem).
**Decisão:** `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis` em produção. Redis também serve de store para: nonces anti-replay (`Cache::add`, atômico), rate limiting e Laravel Reverb (escala horizontal opcional). **Memcached não é usado**: manter um único store reduz peças a operar (Memcached não oferece filas nem persistência e o Laravel não o usa para `queue`). Cliente: extensão `phpredis` se disponível no PHP do Laragon, senão `predis/predis`.
**Regras:** Redis com senha e `bind 127.0.0.1`; persistência (AOF ou RDB) habilitada para não perder jobs; **nada que precise ser durável vive só no Redis** — estado de tarefas, sessões remotas e auditoria ficam no MySQL (Redis é transporte/cache). Perda do Redis não pode corromper estado: leases expiram e tarefas voltam a `available`.
**Dev/testes neste host:** sem servidor Redis nem extensão `phpredis`; usar `database`/`array` via `.env`/`phpunit.xml`. Validar com Redis real é pendência (task 013).

## ADR-004 — Autenticação do Agent: chave assimétrica por instalação + requests assinados
**Alternativas:** (a) mTLS com certificados de cliente; (b) bearer token de longa duração; (c) token de curta duração obtido por desafio; (d) **assinatura por request com ECDSA P-256**.
**Decisão:** (d). Chave privada nunca sai do endpoint (DPAPI máquina). Servidor guarda só a chave pública. Cada request leva `X-Agent-Id`, `X-Timestamp`, `X-Nonce`, `X-Signature` sobre `METHOD\nPATH\nQUERY\nTS\nNONCE\nSHA256(body)`.
**Por quê não mTLS agora:** exige PKI interna, distribuição e renovação de certificados e terminação TLS configurada para pedir certificado de cliente (complexidade operacional alta para a escala, especialmente atrás de proxy). Pode ser adicionado depois como camada extra sem alterar o protocolo.
**Por quê não bearer longo:** segredo reutilizável interceptável/vazável; revogação depende de lista.
**Consequências:** replay mitigado por janela de ±120 s + nonce único (cache); revogação imediata; rotação de chave via endpoint assinado; sem segredos em instalador (apenas token de enrollment de uso único por máquina). Implementação .NET: `ECDsa` com `DSASignatureFormat.Rfc3279DerSequence` (compatível com `openssl_verify`).

## ADR-005 — Enrollment por token de uso único
Token de 256 bits, mostrado uma única vez, armazenado como SHA-256, expira (padrão 24 h), limite de usos (padrão 1), vinculado a grupo de endpoints e criador. Instalador recebe o token por parâmetro (GPO pode usar tokens de múltiplos usos com limite e validade curta, explicitamente criados com permissão `enrollment.create_multi`).

## ADR-006 — RBAC próprio com escopo por grupo
**Alternativa:** spatie/laravel-permission. **Decisão:** modelo próprio mínimo (`roles`, `permissions`, `role_permission`, `role_assignments` com `endpoint_group_id` nulo = global) porque o escopo por grupo é requisito central e a lib não o modela nativamente. Autorização via `Gate::before`-free: `AccessResolver::can($user, $permission, ?Endpoint)`; Policies a consomem. Permissões são constantes em `App\Enums\Permission`.

## ADR-007 — Autenticação do operador: Laravel Fortify + TOTP
Fortify (headless) fornece login, 2FA TOTP, códigos de recuperação, confirmação de senha. Telas em Blade. MFA obrigatório para Administrador (configurável em `system_settings`).

## ADR-008 — Tela remota: capturas periódicas via helper de sessão; somente leitura
Ver `architecture.md` §6. Reavaliar para streaming contínuo se medições mostrarem necessidade.

## ADR-009 — Auditoria encadeada
`audit_logs` é append-only: sem `updated_at`, bloqueio de UPDATE/DELETE por *trigger* (MySQL e SQLite) e hash encadeado (`prev_hash`, `hash` = SHA-256 do registro canônico + prev). A sequência (`seq`) é atribuída pela aplicação sob lock na linha única de `audit_chain` (evita lacunas do auto-incremento do InnoDB em rollback). Verificação com `php artisan audit:verify`. Retenção só via comando privilegiado que arquiva por intervalo e registra a própria remoção.

## ADR-010 — .NET para o Agent
Alvo desejado: **.NET 10 (LTS)**. O host só tem SDK 9.0.308 (STS) e runtimes 8 e 10; o SDK 9 não compila `net10.0`. .NET 8 (LTS) sai de suporte em 10/11/2026. **Decisão provisória:** alvo `net8.0` até instalar o SDK 10, com `TargetFramework` centralizado em `Directory.Build.props` para migrar com uma linha. Registrado como pendência em task 005.

## ADR-011 — Fila local do Agent: SQLite
Arquivo em `%ProgramData%\Toolit\Agent\queue.db`, ACL restrita a SYSTEM/Administrators, limite de linhas/bytes, descarte do mais antigo por prioridade.

## ADR-012 — Atualização do Agent assinada
Pacote ZIP + manifesto assinado (ECDSA P-256 ou Authenticode no executável). Chave pública de verificação embutida no Agent; chave privada de release fora do servidor Manager. Rollback automático se o novo serviço não reportar saúde em N minutos.
