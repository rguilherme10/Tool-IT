# Manual do administrador

Trechos operacionais das telas administrativas. Estado real de cada recurso: [`status.md`](status.md).

## Política de transferência de arquivos (task 009)

Tela: link **Política de arquivos** no menu superior (`/settings/file-policies`, título "Política de transferência de arquivos"). Só quem tem `settings.manage` global (Administrador) vê e altera; criar, editar e remover gera `transfer_policy.created|updated|deleted` na auditoria.

**Como a política vale**
- Uma política por escopo: **global** ou **um grupo**. O computador usa a do grupo mais próximo na árvore (o próprio grupo, depois os de cima) e, se nenhum tiver, a global. **Sem nenhuma política, envio e busca ficam desligados** — a aba Arquivos mostra "Sem política de transferência".
- O escopo não muda depois de criado: para trocar, remova e crie outra. Remover o grupo remove a política dele (sem registro próprio na auditoria — L47).
- Além da política, cada Agent tem o seu **veto local** (`TransferRoots` no `config.json`; padrão `C:\ProgramData\Toolit\Inbox` e `…\Outbox`, e `…\Logs` só para busca). Vale a **interseção**: uma pasta que está na política mas não nas raízes do Agent é recusada no computador (`rejected`).

**Campos**
| Campo | Regra |
|---|---|
| Envio para o computador | Um diretório absoluto por linha (até 20), ex. `C:\ProgramData\Toolit\Inbox`. O destino do envio precisa ser **exatamente** um destes (subpastas não valem). Vazio = envio desligado |
| Busca no computador | Um diretório por linha (até 20); a busca aceita arquivos nele **e nas subpastas**. Vazio = busca desligada |
| Extensões (envio) | Minúsculas, sem ponto (`txt log csv json xml pdf zip 7z png jpg docx xlsx` é a sugestão). Obrigatório se houver diretório de envio. Executáveis e scripts (`exe dll bat cmd ps1 psm1 vbs vbe js jse wsf msi msp scr com pif lnk hta jar cpl reg sys drv`) são recusados aqui e bloqueados sempre, inclusive em dupla extensão (`relatorio.pdf.exe`) |
| Tamanho máx. | 1 byte a 1 GiB; padrão 104857600 (100 MiB). O teto global do Manager também é 100 MiB |
| Sobrescrever | Desligado por padrão: arquivo existente com conteúdo diferente é recusado; com o mesmo SHA-256 o envio conta como concluído |

**O que a tela recusa** (cada linha com erro aponta o número da linha): raiz de unidade (`C:\`), `C:\Windows`, `C:\Program Files*`, `C:\Users` inteiro, caminhos de rede (`\\servidor\share` — UNC fica fora deste ciclo), `..`, dois-pontos fora da letra da unidade (fluxos alternativos), nomes reservados (`CON`, `NUL`, `COM1`…), nomes 8.3 (`~1`), ponto/espaço no fim, caracteres invisíveis/bidi e homógrafos de `\ / : .`, diretório repetido. Regras completas: [`api/path-guard.md`](api/path-guard.md).

**Boas práticas**
- Use pastas **dedicadas** (Inbox/Outbox), nunca pastas de usuário ou do sistema; mantenha a mesma lista nas raízes locais dos Agents.
- Poucas extensões. Lembre que envio e busca são de **risco médio, sem aprovação** (decisão do dono — ADR-022, L42): quem tem `files.transfer_send` no grupo grava sozinho nas pastas da política.
- Busca (`fetch`) aceita qualquer extensão (ler não executa); restrinja pelos diretórios. Suporte só busca.
- Os arquivos ficam no Manager por 7 dias após o fim da transferência (`transfers:prune`, 03:45) — dimensione o disco (L46) e o proxy (`client_max_body_size` ≥ 3m): [`deployment/server.md`](deployment/server.md) §5.1.
