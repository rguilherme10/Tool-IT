# 003 — Modelo de dados, autenticação, autorização e auditoria

## Objetivo e contexto
Base de identidade do Manager: usuários, MFA, RBAC com escopo por grupo de endpoints, auditoria encadeada, endpoints/instalações/tokens de enrollment.

## Problema a resolver
Autorização consistente no backend (BOLA/IDOR) e trilha de auditoria à prova de adulteração por operadores.

## Escopo
Incluído: migrations/models das tabelas "Identidade e acesso" e "Endpoints e agentes" de `data-model.md`; Fortify (login, 2FA TOTP); seeders de papéis/permissões; `AccessResolver`; Policies; `AuditLogger` + `audit:verify`; tela de login, perfil/2FA e listagem mínima de endpoints; CRUD de usuários/atribuições (UI mínima). Excluído: inventário, métricas.

## Dependências
001, 002.

## Análise da arquitetura atual
Skeleton Laravel com `users`, `cache`, `jobs`. Nenhum código de domínio.

## Arquivos/módulos
`app/Enums/Permission.php`, `app/Models/*`, `app/Support/Authorization/AccessResolver.php`, `app/Policies/EndpointPolicy.php`, `app/Services/Audit/AuditLogger.php`, `app/Console/Commands/AuditVerify.php`, `database/migrations/*`, `database/seeders/RolesAndPermissionsSeeder.php`, `routes/web.php`.

## Requisitos funcionais
- Login com e-mail/senha, TOTP, recuperação; MFA obrigatório para Admin.
- Atribuir papel a usuário globalmente ou por grupo.
- `AccessResolver::can(user, permission, ?endpoint)`; consultas de endpoints filtradas por escopo (`Endpoint::visibleTo($user, $permission)`).
- Auditar: login/logout/falha, 2FA, mudança de papel, criação de token de enrollment, revogação de agente, criação/edição de usuário.

## Não funcionais
Hash encadeado em transação com lock (sem lacunas de `seq`); falha de auditoria **aborta** a ação auditada.

## Regras
Ver `permissions-matrix.md`. Último admin não removível. Usuário inativo não autentica nem tem sessões válidas.

## Contratos
Tabelas em `data-model.md`. Hash do log: `sha256(prev_hash | seq | occurred_at | actor | action | subject | endpoint_id | canonical_json(metadata))`.

## Segurança
Sem mass assignment em `role_assignments`; senha com `Password::defaults`; regenerar sessão no login; throttling; nenhum segredo em log/auditoria (metadata filtrada).

## Estratégia
1. `php artisan make:model -m` para cada entidade; ajustar migrations. 2. Seeder. 3. AccessResolver + testes. 4. AuditLogger + triggers + testes. 5. Fortify (`composer require laravel/fortify`, `php artisan fortify:install`). 6. Telas mínimas. 7. Testes de isolamento.

## Cenários de erro
Usuário sem atribuição → 403 (nunca lista vazia silenciosa em rota direta); endpoint de outro grupo → 404/403 consistente; UPDATE/DELETE em `audit_logs` → exceção.

## Testes obrigatórios
Auth (login, throttling, 2FA), RBAC por papel e por grupo, isolamento entre grupos, auditoria (append-only, cadeia íntegra, detecção de adulteração), último admin, migrations (integridade/FKs).

## Critérios de aceite
- [ ] Matriz de permissões coberta por teste (data provider por papel×permissão) - [ ] Triggers bloqueiam UPDATE/DELETE - [ ] `audit:verify` detecta alteração - [ ] Operador de grupo A não vê/edita endpoint do grupo B

## Documentação
`permissions-matrix.md`, `data-model.md`, `status.md`.

## Riscos
Triggers de MySQL diferem de SQLite (sintaxe e privilégio `TRIGGER`) → **validar em MySQL real/Laragon** (task 013); até lá o critério "triggers bloqueiam UPDATE/DELETE" só vale para SQLite. Fortify sem UI pronta: telas escritas à mão.

## Checklist
- [x] Migrations (SQLite; **MySQL pendente**) - [x] Seeders - [x] AccessResolver - [x] Audit (triggers validados só em SQLite) - [x] Fortify/MFA - [x] UI mínima - [x] Testes verdes (229, SQLite) - [x] Docs
- [ ] Gestão de usuários/atribuições pela UI (hoje só por serviço/comando `toolit:create-admin`) — mover para task 011
- [ ] Reset de senha por e-mail (sem SMTP) — decisão: administrador redefine
- [ ] Executar a suíte em MySQL real (task 013)

Critérios de aceite: matriz coberta por teste ✔ · `audit:verify` detecta alteração ✔ · operador do grupo A não vê/edita o grupo B ✔ · triggers bloqueiam UPDATE/DELETE ✔ **somente SQLite**.
