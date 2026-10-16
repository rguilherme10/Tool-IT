# Transferência de arquivos — Plano de implementação

> **Para agentes executores:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para executar este plano tarefa a tarefa. Os passos usam checkbox (`- [ ]`).

**Goal:** Enviar e recuperar arquivos de endpoints em chunks retomáveis, com SHA-256, política de diretórios por grupo, veto local do Agent e auditoria (task 009), sem execução.

**Architecture:** O Manager guarda `file_transfer_policies` (por grupo) e `file_transfers`; `TransferService` orquestra upload em chunks pelo navegador, monta o arquivo, calcula o hash e cria a tarefa `file.send`/`file.fetch` (risco médio) pelo `TaskService` da 008. O Agent obtém os metadados e transfere chunks por rotas assinadas, valida tudo com um `PathGuard` espelhado (mesmo vetor de testes PHP/C#) e grava em `.part` + rename atômico. Os handlers `file.*` implementam `IContextTaskHandler` para enviar `progress`.

**Tech Stack:** Laravel 13 / PHP 8.3 / PHPUnit (SQLite em memória); .NET `net8.0` / xUnit; Blade + Tailwind + JS puro (sem build novo).

**Spec:** `docs/superpowers/specs/2026-10-16-transferencia-de-arquivos-design.md` (ler junto; contexto em `docs/tasks/009-transferencia-de-arquivos.md`, `docs/api/task-types.md`, `docs/threat-model.md` T9). Base de código: 008 já integrada em `main` (`TaskService`, `TaskCatalog`, `TaskRunner`, `ITaskHandler`).

## Global Constraints
- Risco **medium** para `file.send` e `file.fetch` (decisão do dono; sem aprovação). Permissões de criação: `tasks.create` + `files.transfer_send` / `files.transfer_fetch`. Auditor e Consulta nunca criam; Suporte só `fetch`.
- Sem política efetiva para o endpoint → transferência **desligada**. Política efetiva = a do grupo mais próximo na árvore; `endpoint_group_id` nulo = padrão global.
- Extensões **sempre bloqueadas em código**: `exe dll bat cmd ps1 psm1 vbs vbe js jse wsf msi msp scr com pif lnk hta jar cpl reg sys drv`.
- `max_bytes` padrão 104857600; `allow_overwrite` padrão falso; retenção 7 dias (`toolit.transfers.retention_days`); chunk do navegador 1 MiB; chunk do Agent 2 MiB; banda do Agent 4 MiB/s (`AgentSettings.TransferBytesPerSecond`); timeout das tarefas 900 s; idempotentes, 3 tentativas.
- Veto local do Agent: `AgentSettings.TransferRoots` (padrão `%ProgramData%\Toolit\Inbox` e `…\Outbox`; `…\Logs` só fetch); vale política ∩ raízes locais.
- Nenhum caminho vem dos parâmetros da tarefa (só `transfer_id`); o Agent lê tudo de `GET /transfers/{id}` e confere endpoint e direção.
- Nome original do arquivo é só metadado escapado; o Manager grava por UUID. Download com `Content-Disposition: attachment` e `X-Content-Type-Options: nosniff`.
- Autorização sempre no backend: fora do escopo = 404, sem permissão = 403; auditoria encadeada em todo evento listado no spec.
- **Segurança dos testes (obrigatória):** nenhum teste toca diretórios reais do sistema nem serviços reais; só diretórios temporários criados pelo teste. Item só vira `[x]` com evidência em `docs/test-evidence.md`.
- Ambiente: o repositório está no share de rede `Z:`; `dotnet test` direto em `Z:` falha (testhost) → `dotnet build` em `Z:`, copiar `bin/Debug/net8.0` e `tests/contracts` para um diretório local e rodar `dotnet test <local>/AgentToolit.Tests.dll`; Pint não grava em `Z:` → `php vendor/bin/pint --test` e corrigir à mão; `npm run build` falha em `Z:`; `git merge` pode falhar no share (usar fast-forward + `update-ref` se necessário).
- Comandos: Manager `cd manager && php artisan test --filter=<Nome>`; Agent conforme acima.

## Review Focus
1. Caminho hostil nos dois lados, em todos os pontos de entrada: `..`, `.`, UNC, ADS, reservados (`CON.txt`), 8.3, ponto/espaço final, bidi/zero-width, homógrafos de `/ \ : .`, 261 caracteres, junction/symlink no meio do caminho, destino que vira link entre checagem e uso.
2. Extensão enganosa: `relatorio.pdf.exe`, `a.EXE`, `a.exe.`, `a.exe ` (espaço), `.exe` oculto por Unicode; nome só com extensão.
3. Chunks fora de ordem, duplicados, faltando, de tamanho errado, `n` negativo ou ≥ `chunks_total`; upload concluído com chunk faltando; tamanho declarado ≠ soma real; arquivo maior que `max_bytes` declarado menor.
4. Hash: chunk com `X-Chunk-Sha256` errado; hash total divergente → descarta, `failed`; destino existente com mesmo hash → sucesso idempotente; com hash diferente e sem `overwrite` → recusa.
5. BOLA: operador do grupo A criando/baixando/cancelando transferência do grupo B; Agent lendo/escrevendo transferência de outro endpoint; reuso de `transfer_id` de outro endpoint em tarefa.
6. Revogação/desativação/cancelamento/política removida no meio; upload abandonado; tarefa expirada com transferência `ready`.

---

### Task 1: PathGuard (PHP), vetor compartilhado e política efetiva

**Files:**
- Create: `manager/app/Support/Transfers/PathGuard.php`, `tests/contracts/path-guard-cases.json`
- Create: `manager/app/Models/FileTransferPolicy.php`, `manager/database/factories/FileTransferPolicyFactory.php`, `manager/database/migrations/2026_10_16_100001_create_file_transfer_policies_table.php`
- Create: `manager/app/Services/Transfers/TransferPolicyResolver.php`
- Modify: `manager/config/toolit.php` (bloco `transfers`: `retention_days=7`, `max_bytes=104857600`, `browser_chunk_bytes=1048576`, `agent_chunk_bytes=2097152`)
- Test: `manager/tests/Unit/PathGuardTest.php` (estende `Tests\TestCase` só se precisar de config; preferir `PHPUnit\Framework\TestCase`), `manager/tests/Feature/Transfers/PolicyResolverTest.php`

**Interfaces:**
- Produces:
  - `final class PathGuard` (estático, sem acessar disco): `validateDirectory(string $path): ?string` (motivo do erro ou `null`), `validateFileName(string $name): ?string`, `normalizeName(string $name): string` (NFC), `isInside(string $path, array $dirs): bool` (sem diferenciar maiúsculas; exige fronteira de separador), `join(string $dir, string $name): string`, `hasBlockedExtension(string $name): bool` (olha **todas** as extensões do nome, após remover pontos/espaços finais), `forbiddenPolicyDirectory(string $dir): bool` (raiz de unidade, `C:\Windows*`, `C:\Program Files*`, `C:\Users` exato), const `BLOCKED_EXTENSIONS`.
  - `tests/contracts/path-guard-cases.json`: `{"directories":[{"path","ok","why"}], "files":[{"name","ok","why"}], "inside":[{"path","dirs":[...],"ok"}], "blocked_extensions":[{"name","blocked"}]}` — consumido também pelo C# (Task 6).
  - Model `FileTransferPolicy` (`guarded=['*']`, casts json para `send_dirs`, `fetch_dirs`, `allowed_extensions`; bool `allow_overwrite`; `group()`); migration com `endpoint_group_id` nulo único (índice único composto tratando nulo: use uma coluna `scope_key` string única = `group:<id>` ou `global`).
  - `TransferPolicyResolver::forEndpoint(Endpoint $e): ?FileTransferPolicy` (grupo do endpoint → ancestrais → global; `null` = desligado). Reutilize a árvore de `EndpointGroup` (veja `EndpointGroup::expandWithDescendants` e o `parent_id`).

- [ ] **Step 1: Escrever o vetor `path-guard-cases.json`** com pelo menos: diretórios válidos (`C:\ProgramData\Toolit\Inbox`, com acento `C:\Dados\Relatórios`), inválidos (`..\x`, `C:\a\..\b`, `C:\a\.\b`, `\\srv\share\x`, `C:\a\b:ads`, `C:\a\CON`, `C:\a\nul.txt`, `C:\PROGRA~1\x`, `C:\a\b.` , `C:\a\b `, `C:\a\\b`, 261 caracteres, componente de 121, `C:\a\x\u202Ey`, `C:\a\x\u200By`, `C:\a\x\uFF0Fy` (barra de largura total), `C:\a\x\u2215y`, controle `C:\a\x\u0007y`, relativo `a\b`, `/` unix); nomes válidos (`relatório final (1).pdf`) e inválidos (`CON`, `nul.txt`, `a:b`, `a/b`, `a\b`, `..`, `.`, vazio, `a.`, `a `); `inside` (fronteira: `C:\Dados\InboxX` **não** está em `C:\Dados\Inbox`; caixa diferente está; `C:\Dados\Inbox\sub\a.txt` está); `blocked_extensions` (`a.exe`, `a.EXE`, `a.exe.`, `a.exe `, `a.pdf.exe`, `a.exe.txt` → bloqueado; `a.pdf`, `exe` (sem ponto) → livre; `.exe` → bloqueado).
- [ ] **Step 2: Testes falhando** — `PathGuardTest::test_shared_vector_directories|files|inside|blocked_extensions` (um data provider por seção lendo `base_path('../tests/contracts/path-guard-cases.json')`), `test_forbidden_policy_directories` (`C:\`, `D:\`, `C:\Windows\System32`, `C:\Program Files (x86)\x`, `C:\Users`; ok: `C:\Users\Publico\Inbox`), `test_join_never_escapes`. `PolicyResolverTest`: grupo com política vence ancestral; sem política em nenhum → `null`; global usado como fallback; política de outro ramo ignorada.
- [ ] **Step 3: Rodar e ver falhar** — `php artisan test --filter="PathGuardTest|PolicyResolverTest"`.
- [ ] **Step 4: Implementar** `PathGuard` (regras do spec §Validação de caminho; NFKC via `Normalizer`/`intl`, que o projeto já exige), model, migration, factory, resolver e config. Mensagens de motivo em português curto e estável (o teste compara só `ok`, não o texto).
- [ ] **Step 5: Rodar** o filtro → PASS; `php vendor/bin/pint --test <arquivos>`.
- [ ] **Step 6: Commit** — `git commit -m "feat(transfers): PathGuard, vetor compartilhado e política efetiva por grupo"`.

### Task 2: Tela de política por grupo

**Files:**
- Create: `manager/app/Http/Controllers/Web/FileTransferPolicyController.php`, `manager/resources/views/file-policies/{index,form}.blade.php`
- Modify: `manager/routes/web.php` (resource `file-policies` sob `mfa.admin`, exige `settings.manage` global), `manager/resources/views/layouts/app.blade.php` (link para quem tem `settings.manage`)
- Test: `manager/tests/Feature/Web/FilePoliciesUiTest.php`

**Interfaces:**
- Consumes: `PathGuard` e `FileTransferPolicy` (Task 1); `AuditLogger::log(action, actor, subject, endpointId, metadata)`; `AccessResolver::canGlobally(User, Permission::SettingsManage)`.
- Produces: rotas nomeadas `file-policies.index|create|store|edit|update|destroy`; ações de auditoria `transfer_policy.created|updated|deleted` (metadata: escopo, diretórios, extensões, `max_bytes`, `allow_overwrite`).

- [ ] **Step 1: Testes falhando** (`FilePoliciesUiTest`): só `settings.manage` global acessa (Operador/Suporte/Auditor → 403); criar política válida grava e audita; diretório inválido pelo `PathGuard` e diretório proibido (`C:\Windows`) → erro de validação e nada gravado; extensão executável na lista de permitidas é rejeitada; `max_bytes` fora de 1 B–1 GiB rejeitado; uma política por escopo (duplicada → erro); editar e remover auditam; valores iniciais sugeridos do spec aparecem no formulário novo; saída escapada.
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter=FilePoliciesUiTest`.
- [ ] **Step 3: Implementar** controller fino (validação via `PathGuard` + regras do Laravel; listas como `textarea` com uma entrada por linha) e views no padrão de `groups/` e `alert-rules/`.
- [ ] **Step 4: Rodar** → PASS; Pint `--test`.
- [ ] **Step 5: Commit** — `git commit -m "feat(transfers): tela de política de transferência por grupo"`.

### Task 3: Transferências, catálogo `file.*` e ciclo de vida no Manager

**Files:**
- Create: `manager/app/Enums/TransferStatus.php`, `manager/app/Enums/TransferDirection.php`, `manager/app/Models/FileTransfer.php`, `manager/database/factories/FileTransferFactory.php`, `manager/database/migrations/2026_10_16_100002_create_file_transfers_table.php`
- Create: `manager/app/Services/Transfers/TransferService.php`, `manager/app/Console/Commands/PruneTransfers.php` (`transfers:prune`, agendado diário 03:45)
- Modify: `manager/app/Support/Tasks/TaskCatalog.php` (tipos `file.send`, `file.fetch`), `manager/app/Services/Tasks/TaskService.php` (gancho de término), `manager/routes/console.php`
- Test: `manager/tests/Feature/Transfers/TransferServiceTest.php`, `manager/tests/Feature/Transfers/TransferCatalogTest.php`, `manager/tests/Feature/Console/PruneTransfersTest.php`

**Interfaces:**
- Consumes: Task 1 (`PathGuard`, `TransferPolicyResolver`); `TaskService::create(User $by, Endpoint $endpoint, string $type, array $params, ?string $idempotencyKey = null): RemoteTask`; `TaskService::cancelOpenFor`.
- Produces:
  - Enums `TransferStatus` (`Uploading|Ready|Transferring|Verifying|Completed|Failed|Cancelled|Expired`, com `canTransitionTo`/`isFinal`) e `TransferDirection` (`Send|Fetch`).
  - Catálogo: `file.send` e `file.fetch` — `TaskDefinition(type, idempotent=true, timeout=900, maxAttempts=3, createPermissions=[FilesTransferSend|FilesTransferFetch])`; `validate()` aceita só `{transfer_id: <uuid string>}`; `risk()` = `TaskRisk::Medium`; **não** entram em `TaskForm::TYPES` (só `TransferService` cria).
  - `TransferService`:
    - `createSend(User $by, Endpoint $e, string $originalName, int $size, string $dir, ?string $subName): FileTransfer` (valida política efetiva, `dir` ∈ `send_dirs`, extensão ∈ `allowed_extensions` e não bloqueada, `size ≤ max_bytes`, `PathGuard`; status `Uploading`);
    - `storeUploadChunk(FileTransfer $t, int $n, string $bytes): void` (idempotente; `n` em `[0, ceil(size/browser_chunk))`; tamanho exato exceto o último) e `receivedUploadChunks(FileTransfer $t): list<int>`;
    - `completeUpload(User $by, FileTransfer $t): FileTransfer` (todos os chunks, soma = `size`, monta `file.bin`, SHA-256, `Ready`, cria a tarefa `file.send {transfer_id}` via `TaskService::create`, grava `remote_task_id`, audita);
    - `createFetch(User $by, Endpoint $e, string $remotePath): FileTransfer` (`PathGuard`, ∈ `fetch_dirs`; `Ready`; cria a tarefa `file.fetch`);
    - Agente: `metadataFor(FileTransfer $t): array`, `begin(FileTransfer $t, int $size): void` (fetch; `size ≤ max_bytes`; fixa `size` e `chunks_total`; `Transferring`), `chunkBytes(FileTransfer $t, int $n): string`, `storeAgentChunk(FileTransfer $t, int $n, string $bytes, string $sha256): void`, `complete(FileTransfer $t, string $sha256, int $size): FileTransfer`;
    - `cancel(User $by, FileTransfer $t)`, `onTaskFinished(RemoteTask $task): void` (chamado pelo `TaskService` ao levar uma tarefa `file.*` a estado final: `failed/cancelled/expired` → transferência correspondente falha/cancela/expira; `completed` sem `complete` do Agent → `Failed`), `prune(): int`.
  - Auditoria: `transfer.created|upload_completed|completed|failed|cancelled|downloaded`.

- [ ] **Step 1: Testes falhando.** `TransferCatalogTest`: `file.*` válidos só com UUID; extra/ausente → `ValidationException`; risco medium; permissões `[TasksCreate, FilesTransferSend|Fetch]`; ausentes do formulário genérico. `TransferServiceTest`: sem política → recusa; diretório fora de `send_dirs`, extensão fora da lista, `.pdf.exe`, tamanho acima do `max_bytes`, nome hostil → recusa sem criar; fluxo feliz de envio (chunks fora de ordem e duplicados, montagem, hash == `hash_file('sha256')`, tarefa criada e `available`); chunk de tamanho errado/`n` inválido/chunk faltando → recusa; BOLA e papéis (Suporte não envia, Auditor nunca, grupo B negado); fetch: `begin` com `size > max_bytes` → recusa, chunks do Agent com hash errado → recusa, `complete` com hash divergente → `Failed`, montagem e hash certos → `Completed`; `onTaskFinished` (cancelamento da tarefa cancela a transferência; revogação via `cancelOpenFor` também); transições inválidas lançam; todas as ações auditadas e `audit:verify` passa. `PruneTransfersTest`: apaga blobs > 7 dias, mantém metadados, não toca ativas.
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter="TransferServiceTest|TransferCatalogTest|PruneTransfersTest"`.
- [ ] **Step 3: Implementar** com armazenamento em `storage/app/private/transfers/{uuid}/` (disco `local`), máquina de estados em UPDATE condicional (mesmo padrão do `TaskService::move`), sanitização do nome (`PathGuard::normalizeName`), e o gancho `onTaskFinished` no ponto único onde `TaskService` finaliza tarefas (sem duplicar regra).
- [ ] **Step 4: Rodar** o filtro e a suíte inteira → PASS; Pint `--test`.
- [ ] **Step 5: Commit** — `git commit -m "feat(transfers): TransferService, tipos file.* e retenção"`.

### Task 4: Rotas do navegador (upload em chunks e download) e API do Agent

**Files:**
- Create: `manager/app/Http/Controllers/Web/TransferController.php` (criar, upload, status, completar, cancelar, baixar)
- Create: `manager/app/Http/Controllers/Api/Agent/V1/Transfer{Show,Begin,ChunkGet,ChunkPut,Complete}Controller.php`, `manager/app/Http/Requests/Api/Agent/Transfer{Begin,Complete}Request.php`
- Modify: `manager/routes/web.php`, `manager/routes/api.php` (PUT de chunk com `agent.payload:2621440`), `manager/tests/Support/FakeAgent.php` (helper `putRaw(string $uri, string $bytes, array $headers = [])`)
- Create: `tests/contracts/transfer-metadata.json`
- Test: `manager/tests/Feature/Web/TransfersWebTest.php`, `manager/tests/Feature/Api/Agent/TransfersApiTest.php`

**Interfaces:**
- Consumes: `TransferService` (Task 3); `AgentApiError::response`; padrão dos controllers da 008 (`TaskNextController`, `TaskResultController`).
- Produces:
  - Web (sessão + CSRF, nomes): `transfers.store` `POST /endpoints/{uuid}/transfers`; `transfers.upload` `PUT /transfers/{transfer}/upload/{n}` (corpo bruto ≤ 1 MiB + folga); `transfers.upload.status` `GET /transfers/{transfer}/upload`; `transfers.upload.complete` `POST /transfers/{transfer}/upload/complete`; `transfers.fetch` `POST /endpoints/{uuid}/transfers/fetch`; `transfers.cancel`; `transfers.download` `GET /transfers/{transfer}/download` (só fetch `Completed`; auditoria `transfer.downloaded`; `Content-Disposition: attachment; filename*=UTF-8''…` com nome sanitizado; `X-Content-Type-Options: nosniff`; `Cache-Control: no-store`).
  - Agent (assinadas): `GET /transfers/{id}` → `{transfer_id, direction, size, sha256, chunk_size, chunks_total, remote_path, overwrite, received_chunks}`; `GET /transfers/{id}/chunks/{n}` → `application/octet-stream` + `X-Chunk-Sha256`; `PUT /transfers/{id}/chunks/{n}` (corpo bruto; `X-Chunk-Sha256` obrigatório; `422 transfer.chunk_hash` se divergir); `POST /transfers/{id}/begin {size}`; `POST /transfers/{id}/complete {sha256,size}` (`200 {status}`). Transferência de outro endpoint/inexistente → `404 request.not_found`; direção/estado inválido → `409 transfer.conflict`; chunk fora do intervalo → `422`.

- [ ] **Step 1: Testes falhando.** Web: permissões × papel × escopo para cada rota (Suporte: `fetch` ok, `store`(envio) 403; grupo B → 404 sem vazar); upload completo ponta a ponta via HTTP com chunks fora de ordem e retomada (`upload.status` lista os recebidos); `complete` com chunk faltando → 422; download só de fetch concluído, cabeçalhos exatos, nome com `"`/`\n`/Unicode sanitizado, auditoria; CSRF exigido; transferência fora de `Uploading` não aceita chunk. API: `GET` metadados bate com `transfer-metadata.json` (mesmas chaves); chunks do envio têm 2 MiB exceto o último e `X-Chunk-Sha256` correto; `PUT` com hash errado → 422 e nada gravado; assinatura inválida/adulterada → 401; Agent de outro endpoint → 404; corpo > teto → 413 antes de assinar; `begin` com tamanho acima de `max_bytes` → 422; `complete` com hash divergente → transferência `Failed`; agente revogado → 403.
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter="TransfersWebTest|TransfersApiTest"`.
- [ ] **Step 3: Implementar** controllers finos (toda regra no `TransferService`); `AuthorizationException`→403 e fora do escopo→404 pelo padrão `resolveVisible` já usado em `TaskController`; chunks do navegador lidos com `$request->getContent()` (nunca multipart).
- [ ] **Step 4: Rodar** o filtro, `php artisan test tests/Feature/Api` e a suíte → PASS; Pint `--test`.
- [ ] **Step 5: Commit** — `git commit -m "feat(transfers): rotas do navegador e API do Agent para chunks"`.

### Task 5: Aba Arquivos (formulários, JS de upload retomável, lista)

**Files:**
- Create: `manager/resources/views/endpoints/tabs/files.blade.php`, `manager/resources/js/transfer-upload.js` **ou** `manager/public/js/transfer-upload.js` (se `npm run build` não rodar no share, use arquivo estático em `public/js` sem build e registre a escolha)
- Modify: `manager/app/Http/Controllers/Web/EndpointController.php` (aba `files` com `files.transfer_send` ou `files.transfer_fetch`; incluir `files` na lista de abas que dão 403), `manager/app/Support/Tasks/TaskForm.php` (rótulos de `file.send`/`file.fetch` apenas para exibição no detalhe da tarefa, sem entrar em `creatableTypes`)
- Test: `manager/tests/Feature/Web/FilesTabTest.php`

**Interfaces:**
- Consumes: rotas da Task 4; `TransferPolicyResolver`; `PathGuard`.
- Produces: aba **Arquivos**: bloco "Enviar" (diretório da política em `<select>`, arquivo, subnome opcional; JS fatia em 1 MiB, mostra progresso, retoma consultando `upload.status`, trata erro por chunk com 3 repetições), bloco "Recuperar" (caminho remoto), lista paginada (20) de transferências com estado, tamanho, SHA-256, operador, datas, link de download e botão cancelar; aviso "Sem política de transferência" quando desligado; estados vazios sem dados fictícios; nenhuma opção de execução.

- [ ] **Step 1: Testes falhando** (`FilesTabTest`): aba visível só com as permissões; Suporte vê só "Recuperar"; sem política mostra o aviso e nenhum formulário; o `<select>` lista só `send_dirs`; lista escapa nome original (`<script>`); download aparece só para fetch concluído e com permissão; paginação mantém `tab=files`; nenhum texto de execução; token CSRF presente nos formulários e exposto ao JS (`meta`).
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter=FilesTabTest`.
- [ ] **Step 3: Implementar** a aba e o JS (sem dependências; `fetch` com `PUT` e `Content-Type: application/octet-stream`; `crypto.subtle` não é necessário). Revisão visual no navegador embutido (desktop e 375 px) com banco descartável cujo nome contenha "test"; registre o que foi visto e que o CSS pode não ser o build real (L38).
- [ ] **Step 4: Rodar** o filtro e a suíte → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(transfers): aba Arquivos com upload retomável"`.

### Task 6: Agent — PathGuard, configurações e cliente de transferência

**Files:**
- Create: `agent/Toolit/AgentToolit.Core/Transfers/PathGuard.cs`, `Transfers/ITransferApi.cs`, `Transfers/TransferModels.cs`
- Modify: `agent/Toolit/AgentToolit.Core/Net/ManagerClient.cs` (implementa `ITransferApi`; envio/recebimento binário assinado), `Protocol/Contracts.cs`, `Config/AgentSettings.cs`
- Test: `agent/Toolit/AgentToolit.Tests/PathGuardTests.cs`, `TransferClientTests.cs`

**Interfaces:**
- Consumes: `tests/contracts/path-guard-cases.json` e `transfer-metadata.json` (carregar como `CoreTests` carrega `signature-vector.json`); `RequestSigner.Canonical`.
- Produces:
  - `PathGuard` (C#): `static string? ValidateDirectory(string)`, `ValidateFileName(string)`, `NormalizeName`, `IsInside(string path, IEnumerable<string> dirs)`, `HasBlockedExtension(string)` (mesmos resultados do vetor); `static string? ValidateOnDisk(string fullPath, IReadOnlyList<string> allowedRoots)` — `GetFullPath`, recusa qualquer componente existente que seja reparse point (`FileAttributes.ReparsePoint`), confere prefixo contra as raízes; `static string? ValidateOpenHandle(SafeFileHandle h, IReadOnlyList<string> roots)` via `GetFinalPathNameByHandle`.
  - `AgentSettings`: `TransferRoots` (`List<string>`, padrão por `AgentPaths`/`%ProgramData%\Toolit\Inbox|Outbox`; `…\Logs` só para fetch → `TransferFetchOnlyRoots`), `TransferBytesPerSecond` (padrão 4 MiB/s, limite [64 KiB/s, 64 MiB/s] no setter).
  - `ITransferApi`: `Task<TransferInfo> GetAsync(string id, CT)`, `Task<(byte[] Bytes, string Sha256)> GetChunkAsync(string id, int n, CT)`, `Task PutChunkAsync(string id, int n, byte[] bytes, string sha256, CT)`, `Task BeginAsync(string id, long size, CT)`, `Task<bool> CompleteAsync(string id, string sha256, long size, CT)` (409/422 → exceção tipada `ManagerRejectedException`); `record TransferInfo(string TransferId, string Direction, long Size, string? Sha256, int ChunkSize, int ChunksTotal, string RemotePath, bool Overwrite, IReadOnlyList<int> ReceivedChunks)`.

- [ ] **Step 1: Testes falhando.** `PathGuardTests`: o vetor compartilhado (4 seções) com os mesmos resultados do PHP; `ValidateOnDisk` com diretório temporário criado pelo teste: arquivo normal ok; **junction descartável** (`mklink /J` ou `Directory.CreateSymbolicLink` — pule o teste com mensagem explícita se o ambiente não puder criar o link, nunca finja) dentro da raiz apontando para fora → recusado; `..` resolvido para fora → recusado; prefixo parecido (`InboxX`) → recusado; raiz local ∩ política vazia → recusado. `TransferClientTests` (padrão `ClientAndServiceTests`): `GetAsync` assina e desserializa o JSON de `transfer-metadata.json`; `GetChunkAsync` devolve bytes e lê `X-Chunk-Sha256`; `PutChunkAsync` envia corpo bruto com `X-Chunk-Sha256`, e a assinatura verifica sobre os bytes exatos; 404/409/422 mapeados; `AgentSettings.TransferBytesPerSecond` respeita os limites.
- [ ] **Step 2: Rodar e ver falhar** (build falha por tipos inexistentes).
- [ ] **Step 3: Implementar** reaproveitando `SendAsync`/`Sign` com um caminho para corpo/retorno binário (sem passar por `Json`); cabeçalho `X-Chunk-Sha256` em hex minúsculo.
- [ ] **Step 4: Rodar** os filtros e a suíte do Agent → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(agent): PathGuard, cliente de transferência e configurações"`.

### Task 7: Agent — handlers `file.send`/`file.fetch`, progress e vazão

**Files:**
- Create: `agent/Toolit/AgentToolit.Core/Execution/IContextTaskHandler.cs` (+ `TaskContext`), `Execution/Handlers/FileSendHandler.cs`, `FileFetchHandler.cs`, `Transfers/Throttle.cs`
- Modify: `Execution/LocalTaskCatalog.cs` (`file.send`/`file.fetch`, timeout 900), `Execution/TaskSchemaValidator.cs` (`{transfer_id}` UUID, sem chaves extras), `Execution/TaskRunner.cs` (usa `IContextTaskHandler` quando houver; envia `progress` renovando a lease), `Toolit/Program.cs` (DI)
- Test: `agent/Toolit/AgentToolit.Tests/FileHandlersTests.cs`

**Interfaces:**
- Consumes: Task 6 (`ITransferApi`, `PathGuard`, `AgentSettings`); `TaskRunner`/`ITaskHandler` da 008; `ManagerClient.ProgressAsync(taskId, TaskProgressRequest, ct)`.
- Produces: `record TaskContext(string TaskId, string AttemptId, Func<int, CancellationToken, Task> ReportProgress)`; `interface IContextTaskHandler : ITaskHandler { Task<TaskOutcome> ExecuteAsync(JsonElement parameters, TaskContext context, CancellationToken ct); }`; `FileSendHandler(ITransferApi api, AgentSettings settings, TimeProvider time)` e `FileFetchHandler(...)`.
- Comportamento `file.send`: `GetAsync` → recusa (`rejected`) se direção ≠ send, política ∩ raízes locais, `PathGuard` ou extensão bloqueada falham; espaço em disco insuficiente → `failed`; destino existente com o mesmo SHA-256 → `completed` idempotente; existente com hash diferente e `overwrite=false` → `rejected`; baixa chunks para `.toolit-{id}.part` retomando pelo tamanho do `.part` (múltiplo de `ChunkSize`), confere hash de cada chunk, respeita `Throttle`, reporta `progress`; confere SHA-256 total (divergente → apaga `.part`, `failed`); `File.Move` atômico (`overwrite` só se `Overwrite` e política); `CompleteAsync`; stdout `Arquivo X recebido (n bytes, sha256 …)`. Cancelamento → apaga `.part` e `cancelled`.
- Comportamento `file.fetch`: valida origem (política ∩ raízes locais ∪ fetch-only, `PathGuard`, handle final dentro da raiz), abre com `FileShare.Read`, arquivo em uso → `failed`, tamanho > limite → `rejected`; `BeginAsync(size)`; envia chunks de `ChunkSize` pulando os já recebidos (`ReceivedChunks`), `Throttle`, `progress`; `CompleteAsync(sha256,size)`.

- [ ] **Step 1: Testes falhando** (`FileHandlersTests`, fake `ITransferApi` em memória + diretório temporário do teste): envio feliz (3 chunks, hash ok, arquivo final e `.part` removido); retomada (pré-criar `.part` com 1 chunk → o fake só recebe pedidos de `n ≥ 1`); chunk com hash errado → `failed` e `.part` apagado; hash total divergente; destino existente igual → `completed` sem baixar; diferente sem overwrite → `rejected`; com overwrite permitido → substitui; destino fora de `TransferRoots` → `rejected`; `..`/ADS/reservado/extensão `.exe` no `remote_path` → `rejected` (use o vetor); componente com junction → `rejected`; cancelamento no meio apaga o `.part`; `Throttle` limita (relógio simulado); fetch feliz com `ReceivedChunks` parcial; fetch de arquivo bloqueado por outro processo (abra com `FileShare.None` no teste) → `failed`; `progress` chamado; parâmetros inválidos (`transfer_id` não UUID, chave extra) → `rejected` sem chamar a API; `TaskRunner` usa o `IContextTaskHandler`. Nenhum teste fora de diretórios temporários criados por ele.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** (handlers pequenos; lógica comum de validação em `Transfers/` se evitar duplicação); registrar no catálogo local e na DI.
- [ ] **Step 4: Rodar** o filtro e a suíte do Agent → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(agent): handlers file.send e file.fetch com retomada"`.

### Task 8: E2E real, documentação e evidências

**Files:**
- Create: `tests/integration/transfers-smoke.php` (opcional, só se rodar contra Manager local; guarda de loopback como `tasks-smoke.php`)
- Modify: `docs/api/agent-api-v1.md` (§transfers), `docs/api/task-types.md` (`file.*`), `docs/data-model.md`, `docs/permissions-matrix.md`, `docs/threat-model.md` (T9), `docs/decisions.md` (ADR-022), `docs/known-limitations.md`, `docs/status.md`, `docs/tasks/009-…md`, `docs/tasks/README.md`, `docs/test-evidence.md`, `docs/deployment/server.md` (limites de proxy/PHP para chunks), `README.md` (contagens)

- [ ] **Step 1: E2E real** — Manager local (`php artisan serve`, SQLite descartável **com "test" no nome**, `.env` intocado, variáveis só no processo), Agent publicado em diretório **local** (fora de `Z:`), modo console, `TOOLIT_AGENT_DATA` e `TransferRoots` apontando para diretórios temporários criados pela rodada. Executar e medir: envio de arquivo > 5 MiB com hash verificado; interrupção (derrubar o Manager no meio) e retomada; recuperação e download pelo navegador/`curl` com cabeçalhos conferidos; `..`, ADS, reservado e `.exe` recusados; junction descartável dentro da raiz recusada; operador sem permissão → 403; grupo B → 404; revogação cancela a transferência. **Regras:** nunca usar diretórios reais do sistema, serviços reais ou nomes permitidos reais em testes hostis (incidente da 008); registrar datas, comandos, saídas e latências; limpar tudo ao final (processos, banco, publicação, diretórios temporários). Se algo não rodar, registrar o bloqueio exato como NÃO executado.
- [ ] **Step 2: Suítes e auditorias** — `php artisan test`, suíte do Agent (cópia local), `composer audit`, `dotnet list package --vulnerable --include-transitive --source https://api.nuget.org/v3/index.json` por projeto, `php vendor/bin/pint --test`; anotar totais reais e atualizar o `README.md`.
- [ ] **Step 3: Docs** — task 009: `[x]` só com evidência; manter `[ ]` para UNC real, antivírus real e qualquer item não executado; ADR-022 (risco médio por decisão do dono, política por grupo, veto local); limitações novas (sem aprovação por segunda pessoa, UNC/antivírus não validados, caminho digitado, build do CSS no share, quota de disco do Manager); manual da política de diretórios; seção de limites de proxy/PHP para chunks.
- [ ] **Step 4: Commit** — `git commit -m "docs(transfers): evidências, contrato e ADR-022 da transferência de arquivos"`.

---

## Auto-revisão
- Cobertura do spec: política e tela (T1–T2), `PathGuard` e vetor (T1, T6), modelo/estados/retenção/auditoria (T3), rotas do navegador, download e API do Agent incl. `begin` (T4), aba (T5), Agent (T6–T7), erros/testes/E2E/docs (T3–T8).
- Nomes consistentes: `PathGuard`, `TransferPolicyResolver`, `TransferService`, `FileTransfer`, `TransferStatus`, `ITransferApi`, `IContextTaskHandler`, `TaskContext`, `TransferRoots`, `TransferBytesPerSecond`.
- Fora de escopo mantido: `files.execute`, navegação remota, compressão/delta, inventário de arquivos.
