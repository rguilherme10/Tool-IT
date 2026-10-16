# Tarefas remotas tipadas — Plano de implementação

> **Para agentes executores:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para executar este plano tarefa a tarefa. Os passos usam checkbox (`- [ ]`).

**Goal:** Executar ações administrativas remotas por tarefas persistidas, tipadas, aprovadas quando arriscadas e auditadas (task 008), sem shell livre.

**Architecture:** O Manager guarda `remote_tasks` e `remote_task_attempts` com máquina de estados e claim atômico por `UPDATE` condicional; o Agent consulta `GET /tasks/next?wait=0` a cada ~10 s (e logo após o heartbeat sinalizar `tasks_available`), valida tipo/parâmetros contra catálogo local e executa handlers sobre APIs .NET (nunca `cmd`). Toda mudança de estado passa por `TaskService`, que audita.

**Tech Stack:** Laravel 13 / PHP 8.3 / PHPUnit (SQLite em memória); .NET `net8.0` / xUnit; Blade + Tailwind existentes.

**Spec:** `docs/superpowers/specs/2026-10-15-tarefas-remotas-design.md` (ler junto; contexto em `docs/tasks/008-tarefas-e-comandos-remotos.md` e `docs/api/agent-api-v1.md` §tasks).

## Global Constraints
- PowerShell, `files.*`, `screen.*` e notificação externa **fora** deste ciclo; módulos ausentes não aparecem na UI.
- Poll curto `wait=0`, intervalo padrão 10 s (`AgentSettings.TaskPollSeconds`); sem long-poll.
- stdout/stderr ≤ 16 KiB (16384 bytes) cada; concorrência 1 tarefa por endpoint no Agent.
- Risco: `low` (leitura), `medium` (`service.start|stop|restart` com nome na allowlist), `high` (`process.kill`, `agent.restart`, `service.*` fora da allowlist). Alto risco exige `tasks.create_high_risk` + permissão do recurso (`processes.kill` / `services.control`) e aprovação de **outro** usuário com `tasks.approve`.
- Processos protegidos (nunca encerrar): `System`, `csrss`, `wininit`, `winlogon`, `lsass`, `services`, `smss`, o próprio Agent, antivírus registrado. O Agent recusa mesmo com aprovação do Manager.
- Idempotentes: `system.info`, `process.list`, `service.list`, `inventory.refresh`, `service.start`, `service.stop` (até 3 tentativas). `service.restart`, `process.kill`, `agent.restart`: 1 tentativa.
- Timeouts (s): lista/info 30, `service.*` 60, `process.kill` 15, `agent.restart` 30, `inventory.refresh` 120. Tarefa não pega expira em 30 min (`toolit.tasks.pending_ttl_minutes`). Lease = timeout + 30 s; `progress` renova.
- Autorização sempre no backend, reconferida na criação **e** no claim; fora do escopo do usuário = 404; sem permissão = 403.
- Auditoria encadeada via `AuditLogger` em toda transição (`task.created|approved|rejected|cancelled|claimed|completed|failed|expired`).
- Item só vira `[x]` em docs com evidência em `docs/test-evidence.md`. Handlers que alteram sistema são testados só contra processo/serviço descartável criado pelo teste.
- Testes do Manager: `cd manager && php artisan test --filter=<Nome>`; Agent: `cd agent/Toolit && dotnet test AgentToolit.Tests/AgentToolit.Tests.csproj --filter <Nome>`.

## Review Focus
1. Resultado repetido ou tardio (após lease vencida/expiração/cancelamento): `409 task.conflict` para attempt não vigente; reenvio idêntico do vigente → `200` sem alterar nada.
2. Endpoint revogado ou desativado com tarefas abertas: claim recusado e tarefas viram `cancelled`.
3. Parâmetros hostis: nome de serviço com espaço/`;`/`..`/512 caracteres, `pid` como string ou negativo, objeto extra em `params` → recusados no Manager (422) e no Agent (`rejected`).
4. Saída com HTML, bytes de controle ou UTF-8 inválido: sanitizada na ingestão e escapada na UI.
5. Relógio do Agent deslocado: lease, expiração e ordenação usam só horário do servidor; `started_at`/`finished_at` do Agent são informativos e limitados (±24 h do servidor, senão descartados).
6. Operador do grupo A criando/aprovando/cancelando tarefa de endpoint do grupo B (BOLA) e criador aprovando a própria tarefa.

