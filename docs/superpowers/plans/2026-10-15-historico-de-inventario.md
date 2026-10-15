# Plano — histórico de mudanças do inventário (task 006)

**Situação encontrada (2026-10-15):** ingestão (`InventoryIngestService`), models, API `POST /inventory` (limite 4 MiB) e abas Hardware/Software/Rede já existem, com testes. Faltava: diff de hardware/SO/rede, nomes do software adicionado/removido e uma tela que mostrasse `inventory_snapshots.changes`.

**Escopo aprovado:** "histórico completo", sem migration (`changes` já é JSON).

## Desenho
- `App\Support\Inventory\InventoryDiff` (puro, sem banco): compara a seção anterior com a nova por *descritores estáveis* (ignora espaço livre/uso de disco, IPs e `boot_time`, que mudam a cada coleta) e devolve entradas `{category, type: added|removed|changed, before, after}`.
- `InventoryIngestService` grava em `changes`: contagens de software (como antes) + `software_added_names`/`software_removed_names` (teto 50) + `items` (diff de sistema/hardware/periféricos/rede, teto 100) + `initial: true` na primeira coleta. Só compara se havia dado anterior da seção e a nova seção veio `ok`.
- Aba **Histórico** em `EndpointController::show` (paginada, 20 snapshots com mudanças). Categorias `software` e `network` só aparecem com `inventory.view_sensitive`; quem não tem vê "alterações restritas". Somente leitura: nada novo em `audit_logs`.

## Passos
1. Testes unitários do `InventoryDiff` → implementar.
2. Testes de ingestão (`changes` com diff/nomes/initial) → integrar no serviço (ajustando o teste que usava igualdade exata).
3. Testes da aba (permissões, vazio, paginação, escape de HTML) → controller + view.
4. Docs: `data-model.md`, task 006, `known-limitations.md`, `test-evidence.md`. Suíte completa.

## Fora do escopo (registrar)
Política de inventário de arquivos; regra "removido após 2 coletas" (hoje remove na 1ª coleta completa); mudança de IPs de rede; payload do snapshot não é guardado (histórico = só o diff).
