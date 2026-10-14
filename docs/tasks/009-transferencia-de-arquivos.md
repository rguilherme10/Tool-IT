# 009 — Transferência de arquivos

## Objetivo e contexto
Enviar arquivos do Manager ao endpoint e recuperar arquivos do endpoint, com integridade, limites e auditoria. Transferir ≠ executar.

## Problema
Path traversal, sobrescrita acidental, exfiltração e execução automática.

## Escopo
Incluído: transferências em partes (chunks) retomáveis, hash SHA-256 obrigatório, diretórios permitidos por política, quarentena/retenção. Excluído: execução do arquivo (permissão e tarefa próprias `files.execute`, desligada por padrão).

## Dependências
008.

## Análise da arquitetura atual
Tarefas tipadas existentes (008).

## Arquivos
Manager: `FileTransfer`, `FileTransferService`, `PathValidator`, storage privado `storage/app/transfers` (fora do webroot), `PruneTransfersJob`. Agent: `Handlers/FileSend|FileFetch`, `PathGuard`.

## Requisitos funcionais
Seleção explícita de endpoint e arquivo; tipo/tamanho validados; chunks de 1–4 MiB com hash por chunk e total; retomada; registro de origem/destino/tamanho/operador/resultado.

## Não funcionais
Limite padrão 100 MiB por arquivo (configurável), 1 transferência por endpoint; banda limitada; retenção de temporários 7 dias.

## Regras
- Nome do arquivo é dado não confiável: o Manager gera nome interno (UUID); nome original só como metadado escapado.
- Destino deve estar dentro de diretório permitido pela política do grupo (ex.: `C:\ProgramData\Toolit\Inbox`); caminho canonicalizado (`GetFullPath`, resolve links/junctions) **antes** de comparar; rejeitar `..`, caminhos UNC não autorizados, ADS (`:`), nomes reservados (CON, NUL…), caracteres de controle.
- Sem sobrescrita por padrão (`overwrite=false`; sobrescrever exige flag + permissão); gravação em arquivo temporário + rename atômico após hash OK.
- Recuperação (`fetch`) só de diretórios permitidos; conteúdo de documentos pessoais não é inventariado.

## Contratos
Endpoints do Agent: `GET /transfers/{id}/chunks/{n}` (download), `PUT /transfers/{id}/chunks/{n}` (upload) + `POST /transfers/{id}/complete`; documentar em `agent-api-v1.md` ao implementar.

## Segurança
Autorização por endpoint+operador na criação e no acesso aos chunks (BOLA); URL de download só para o agente dono; antivírus do host não é contornado; limite de tipo (allowlist de extensões por política); download pelo navegador com `Content-Disposition: attachment`, `nosniff`.

## Estratégia
1. PathValidator (Manager e Agent) com testes extensivos. 2. Upload operador→Manager. 3. Entrega ao agente. 4. Fetch inverso. 5. Retomada. 6. Retenção.

## Erros
Hash divergente (descartar), interrupção (retomar), disco cheio, arquivo em uso, destino fora da política, tamanho excedido, agente revogado no meio.

## Testes
Tabela de caminhos maliciosos (traversal, UNC, ADS, reservados, symlink/junction, Unicode confusables), sobrescrita, hash, retomada, limites, permissões, auditoria, retenção.

## Aceite
- [ ] Envio e recuperação reais com hash verificado - [ ] Traversal impossível por teste - [ ] Sem execução automática - [ ] Operador sem permissão recebe 403

## Documentação
Manual do administrador (política de diretórios), threat model T9.

## Riscos
Arquivos grandes sobre Laragon/Apache (limites `post_max_size`/`upload_max_filesize`): usar chunks e documentar.

## Checklist
- [ ] PathValidator - [ ] Envio - [ ] Recuperação - [ ] Retomada - [ ] Retenção - [ ] Testes - [ ] Docs