---

### Task 1: Enums, catálogo, migrations e models

**Files:**
- Create: `manager/app/Enums/TaskState.php`, `manager/app/Enums/TaskRisk.php`
- Create: `manager/app/Support/Tasks/TaskCatalog.php`, `manager/app/Support/Tasks/TaskDefinition.php`
- Create: `manager/database/migrations/2026_10_15_100001_create_remote_tasks_table.php`, `..._100002_create_remote_task_attempts_table.php`
- Create: `manager/app/Models/RemoteTask.php`, `manager/app/Models/RemoteTaskAttempt.php`, `manager/database/factories/RemoteTaskFactory.php`
- Modify: `manager/config/toolit.php` (bloco `tasks`)
- Test: `manager/tests/Unit/TaskStateTest.php`, `manager/tests/Unit/TaskCatalogTest.php`

**Interfaces:**
- Produces:
  - `enum TaskState: string { PendingApproval='pending_approval'; Available='available'; Running='running'; Completed='completed'; Failed='failed'; Cancelled='cancelled'; Expired='expired'; public function canTransitionTo(self $to): bool; public function isFinal(): bool }`
  - `enum TaskRisk: string { Low='low'; Medium='medium'; High='high' }`
  - `TaskDefinition` (readonly): `string $type; bool $idempotent; int $timeoutSeconds; int $maxAttempts; Permission[] $createPermissions`.
  - `TaskCatalog::definition(string $type): ?TaskDefinition`; `TaskCatalog::types(): list<string>`; `TaskCatalog::validate(string $type, array $params): array` (devolve params normalizados ou lança `Illuminate\Validation\ValidationException`); `TaskCatalog::risk(string $type, array $params): TaskRisk`; `TaskCatalog::permissionsFor(string $type, array $params): list<Permission>`; `TaskCatalog::serviceAllowlist(): list<string>` e `serviceProtected(): list<string>` (lêem `SystemSetting` `tasks.service_allowlist` / `tasks.service_protected`, com padrão em `config('toolit.tasks')`).
  - Models `RemoteTask` (`getRouteKeyName()` = `uuid`; `guarded=['*']`; relações `endpoint()`, `creator()`, `approver()`, `attempts()`, `currentAttempt()`) e `RemoteTaskAttempt`; casts: `state`→`TaskState`, `risk`→`TaskRisk`, `params`/`result_summary`→array, datas.

