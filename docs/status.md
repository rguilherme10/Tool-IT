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
| Script de instalação do serviço (`installer/Install-ToolitAgent.ps1`) | ⚠ escrito, **serviço não criado em teste** | Sessão sem elevação; validado só em `-DryRun` e guardas de erro |
| Serviço Windows real: início automático, recuperação, reinício do PC | ❌ **não validado** | Exige Administrador (ver L8) |
| Alertas (regras, duração mínima, histerese), eventos do Windows, serviços monitorados, rollups horários | ❌ pendente | task 007 |
| Inventário de arquivos por política | ❌ pendente | task 006 |
| Histórico/log do endpoint no painel | parcial | auditoria por endpoint + métricas 7 d; sem eventos do Windows |

## Próximos passos (ordem)
1. **Instalar e validar o serviço como Administrador** (script + reinício + queda de rede) e registrar em `test-evidence.md`.
2. Validar Manager em MySQL + Redis (Laragon) — L1.
3. Task 007 (alertas e eventos), depois Fase 3 (tarefas 008, arquivos 009).
4. Migrar o Agent para `net10.0` quando o SDK 10 estiver instalado (L2).

## Riscos abertos
Ver [`known-limitations.md`](known-limitations.md).
