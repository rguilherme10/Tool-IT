# Estado do projeto

Atualizado em 2026-10-14. **Só consta como "concluído" o que foi executado e validado** (evidências em [`test-evidence.md`](test-evidence.md)).

## Fase 1 — Fundação (Manager) ✔
RBAC por grupo, auditoria encadeada, login + TOTP, API do Agent (enroll, heartbeat, rotação, revogação), painel mínimo. Detalhes e testes no histórico de [`test-evidence.md`](test-evidence.md).

## Fase 2 — MVP funcional

| Item | Estado | Observação |
|---|---|---|
| Agent: solução .NET (Core / Collectors / Host / Tests) sobre o template do usuário | ✔ | `agent/Toolit/` — ADR-014 |
| Identidade ECDSA P-256 protegida por DPAPI; `enroll` com token de uso único (token nunca em disco/log) | ✔ | Validado com o `.exe` publicado contra um Manager real |
| Requests assinados (vetor PHP↔C# verificado nos dois lados) | ✔ | |
| Heartbeat, fila SQLite limitada, backoff com jitter, reenvio idempotente | ✔ | Queda de 55 s: 0 lacunas, 0 duplicatas |
| Revogação detectada → coleta/envio suspensos, fila preservada, estado persistente | ✔ | Validado ao vivo |
| Inventário: sistema, hardware, software (Registro), rede, periféricos | ✔ | 5/5 seções reais nesta máquina em ~1 s, 19 KiB |
| Métricas: CPU, memória, volumes, uptime (lote, sem WMI) | ✔ | Temperatura: **não coletada** (sem fonte confiável) |
| Manager: ingestão de inventário/métricas, retenção (`metrics:prune`), abas Hardware/Software/Rede/Métricas | ✔ | Gráfico SVG gerado no servidor |
| Permissão `inventory.view_sensitive` (Consulta não vê software/IP/MAC/usuário) | ✔ | |
| Instalador MSI (`agent/Toolit/ToolitSetup`, projeto do usuário) com serviço: diálogo de URL/token, `service install/uninstall` no próprio Agent, ações do MSI via `installer/Patch-ToolitMsi.ps1` | ✔ MSI gerado (x64, autossuficiente, 33 MB) e validado nas tabelas e na extração · ⚠ **instalação real não executada** | Sessão sem Administrador; ver `installer/README.md` |
| Script alternativo `installer/Install-ToolitAgent.ps1` | ⚠ validado em `-DryRun` e guardas | Chama o mesmo `service install` |
| Serviço Windows real: criação, início automático, recuperação, reinício do PC, atualização do MSI | ❌ **não validado** | Exige Administrador (ver L8) — rodar `ToolitSetup.msi` e registrar em `test-evidence.md` |
| Alertas (regras, duração mínima, histerese), eventos do Windows, serviços monitorados, rollups horários | ❌ pendente | task 007 |
| Inventário de arquivos por política | ❌ pendente | task 006 |
| Histórico/log do endpoint no painel | parcial | auditoria por endpoint + métricas 7 d; sem eventos do Windows |

## Próximos passos (ordem)
1. **Instalar `ToolitSetup.msi` como Administrador** (interativo e `/qn`), reiniciar o PC, derrubar a rede, atualizar e desinstalar; registrar em `test-evidence.md`.
2. Validar Manager em MySQL + Redis (Laragon) — L1.
3. Task 007 (alertas e eventos), depois Fase 3 (tarefas 008, arquivos 009).
4. Migrar o Agent para `net10.0` quando o SDK 10 estiver instalado (L2).

## Riscos abertos
Ver [`known-limitations.md`](known-limitations.md).