- [ ] **Step 1: Testes de unidade falhando.**
  `TaskStateTest::test_transition_table`: permitidas — PendingApproval→{Available,Cancelled,Expired}; Available→{Running,Cancelled,Expired}; Running→{Completed,Failed,Cancelled,Available}; qualquer estado final→nada. `test_final_states`.
  `TaskCatalogTest`: `test_risk_by_type` (`service.restart` com `Spooler` = medium; com `Foo` = high; `process.kill` = high; `process.list` = low); `test_rejects_hostile_service_names` (`"a b"`, `"a;b"`, `"../x"`, 257 chars → `ValidationException`); `test_rejects_bad_pid` (`"4 "`, `-1`, `3`, string, float); `test_rejects_unknown_type_and_extra_params`; `test_protected_service_is_rejected_for_stop_and_restart`; `test_permissions_for_high_risk_kill` = `[TasksCreateHighRisk, ProcessesKill]`; `test_permissions_for_medium_service` = `[TasksCreate, ServicesControl]`; `test_list_types_need_resource_view` (`process.list` → `[TasksCreate, ProcessesView]`, `service.list` → `[TasksCreate, ServicesView]`).
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter="TaskStateTest|TaskCatalogTest"` → FAIL (classes inexistentes).
- [ ] **Step 3: Implementar** enums e catálogo conforme as Global Constraints. Schemas: `service.*` → `name` regex `^[A-Za-z0-9_.$-]{1,128}$`; `process.kill` → `pid` int ≥ 5 e `name` string 1–128 (nome esperado do executável, protege contra reúso de PID); demais tipos aceitam `{}`. Padrões `config('toolit.tasks')`: `pending_ttl_minutes=30`, `service_allowlist=['Spooler','W32Time','BITS','wuauserv']`, `service_protected=['RpcSs','DcomLaunch','LSM','SamSs','EventLog','BFE','mpssvc','WinDefend','CryptSvc','Winmgmt','ToolitAgent']`.
- [ ] **Step 4: Migrations** (`php artisan make:migration`): `remote_tasks` — `uuid` único, `endpoint_id` FK cascade, `type` 40, `params` json, `risk` 8, `state` 20, `created_by` FK users, `approved_by`/`approved_at` null, `idempotency_key` 64 null, `expires_at`, `cancel_requested_at` null, `result_summary` json null, timestamps; índices `(endpoint_id,state)`, `(state,expires_at)`, único `(created_by,idempotency_key)`. `remote_task_attempts` — `uuid` único, `remote_task_id` FK cascade, `number`, `claimed_at`, `lease_expires_at`, `started_at`/`finished_at` null, `status` 16 null, `exit_code` null, `stdout`/`stderr` text null, `truncated` bool, `error` 255 null; único `(remote_task_id,number)`.
- [ ] **Step 5: Models + factory; rodar** o filtro do Step 2 → PASS; `php artisan migrate:fresh --env=testing` não deve falhar.
- [ ] **Step 6: Commit** — `git add manager && git commit -m "feat(tasks): estados, catálogo e modelo de tarefas remotas"`.

### Task 2: TaskService (criar, aprovar, cancelar, claim, resultado, expirar)

**Files:**
- Create: `manager/app/Services/Tasks/TaskService.php`, `manager/app/Exceptions/TaskConflictException.php`
- Test: `manager/tests/Feature/Tasks/TaskServiceTest.php`, `manager/tests/Feature/Tasks/TaskPermissionMatrixTest.php`

**Interfaces:**
- Consumes: Task 1; `AccessResolver::can(User, Permission, Endpoint)`; `AuditLogger::log(action, actor, subject, endpointId, metadata)`.
- Produces (`App\Services\Tasks\TaskService`):
  - `create(User $by, Endpoint $endpoint, string $type, array $params, ?string $idempotencyKey = null): RemoteTask` — valida com `TaskCatalog`, confere **todas** as `permissionsFor` no endpoint (`AuthorizationException` se faltar), endpoint ativo, instalação ativa; alto risco nasce `PendingApproval`, senão `Available`; mesma `(by, idempotencyKey)` devolve a tarefa existente.
  - `approve(User $by, RemoteTask $t): RemoteTask` — exige `tasks.approve` no endpoint, `$by->id !== $t->created_by` (`DomainException`), estado `PendingApproval`.
  - `reject(User $by, RemoteTask $t, ?string $reason): RemoteTask` — mesmas guardas; vai para `Cancelled`.
  - `cancel(User $by, RemoteTask $t): RemoteTask` — `tasks.cancel` no endpoint; `Available`/`PendingApproval` → `Cancelled`; `Running` → grava `cancel_requested_at`.
  - `claim(Endpoint $endpoint): ?array{task: RemoteTask, attempt: RemoteTaskAttempt}` — escolhe a mais antiga `Available` não expirada e faz `UPDATE … WHERE id=? AND state='available'`; só prossegue se 1 linha afetada; cria o attempt (`number` = tentativas anteriores + 1, lease = timeout+30 s); reconfere endpoint ativo e aprovação de alto risco.
  - `progress(RemoteTask $t, string $attemptUuid): void` renova a lease; `409` se não vigente.
  - `complete(RemoteTask $t, string $attemptUuid, array $result): RemoteTask` — `status` ∈ `completed|failed|rejected|cancelled`; `rejected` grava `Failed` com `error`; sanitiza e trunca stdout/stderr em 16384 bytes (UTF-8 válido, sem bytes de controle exceto `\n\t`); reenvio idêntico do attempt já encerrado devolve o estado atual; attempt não vigente → `TaskConflictException`.
  - `expireDue(): int` — `Available|PendingApproval` com `expires_at` vencido → `Expired`; `Running` com lease vencida → `Available` se `idempotent` e `number < maxAttempts`, senão `Failed` (`error='lease_expired'`); retorna quantidade tratada.
  - `cancelOpenFor(Endpoint $e, string $reason): int` — usado na revogação/desativação.
  - `availableFor(Endpoint $e): bool` e `cancelRequestedIds(Endpoint $e): list<string>`.

- [ ] **Step 1: Testes falhando** (`TaskServiceTest`): `test_low_risk_is_available_and_high_risk_needs_approval`; `test_creator_cannot_approve_own_task`; `test_approval_by_other_admin_makes_available`; `test_idempotency_key_returns_same_task`; `test_claim_takes_oldest_and_second_claim_gets_nothing` (duas chamadas seguidas: só a primeira recebe); `test_claim_refuses_pending_approval_and_expired`; `test_claim_refuses_revoked_installation`; `test_late_or_foreign_attempt_result_conflicts` (resultado de attempt antigo após retry → `TaskConflictException`); `test_duplicate_identical_result_is_idempotent`; `test_output_is_truncated_and_sanitized` (20 KiB, `"\x00<b>\xC3"` inválido); `test_agent_timestamps_are_clamped` (±24 h); `test_expire_due_pending_running_idempotent_and_not`; `test_cancel_running_sets_request_only`; `test_cancel_open_for_endpoint`; `test_every_transition_is_audited` (ações `task.*` no `audit_logs`, `audit:verify` passa).
  `TaskPermissionMatrixTest` (data provider papel × tipo × escopo): Admin/Operador/Suporte/Auditor/Consulta contra `process.list`, `service.restart` na allowlist, `process.kill`, `agent.restart`; Auditor nunca cria (`tasks.create*`); Suporte não cria `service.restart` nem `process.kill`; Operador cria `process.kill` mas não aprova; só Admin aprova; usuário do grupo A em endpoint do grupo B → negado em create/approve/cancel (BOLA).
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter="TaskServiceTest|TaskPermissionMatrixTest"`.
- [ ] **Step 3: Implementar `TaskService`** em transações; cada método chama `AuditLogger` (`metadata`: tipo, risco, attempt, status; nunca stdout inteiro — só tamanho). Claim com `UPDATE` condicional (não `SELECT … FOR UPDATE`, que o SQLite ignora).
- [ ] **Step 4: Rodar** o filtro → PASS. Registrar que concorrência real (MySQL) fica para a task 013.
- [ ] **Step 5: Commit** — `git commit -m "feat(tasks): TaskService com estados, claim atômico e auditoria"`.

