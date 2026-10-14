# Modelo de dados

Banco alvo: MySQL 8.0+ (InnoDB, utf8mb4) — ver ADR-002. Convenções Laravel (snake_case, plural). IDs públicos (`uuid`) para entidades expostas ao Agent/API; PK interna `bigint`. Fase marca quando a tabela é criada. ✔ = já migrada.

## Identidade e acesso (Fase 1)
| Tabela | Colunas-chave | Restrições |
|---|---|---|
| `users` | name, email, password, 2FA (Fortify), `is_active`, `last_login_at`, `deleted_at` | email único |
| `roles` | key, name, description, `is_system` | key único |
| `permissions` | key (`modulo.acao`), description | key único |
| `permission_role` | role_id, permission_id | PK composta, FK cascade |
| `endpoint_groups` | uuid, name, parent_id nulo, description | name único por parent |
| `role_assignments` | user_id, role_id, endpoint_group_id NULL, assigned_by | único (user, role, group); FK |
| `audit_logs` | seq (atribuído pela app, único), occurred_at, actor_type/actor_id, action, subject_type/subject_id, endpoint_id, ip, user_agent, metadata json, prev_hash, hash | sem updated_at; trigger bloqueia UPDATE/DELETE; índice (occurred_at), (subject), (actor), (action) |
| `audit_chain` | id=1, last_seq, last_hash | linha única bloqueada (`FOR UPDATE`) a cada gravação de auditoria |
| `system_settings` | key, value json, updated_by | key único |

## Endpoints e agentes (Fase 1)
| Tabela | Colunas-chave | Restrições/Índices |
|---|---|---|
| `endpoints` | uuid, hostname, endpoint_group_id, status (`pending|active|disabled`), os_name/version/build/arch, manufacturer, model, serial, domain, current_user, last_seen_at, last_inventory_at, agent_version, `deleted_at` | índices: hostname, last_seen_at, status, group; **sem** unique em hostname/MAC |
| `agent_installations` | uuid, endpoint_id, public_key, key_fingerprint, status (`active|revoked`), agent_version, enrolled_at, revoked_at, revoked_by, revoke_reason, machine_fingerprint, `previous_public_key`, `previous_key_valid_until` | uuid único; **no máximo 1 instalação ativa por endpoint**: coluna gerada `active_endpoint_id = CASE WHEN status='active' THEN endpoint_id END` com índice único (portável MySQL/SQLite) |
| `enrollment_tokens` | token_hash (único), endpoint_group_id, created_by, expires_at, max_uses, uses, replaces_endpoint_id, revoked_at | |
| `agent_nonces` | (cache; não é tabela) | TTL 5 min |

## Inventário (Fase 2)
`hardware_inventory` (1:1 endpoint; JSON por seção + `section_status`), `network_interfaces`, `software_inventory` (único endpoint+nome+versão+source, `last_seen_at`, `removed_at`), `peripheral_inventory`, `inventory_snapshots` (snapshot_id único para idempotência; payload não guardado, só metadados e hash).

## Monitoramento (Fase 2/3)
`metric_samples` (endpoint_id, sampled_at, cpu, mem, uptime; único (endpoint, sample_id); índice (endpoint, sampled_at)) — raw 7 dias; `metric_rollups_hourly` — 13 meses; `volume_samples` junto ao rollup; `windows_services` (estado atual + monitorados); `process_snapshots` (somente sob demanda, TTL 24 h); `alert_rules`, `alerts` (estado: open/ack/resolved; `first_seen_at`, `last_seen_at`, dedupe_key único aberto), `endpoint_events` (TTL 30 dias).

## Tarefas e arquivos (Fase 3)
`remote_tasks` (uuid, endpoint_id, type, params json, risk, status, requested_by, approved_by, idempotency_key único por (requested_by), expires_at, max_attempts, ...), `remote_task_attempts` (attempt uuid, started/finished, exit_code, stdout/stderr limitados, duration), `file_inventory_policies`, `file_inventory_items`, `file_transfers` (direção, caminho canônico, size, sha256, status, retention_until).

## Suporte remoto e versões (Fase 4/5)
`remote_sessions` (operator, endpoint, status, started/ended, end_reason, quality, ws token hash, expires_at), `agent_versions` (version, sha256, signature, channel, mandatory), `agent_update_jobs`.

## Retenção
| Dado | Retenção padrão | Mecanismo |
|---|---|---|
| metric_samples raw | 7 d | job diário, agrega em hora |
| rollups horários | 13 meses | job mensal |
| endpoint_events | 30 d | job diário |
| process_snapshots | 24 h | job horário |
| alerts resolvidos | 12 meses | job |
| audit_logs | 24 meses (min. 12) | `audit:archive` privilegiado, auditado |
| file transfers temp | 7 d | job |
| remote_sessions metadados | 24 meses | job |
Todas configuráveis em `system_settings` (`retention.*`).
