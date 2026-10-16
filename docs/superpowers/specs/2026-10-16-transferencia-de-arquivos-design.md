# Spec — Transferência de arquivos (task 009)

Sub-projeto D da sequência C→D→A→B→E→F→G. Depende da 008 (tarefas tipadas, já integrada em `main`). Contexto: `docs/tasks/009-transferencia-de-arquivos.md`, `docs/threat-model.md` T9, `docs/api/task-types.md`, `docs/permissions-matrix.md`.

## Objetivo
Enviar arquivos do operador ao endpoint e recuperar arquivos do endpoint, em partes (chunks) retomáveis, com SHA-256 verificado, limitados por **política de diretórios por grupo**, sem execução e totalmente auditados. Transferir ≠ executar.

## Decisões fechadas com o dono do projeto
- **Risco médio nos dois sentidos** (`file.send` e `file.fetch`): sem aprovação por segunda pessoa. Isso foi escolha do dono (a recomendação era envio = alto). Consequência: a política e o veto local do Agent passam a ser o controle principal; registrar no ADR e em limitações.
- **Política por grupo, com tela de edição** (`settings.manage`, auditada).
- **Upload do operador em chunks pelo navegador** (retomável, não depende de `post_max_size`).

## Escopo
Incluído: política por grupo (herdada pela árvore), validação de caminho nos dois lados (`PathGuard`), upload em chunks pelo navegador, tarefas `file.send` e `file.fetch`, rotas de chunks do Agent, aba **Arquivos**, download autenticado, retenção, auditoria, envio de `progress` pelo Agent.
Excluído: `files.execute` (permissão e tarefa próprias, desligadas), navegação no sistema de arquivos do endpoint (o operador digita o caminho), compressão/delta, inventário de arquivos (sub-projeto B), caminhos UNC reais (não validáveis aqui).

## Política (`file_transfer_policies`)
Campos: `endpoint_group_id` (nulo = padrão global), `send_dirs` (json, lista de diretórios absolutos Windows), `fetch_dirs` (json), `allowed_extensions` (json, só para envio; minúsculas, sem ponto), `max_bytes` (padrão 104857600), `allow_overwrite` (bool, padrão falso), `updated_by`, timestamps. Única por grupo.
- Política efetiva do endpoint = a do grupo mais próximo na árvore que tenha política; sem nenhuma → **transferências desligadas** (negado por padrão; a UI mostra "Sem política de transferência").
- Extensões executáveis ficam **sempre bloqueadas em código**, mesmo se listadas: `exe dll bat cmd ps1 psm1 vbs vbe js jse wsf msi msp scr com pif lnk hta jar cpl reg sys drv`.
- Valores iniciais sugeridos ao criar uma política: `send_dirs=[C:\ProgramData\Toolit\Inbox]`, `fetch_dirs=[C:\ProgramData\Toolit\Outbox, C:\ProgramData\Toolit\Logs]`, extensões `txt log csv json xml pdf zip 7z png jpg docx xlsx`.
- Tela `/settings/file-policies` (lista, criar, editar, remover): só `settings.manage` global; valida cada diretório com o `PathGuard` e rejeita raiz de unidade, `C:\Windows`, `C:\Program Files*` e `C:\Users` inteiros.
- **Veto local do Agent:** `AgentSettings.TransferRoots` (padrão `Inbox` e `Outbox` em `%ProgramData%\Toolit`; `Logs` do próprio Agent só para fetch). Vale a **interseção** da política do Manager com os diretórios locais; fora dela o Agent recusa (`rejected`).

## Validação de caminho (`PathGuard`)
Implementada em PHP (Manager, só sintaxe, sem acessar disco) e em C# (Agent, com o sistema de arquivos). Os dois passam pelo mesmo vetor `tests/contracts/path-guard-cases.json` (caso, contexto, resultado esperado, motivo).
Rejeita: caminho relativo, `..` ou `.` como componente, UNC (`\\`) não listado na política, ADS (`:` fora de `X:` na posição 1), nomes reservados (`CON PRN AUX NUL COM1-9 LPT1-9`, com ou sem extensão), nomes 8.3 (`~\d`), ponto ou espaço ao fim de componente, componentes vazios, comprimento > 260 total e > 120 por componente, caracteres de controle e de formato Unicode (Cc, Cf: inclui bidi U+202A–202E/2066–2069 e zero-width), e qualquer caractere que NFKC transforme em `/ \ : .` (homógrafos). Nomes de arquivo em NFC; letras e dígitos Unicode são permitidos (nomes em português), além de espaço e `.-_()`.
Agent (adicional): `Path.GetFullPath`; recusa **qualquer componente existente que seja reparse point** (junction/symlink); compara prefixo com os diretórios permitidos sem diferenciar maiúsculas e com separador final; após abrir o arquivo, confere o caminho real do handle (`GetFinalPathNameByHandle`) contra os diretórios permitidos (fecha a janela entre checagem e uso).

