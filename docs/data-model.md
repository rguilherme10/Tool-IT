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

## Inventário (Fase 2) ✔ implementado
| Tabela | Conteúdo | Restrições/Índices |
|---|---|---|
| `endpoint_inventories` | **Estado atual** 1:1 com o endpoint: `system`, `hardware`, `peripherals` (JSON) + `section_status` (estado da última coleta por seção, com motivo e hora) | `endpoint_id` único |
| `software_items` | Um registro por (nome, versão, arch, origem): `first_seen_at`, `last_seen_at`, `removed_at` | único `(endpoint_id, identity_hash)` — hash porque UNIQUE com colunas NULL não protege; índices `(endpoint_id, name)` e `(endpoint_id, removed_at)` |
| `network_interfaces` | Interfaces (substituídas a cada coleta `ok`) | índices `endpoint_id`, `mac` |
| `inventory_snapshots` | Só metadados do envio: `snapshot_id` único (idempotência), estado das seções, `changes` (JSON, nulo quando nada mudou: contagens e nomes de software adicionado/removido/reaparecido — nomes limitados a 50 —, `items` com o diff de sistema/hardware/periféricos/rede `{category,type,before,after}` limitado a 100, e `initial` na primeira coleta; alimenta a aba **Histórico**), hash do payload. **O payload não é guardado**: o histórico é só o diff. | `(endpoint_id, received_at)` |

Não implementado: `peripheral_inventory` separado (periféricos ficam em `endpoint_inventories.peripherals`), `file_inventory_*` (política de arquivos — pendente na task 006).

## Monitoramento (Fase 2/3)
| Tabela | Estado | Notas |
|---|---|---|
| `metric_samples` | ✔ | Histórico bruto, sem `created_at`/`updated_at`; único `(endpoint_id, sample_id)`; índices `(endpoint_id, sampled_at)` e `sampled_at`; retenção por `metrics:prune` (padrão 7 dias, agendado 03:15) |
| `alert_rules` | ✔ | uuid; `type` (disk, memory, cpu, agent_offline, agent_outdated), `threshold`, `clear_threshold` (histerese), `min_duration_seconds`, `clear_duration_seconds`, `repeat_interval_seconds`, `severity`, escopo opcional `endpoint_group_id` (inclui descendentes), `enabled` |
| `alerts` | ✔ | uuid; `status` open/acknowledged/resolved; `active_dedupe` (coluna virtual única enquanto ativo → no máximo 1 alerta ativo por regra+endpoint+volume); `opened_at`, `acknowledged_by/at`, `resolved_at`, `resolved_reason`; retenção `alerts:prune` (padrão 180 dias, 03:30) |
| `metric_rollups_hourly`, `windows_services`, `process_snapshots`, `endpoint_events` | pendente (task 007/008) | Agregação horária ainda **não** existe: hoje o histórico é só o bruto de 7 dias |

## Tarefas remotas (Fase 3) ✔ implementado (task 008)
| Tabela | Colunas-chave | Restrições/Índices |
|---|---|---|
| `remote_tasks` | uuid, endpoint_id (FK cascade), `type` (catálogo, [`api/task-types.md`](api/task-types.md)), `params` json, `risk` (low/medium/high), `state` (`pending_approval`, `available`, `running`, `completed`, `failed`, `cancelled`, `expired` — `App\Enums\TaskState`), `created_by`, `approved_by`/`approved_at`, `idempotency_key` (≤ 64), `expires_at` (prazo para ser pega; recomeça na aprovação e no requeue), `cancel_requested_at`, `result_summary` json (status, exit_code, duration_seconds, error/reason), timestamps | uuid único; índices `(endpoint_id, state)`, `(state, expires_at)`; único `(created_by, idempotency_key)` |
| `remote_task_attempts` | uuid, remote_task_id (FK cascade), `number`, `claimed_at`, `lease_expires_at`, `started_at`/`finished_at` (informados pelo Agent, informativos), `status` (nulo enquanto aberto; completed/failed/rejected/cancelled/expired), `exit_code`, `stdout`/`stderr` (text, ≤ 16 KiB após sanitização), `truncated`, `error` (≤ 255) | uuid único; único `(remote_task_id, number)` |

Transições só pelo `TaskService` com UPDATE condicional no estado (ver ADR-021); máximo de tentativas e timeout vêm do catálogo (não são colunas). **Retenção: nenhuma ainda** — tarefas e attempts (com a saída) crescem sem limpeza automática (L40).

## Arquivos (Fase 3)
`file_inventory_policies`, `file_inventory_items`, `file_transfers` (direção, caminho canônico, size, sha256, status, retention_until) — pendente (task 009).

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
| remote_tasks / remote_task_attempts | **sem retenção definida** (não implementado) | — (L40) |
Todas configuráveis em `system_settings` (`retention.*`).