### Task 3: API do Agent, expiração e integração com revogação

**Files:**
- Create: `manager/app/Http/Controllers/Api/Agent/V1/TaskNextController.php`, `TaskProgressController.php`, `TaskResultController.php`
- Create: `manager/app/Http/Requests/Api/Agent/TaskResultRequest.php`, `TaskProgressRequest.php`
- Create: `manager/app/Console/Commands/ExpireTasks.php` (`tasks:expire`)
- Modify: `manager/routes/api.php`, `manager/routes/console.php` (`tasks:expire` a cada minuto, `withoutOverlapping`, `onOneServer`), `HeartbeatController.php`, `RevocationService.php`, `EndpointManagementController::setStatus` (desativar chama `cancelOpenFor`)
- Modify: `manager/tests/Support/FakeAgent.php` (método `get(string $uri)` assinado, com query)
- Create: `tests/contracts/task-next.json`, `tests/contracts/task-result.json`
- Test: `manager/tests/Feature/Api/Agent/TasksApiTest.php`, `manager/tests/Feature/Console/ExpireTasksTest.php`

**Interfaces:**
- Consumes: `TaskService` (Task 2); middleware `agent.payload`, `agent.signed`, `throttle:agent-api`; `AgentApiError::response(code, message, status)`.
- Produces: rotas `GET /api/agent/v1/tasks/next`, `POST /tasks/{uuid}/progress`, `POST /tasks/{uuid}/result`; heartbeat com `tasks_available` e `cancel_task_ids` reais. `204` sem corpo quando não há tarefa; `409 task.conflict`; `404 request.not_found` para tarefa de outro endpoint.