## Modelo de dados
`file_transfers`: `uuid`, `endpoint_id`, `remote_task_id` (nulo até criar a tarefa), `direction` (`send|fetch`), `created_by`, `original_name` (metadado, escapado), `stored_name` (UUID, nunca vem do cliente), `remote_path` (destino no envio; origem na recuperação), `size`, `sha256` (envio: calculado no Manager ao montar; recuperação: informado pelo Agent e conferido), `chunk_size`, `chunks_total`, `chunks_received`, `overwrite` (bool), `status` (`uploading|ready|transferring|verifying|completed|failed|cancelled|expired`), `error` (255), `expires_at`, timestamps. Índices `(endpoint_id,status)`, `(status,expires_at)`.
Armazenamento privado fora do webroot: `storage/app/private/transfers/{uuid}/` (partes de upload, arquivo montado, chunks recebidos do Agent). `PruneTransfersJob` diário: temporários e blobs com 7 dias (`toolit.transfers.retention_days`) são apagados; os metadados ficam.

## Fluxos
**Envio (operador → endpoint):**
1. `POST /endpoints/{uuid}/transfers` (web, `files.transfer_send` + escopo): arquivo (nome, tamanho), diretório (um da política) e subnome opcional; valida política, extensão, `max_bytes`, `PathGuard`; cria a transferência `uploading` e devolve `uuid` e `chunk_size` (1 MiB).
2. `PUT /transfers/{uuid}/upload/{n}` (corpo bruto, idempotente por `n`; ordem livre; teto 1 MiB + folga) e `GET /transfers/{uuid}/upload` (chunks já recebidos, para retomar).
3. `POST /transfers/{uuid}/upload/complete`: confere todos os chunks e o tamanho, monta o arquivo, calcula SHA-256, passa a `ready` e **cria a tarefa** `file.send {transfer_id}` via `TaskService` (risco médio, nasce `available`).
4. O Agent executa `file.send`: `GET /transfers/{id}` (metadados) → valida (política ∩ veto local, `PathGuard`, espaço em disco, `overwrite`) → baixa chunks de 2 MiB (`GET …/chunks/{n}` com `X-Chunk-Sha256`) para `.toolit-{uuid}.part` no diretório de destino → confere SHA-256 total → `rename` atômico (sobrescreve só se `overwrite` e política) → `POST /transfers/{id}/complete {sha256,size}`.
**Recuperação (endpoint → operador):**
1. Formulário (`files.transfer_fetch` + escopo): caminho remoto validado contra `fetch_dirs`. Cria transferência + tarefa `file.fetch {transfer_id}`.
2. O Agent valida, abre o arquivo (FileShare.Read), envia chunks de 2 MiB (`PUT …/chunks/{n}` com `X-Chunk-Sha256`), calcula o SHA-256 e chama `complete`. O Manager confere tamanho (≤ `max_bytes`) e hash dos chunks, monta e passa a `ready`.
3. `GET /transfers/{uuid}/download` (web, `files.transfer_fetch` + escopo; auditado): `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, nome sanitizado.
**Retomada:** no envio, o Agent continua do tamanho do `.part` (múltiplo de `chunk_size`); na recuperação, o Manager informa os chunks já recebidos em `GET /transfers/{id}`. Destino existente com o mesmo SHA-256 → sucesso idempotente. Hash divergente → descarta o `.part`, `failed`.

## Tarefas (catálogo 008)
`file.send` e `file.fetch`: risco **medium**; idempotentes, 3 tentativas; timeout 900 s (o `progress` renova a lease; o Agent passa a enviar `progress` a cada chunk ou a cada 15 s); parâmetro único `transfer_id` (UUID). Permissões de criação: `tasks.create` + `files.transfer_send` / `files.transfer_fetch`. O Agent obtém caminho e metadados por `GET /transfers/{id}` — nunca dos parâmetros — e só aceita transferência cujo `endpoint_id` e direção batem. Cancelar a tarefa cancela a transferência; revogar/desativar o endpoint cancela as abertas.

## API do Agent (assinada, escopada ao endpoint da instalação)
`GET /transfers/{id}`, `GET /transfers/{id}/chunks/{n}`, `PUT /transfers/{id}/chunks/{n}`, `POST /transfers/{id}/begin` (só recuperação: o Agent informa `{size}` ao abrir o arquivo; o Manager confere `max_bytes` e fixa `chunks_total`), `POST /transfers/{id}/complete`. Transferência de outro endpoint ou inexistente → `404 request.not_found`; fora de estado válido → `409 transfer.conflict`. `agent.payload` ganha teto de chunk (≥ 2 MiB + folga) só nessas rotas de PUT; `X-Chunk-Sha256` obrigatório; chunk com hash errado → `422 transfer.chunk_hash`. Banda do Agent limitada (`AgentSettings.TransferBytesPerSecond`, padrão 4 MiB/s).

## Painel
Aba **Arquivos** no endpoint (`files.transfer_send` ou `files.transfer_fetch`, o que o usuário tiver): formulário de envio (JS fatia o arquivo, mostra progresso e permite retomar), formulário de recuperação, lista paginada de transferências (estado, tamanho, SHA-256, operador, datas) e botão de download. Estados vazios sem dados fictícios. Nenhuma opção de execução. Saídas e nomes sempre escapados.

## Segurança
BOLA em toda rota (web por `AccessResolver`, Agent por instalação); download só com `files.transfer_fetch` no escopo; nome original só como metadado escapado; nenhum caminho vindo de tarefa/parâmetro; chunks e montagem fora do webroot; limites de tamanho/quantidade; sem execução automática; antivírus do host não é contornado; checagem de espaço em disco no Agent e no Manager; `max_bytes` aplicado em cada etapa.

## Auditoria
`transfer.created`, `transfer.upload_completed`, `transfer.completed`, `transfer.failed`, `transfer.cancelled`, `transfer.downloaded`, `transfer_policy.created|updated|deleted` — com endpoint, direção, tamanho, SHA-256, diretório (nunca o conteúdo).

## Erros
Hash divergente (descarta), interrupção (retoma), disco cheio (Agent: `failed` com motivo; Manager: recusa o upload), arquivo em uso (fetch: `failed`), destino fora da política (`rejected`), tamanho excedido (422), agente revogado no meio (cancela), chunk duplicado (idempotente), chunk fora do intervalo (422), upload abandonado (expira), política removida durante a transferência (Agent revalida no início; tarefas abertas falham no claim).

## Testes
Manager: `PathGuard` com a tabela de caminhos maliciosos; política efetiva por herança; permissões × papel × escopo (BOLA entre grupos, Auditor nunca, Suporte só fetch); upload retomável, idempotência e montagem; limites; hash; sobrescrita; download (cabeçalhos, escopo, auditoria); retenção; rotas do Agent (404 de outro endpoint, 409, 422, assinatura); revogação cancela; contrato JSON compartilhado.
Agent: `PathGuard` com o mesmo vetor, reparse point (cria junction descartável no teste), interseção política ∩ raízes locais, escrita `.part` + rename atômico, retomada, hash divergente, destino existente com mesmo hash, sobrescrita, banda, `progress`, cancelamento — tudo em diretórios temporários criados pelo teste.
E2E: Agent real contra Manager local, só com arquivos descartáveis em Inbox/Outbox temporários: envio de arquivo > 5 MiB com hash verificado, interrupção e retomada, recuperação e download, tentativa de `..` e de extensão `.exe` recusadas. Evidências em `docs/test-evidence.md`. **Regra:** nenhum teste toca diretórios reais do sistema nem serviços reais.

## Aceite (task 009)
Envio e recuperação reais com hash verificado · traversal impossível por teste · sem execução automática · operador sem permissão recebe 403 · retomada comprovada · retenção comprovada.

## Documentação a atualizar
`docs/api/agent-api-v1.md` (rotas de transferência), `docs/api/task-types.md` (`file.*`), `data-model.md`, `permissions-matrix.md`, `threat-model.md` T9, `decisions.md` (ADR-022: risco médio por decisão do dono, política por grupo e veto local), `known-limitations.md`, `status.md`, task 009 e `test-evidence.md`; manual da política de diretórios; nota sobre limites do proxy/PHP para chunks.

## Limitações previstas (registrar)
Sem aprovação por segunda pessoa (decisão do dono, risco médio); UNC e antivírus interferindo não validados; caminho do operador é digitado (sem navegação remota); quotas de espaço do Manager não monitoradas além da checagem no upload.