- [ ] **Step 1: Testes falhando.** `TasksApiTest`: `test_next_returns_204_when_empty`; `test_next_returns_task_with_attempt_and_lease` (corpo = `task-next.json`: `task_id, attempt_id, type, params, timeout_seconds, lease_expires_at, risk`); `test_next_requires_valid_signature_including_query` (query `wait=0` adulterada → 401); `test_other_endpoint_task_is_404`; `test_result_roundtrip_and_conflict_409`; `test_result_body_validation` (status fora da lista, stdout > 64 KiB → 422); `test_heartbeat_reports_tasks_available_and_cancel_ids`; `test_revoked_agent_gets_403_on_tasks`; `test_revocation_cancels_open_tasks`; `test_deactivating_endpoint_cancels_open_tasks`. `ExpireTasksTest`: comando expira e reabre lease conforme `expireDue`.
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter="TasksApiTest|ExpireTasksTest"`.
- [ ] **Step 3: Implementar** rotas dentro do grupo `agent.payload` + `agent.signed`; `TaskNextController` aceita `wait` (0–30, ignorado: sempre resposta imediata) e valida o parâmetro; `FakeAgent::get()` monta a canônica com a query.
- [ ] **Step 4: Rodar** o filtro → PASS e depois a suíte de API existente: `php artisan test tests/Feature/Api` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(tasks): API do Agent (next/progress/result), expiração e revogação"`.

### Task 4: Painel — aba Tarefas, aprovações e detalhe

**Files:**
- Create: `manager/app/Http/Controllers/Web/TaskController.php`, `manager/resources/views/endpoints/tabs/tasks.blade.php`, `manager/resources/views/tasks/approvals.blade.php`, `manager/resources/views/tasks/show.blade.php`
- Modify: `manager/routes/web.php`, `manager/app/Http/Controllers/Web/EndpointController.php` (aba `tasks` com `tasks.view`; adicionar `'tasks'` à lista de abas que dão 403), `manager/resources/views/layouts/app.blade.php` (link "Aprovações" para quem tem `tasks.approve`)
- Test: `manager/tests/Feature/Web/TasksUiTest.php`

**Interfaces:**
- Consumes: `TaskService`, `TaskCatalog`, `AccessResolver`.
- Produces: rotas nomeadas `tasks.store` (`POST /endpoints/{uuid}/tasks`), `tasks.show` (`GET /tasks/{task}`), `tasks.cancel`, `tasks.approve`, `tasks.reject` (`POST /tasks/{task}/…`), `tasks.approvals` (`GET /tasks/approvals`). Rota-modelo por `uuid`; tarefa fora do escopo = 404.

- [ ] **Step 1: Testes falhando.** `test_tasks_tab_visible_only_with_tasks_view` (Consulta recebe 403 em `?tab=tasks`); `test_create_form_lists_only_allowed_types` (Suporte não vê `process.kill`); `test_store_creates_task_with_idempotency_key_and_redirects`; `test_store_hostile_params_returns_validation_error`; `test_high_risk_shows_pending_approval_and_creator_has_no_approve_button`; `test_approvals_page_lists_only_scoped_tasks`; `test_approve_by_other_admin_works_and_audited`; `test_cancel_requires_permission`; `test_detail_escapes_output` (stdout `<script>alert(1)</script>` aparece escapado); `test_no_powershell_files_or_screen_options_in_ui`.
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter=TasksUiTest`.
- [ ] **Step 3: Implementar** controller fino (toda regra no `TaskService`); formulário gera `idempotency_key` UUID a cada render; resultado em `<pre>` com `{{ }}`; estado vazio "Nenhuma tarefa" sem dados fictícios; lista paginada (20).
- [ ] **Step 4: Rodar** o filtro → PASS; revisão visual no navegador embutido (desktop e 375 px), registrando em `test-evidence.md` o que foi visto.
- [ ] **Step 5: Commit** — `git commit -m "feat(tasks): aba Tarefas, aprovações e detalhe no painel"`.

### Task 5: Agent — contratos, cliente e assinatura de GET com query

**Files:**
- Modify: `agent/Toolit/AgentToolit.Core/Protocol/Contracts.cs`, `agent/Toolit/AgentToolit.Core/Net/ManagerClient.cs`, `agent/Toolit/AgentToolit.Core/Config/AgentSettings.cs` (`TaskPollSeconds = 10`)
- Test: `agent/Toolit/AgentToolit.Tests/TaskClientTests.cs`

**Interfaces:**
- Produces (em `AgentToolit.Core.Protocol`): `record TaskAssignment(string TaskId, string AttemptId, string Type, JsonElement Params, int TimeoutSeconds, DateTimeOffset LeaseExpiresAt, string Risk)`; `record TaskResultRequest(string AttemptId, string Status, int? ExitCode, string? Stdout, string? Stderr, bool Truncated, DateTimeOffset StartedAt, DateTimeOffset FinishedAt, long DurationMs, string? Error)`; `record TaskProgressRequest(string AttemptId, int Percent)`.
  Em `ManagerClient`: `Task<TaskAssignment?> NextTaskAsync(CancellationToken ct)` (204 → `null`), `Task ProgressAsync(string taskId, TaskProgressRequest r, CancellationToken ct)`, `Task<bool> SendResultAsync(string taskId, TaskResultRequest r, CancellationToken ct)` (409 → `false`, não lança). `Sign` passa a separar `path` de `?query` e usar `RequestSigner.Canonical(method, path, query, …)`.

- [ ] **Step 1: Testes falhando** (`TaskClientTests`): `Next_task_signs_the_query_and_parses_assignment` (GET `/api/agent/v1/tasks/next?wait=0`; `sent.Path` sem query; assinatura verifica com `query="wait=0"`; corpo = `tests/contracts/task-next.json`); `Next_task_returns_null_on_204`; `Result_conflict_409_returns_false`; `Result_serializes_snake_case_and_omits_nulls`; `Heartbeat_response_with_cancel_ids_is_parsed`.
- [ ] **Step 2: Rodar e ver falhar** — `dotnet test AgentToolit.Tests/AgentToolit.Tests.csproj --filter TaskClientTests`.
- [ ] **Step 3: Implementar** os registros e métodos; `SendAsync` deve aceitar `204` (corpo vazio) sem tratá-lo como erro.
- [ ] **Step 4: Rodar** o filtro e a suíte inteira do Agent → PASS (nenhum teste existente quebra).
- [ ] **Step 5: Commit** — `git commit -m "feat(agent): cliente de tarefas e assinatura de GET com query"`.

### Task 6: Agent — validador, handlers, runner e poller

**Files:**
- Create em `agent/Toolit/AgentToolit.Core/Execution/`: `TaskSchemaValidator.cs`, `LocalTaskCatalog.cs`, `TaskOutcome.cs`, `ITaskHandler.cs`, `TaskRunner.cs`, `TaskPoller.cs`, `TaskCoordinator.cs`, `ProtectedProcessList.cs`, `IProcessApi.cs`, `IServiceApi.cs`, `Handlers/{SystemInfo,ProcessList,ProcessKill,ServiceList,ServiceControl,InventoryRefresh,AgentRestart}Handler.cs`, `Windows/{WindowsProcessApi,WindowsServiceApi}.cs`
- Modify: `agent/Toolit/AgentToolit.Core/Services/HeartbeatService.cs` (parâmetro opcional final `TaskCoordinator? tasks = null`: `Notify()` se `TasksAvailable`, `Cancel(ids)` para `CancelTaskIds`), `agent/Toolit/Toolit/Program.cs` (DI), `agent/Toolit/Toolit/Workers.cs` (`TaskWorker`)
- Test: `agent/Toolit/AgentToolit.Tests/TaskExecutionTests.cs`, `TaskHandlersTests.cs`, `TaskPollerTests.cs`

**Interfaces:**
- Consumes: Task 5 (`NextTaskAsync`, `SendResultAsync`, `ProgressAsync`); `CollectionService.CollectInventoryAsync(CancellationToken)`.
- Produces:
  - `record TaskOutcome(string Status, int? ExitCode, string? Stdout, string? Stderr, string? Error)`; `interface ITaskHandler { string Type { get; } Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct); }`.
  - `TaskSchemaValidator.Validate(string type, JsonElement p): (bool Ok, string? Error)` — mesmas regras do `TaskCatalog` do Manager (regex de nome de serviço, `pid ≥ 5`, sem propriedades extras).
  - `ProtectedProcessList.IsProtected(string name, int pid): bool` (lista fixa + `Environment.ProcessId` + nomes de antivírus de `IProtectedProcessSource`, falha silenciosa na consulta WMI).
  - `IProcessApi { IReadOnlyList<ProcessInfo> List(); ProcessInfo? Get(int pid); void Kill(int pid); }`; `IServiceApi { IReadOnlyList<ServiceInfo> List(); ServiceInfo? Get(string name); Task StartAsync/StopAsync/RestartAsync(string name, CancellationToken ct); }` com `ProcessInfo(int Pid, string Name, string? Path, string? User, double? CpuSeconds, double WorkingSetMb, DateTimeOffset? StartedAt)` e `ServiceInfo(string Name, string DisplayName, string State, string StartType)`.
  - `TaskRunner.RunAsync(TaskAssignment a, CancellationToken ct): Task<TaskResultRequest>` — tipo/parâmetros inválidos → `rejected` sem executar; timeout → `failed`/`error="timeout"`; cancelamento → `cancelled`; trunca saída a 16384 bytes.
  - `TaskCoordinator`: `Notify()`, `Task WaitAsync(TimeSpan, CancellationToken)`, `Register(string taskId, CancellationTokenSource)`, `Cancel(IEnumerable<string> ids)`.
  - `TaskPoller.TickAsync(CancellationToken): Task<TimeSpan>` — não registrado/revogado → ocioso como o heartbeat; sem tarefa → `TaskPollSeconds`; com tarefa → executa, envia resultado e devolve `TimeSpan.Zero`; erros de rede seguem `Backoff`.

- [ ] **Step 1: Testes falhando.** `TaskExecutionTests`: `Validator_rejects_hostile_names_and_pids` (mesmos casos do Manager); `Unknown_type_is_rejected_without_executing` (handler espião não chamado); `Protected_processes_are_refused` (`lsass`, `csrss`, `System`, `winlogon`, `wininit`, `services`, `smss`, pid do próprio processo; nomes com `.exe` e caixa diferente); `Kill_requires_matching_name` (PID reutilizado por outro executável → `rejected`); `Output_over_16KiB_is_truncated_on_utf8_boundary`; `Timeout_yields_failed_timeout`; `Cancel_via_coordinator_yields_cancelled`.
  `TaskHandlersTests` (reais, só leitura nesta máquina): `SystemInfo_returns_json_with_hostname_and_os`; `ProcessList_includes_current_process_and_fits_16KiB` (descarta os de menor memória e marca `truncated`); `ServiceList_returns_known_service_states`. Controle com descartáveis: `ProcessKill_kills_a_disposable_child_process` (o teste inicia `ping -n 60 127.0.0.1` ou `cmd /c timeout`, encerra por PID+nome, confirma saída); com fakes `IServiceApi`: `ServiceControl_calls_start_stop_restart_and_reports_missing_service`, `ServiceControl_refuses_protected_service`.
  `TaskPollerTests`: `Poller_idle_when_not_enrolled_or_revoked`; `Poller_runs_task_then_returns_zero_delay`; `Poller_posts_result_and_drops_it_on_409`; `Heartbeat_tasks_available_wakes_poller`; `Heartbeat_cancel_ids_cancel_running_task`.
- [ ] **Step 2: Rodar e ver falhar** — `dotnet test AgentToolit.Tests/AgentToolit.Tests.csproj --filter "TaskExecutionTests|TaskHandlersTests|TaskPollerTests"`.
- [ ] **Step 3: Implementar** usando `Process`, `ServiceController` e `Environment`; nada de `cmd`/`powershell` com parâmetros; `process.list` devolve JSON compacto ordenado por memória; `agent.restart` envia o resultado e só então pede parada do host com código de saída ≠ 0 (a recuperação do serviço o reinicia — comportamento real validado apenas na instalação como Administrador, L8); `inventory.refresh` chama `CollectInventoryAsync`.
- [ ] **Step 4: Ligar na DI** (`Program.cs`): registrar APIs Windows, handlers (`IEnumerable<ITaskHandler>`), `TaskCoordinator`, `TaskRunner`, `TaskPoller`, `TaskWorker` (usa `Loop.RunAsync` com `coordinator.WaitAsync`).
- [ ] **Step 5: Rodar** o filtro e depois `dotnet test AgentToolit.Tests/AgentToolit.Tests.csproj` completo → PASS.
- [ ] **Step 6: Commit** — `git commit -m "feat(agent): execução de tarefas tipadas com proteção de processos"`.

### Task 7: E2E com Agent real, documentação e evidências

**Files:**
- Create: `docs/api/task-types.md`, `tests/integration/tasks-smoke.php` (simulador PHP do ciclo `next`→`result`, no padrão de `agent-smoke.php`)
- Modify: `docs/api/agent-api-v1.md` (§tasks: `wait=0`, 204, `cancel_task_ids`), `docs/data-model.md`, `docs/permissions-matrix.md` (nota: Operador cria alto risco mas só Admin aprova), `docs/decisions.md` (ADR-021), `docs/known-limitations.md`, `docs/status.md`, `docs/tasks/008-…md` (checklist), `docs/tasks/README.md`, `docs/test-evidence.md`, `README.md` (contagem de testes)

- [ ] **Step 1: E2E real** — subir o Manager (`php artisan serve` com banco SQLite de E2E em arquivo cujo nome contenha `test`), registrar o `AgentToolit.exe` publicado (`dotnet publish`) com `TOOLIT_AGENT_DATA` isolado e token novo; criar via painel/`php artisan tinker` uma `process.list` e uma `system.info`; confirmar `completed` com saída real em ≤ 15 s. Criar um `process.kill` de alto risco, aprovar com outro admin e matar um processo descartável iniciado pelo operador do teste; tentar `process.kill` de `lsass` e confirmar `rejected` (e não encerrado). Registrar comandos, datas, saídas e latências em `test-evidence.md`.
- [ ] **Step 2: Suítes completas** — `cd manager && php artisan test` e `cd agent/Toolit && dotnet test AgentToolit.Tests/AgentToolit.Tests.csproj`; anotar totais reais. Rodar `composer audit` e `vendor/bin/pint --test`.
- [ ] **Step 3: Docs** — marcar `[x]` na task 008 só o que tem evidência (estados, claim, handlers de leitura, aprovação, UI, testes); manter `[ ]` PowerShell (adiado) e "reinício de serviço real do Windows" (sem VM). Registrar limitações: latência ≤ ~10 s; concorrência real do claim só em MySQL (task 013); allowlist/serviços protegidos sem tela de edição (somente `system_settings`/config); `agent.restart` depende da recuperação do serviço (L8).
- [ ] **Step 4: Commit** — `git commit -m "docs(tasks): evidências, contrato e ADR-021 das tarefas remotas"`.

---

## Auto-revisão
- Cobertura do spec: modelo/estados (T1–T2), catálogo e permissões (T1–T2), API e heartbeat (T3), painel/aprovações/auditoria (T2, T4), Agent (T5–T6), erros (T2/T3/T6), testes e aceite (T1–T7), documentação (T7).
- Nomes consistentes entre tarefas: `TaskService`, `TaskCatalog`, `TaskState`, `TaskAssignment`, `TaskResultRequest`, `TaskCoordinator`, `TaskOutcome`.
- Fora de escopo mantido: PowerShell, arquivos, tela, notificações externas.
