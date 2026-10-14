# 007 — Monitoramento de saúde e alertas

## Objetivo e contexto
Métricas periódicas, histórico com retenção e alertas com persistência, histerese e repetição controlada.

## Problema
Evitar alertas por leitura momentânea e crescimento ilimitado de tabelas.

## Escopo
Incluído: coleta de CPU, memória, volumes, uptime, estado de serviços monitorados, eventos de erro relevantes, temperatura (só se confiável); `POST /metrics`, `POST /events`; regras de alerta; rollups; retenção. Excluído: notificações externas (e-mail/webhook) — futuro.

## Dependências
005, 006.

## Análise da arquitetura atual
Heartbeat existente; sem `metric_samples`.

## Arquivos
Agent: `Collectors/MetricsCollector.cs`, `EventLogCollector.cs`. Manager: `MetricsController`, `MetricsIngestService`, `AlertEvaluator` (job a cada minuto), `RetentionJob`/`RollupJob`, migrations `metric_samples`, `metric_rollups_hourly`, `alert_rules`, `alerts`, `endpoint_events`.

## Requisitos funcionais
Regras: disco > X %, memória disponível < Y, CPU > Z % por N min, agente sem comunicação > T, serviço parado, falha recorrente de tarefa, versão desatualizada. Cada regra: `threshold`, `min_duration`, `hysteresis` (limite de recuperação), `repeat_interval`, escopo (global/grupo/endpoint), severidade.

## Não funcionais
100 endpoints × 1 amostra/min ≈ 144 mil linhas/dia: índice (endpoint_id, sampled_at); raw 7 dias; rollup horário 13 meses. Inserção em lote.

## Regras
Alerta abre somente após condição contínua ≥ `min_duration`; fecha só após cruzar histerese; deduplicação por `dedupe_key` enquanto aberto; reconhecimento por operador (`alerts.manage`) auditado. "Agente sem comunicação" calculado pelo servidor.

## Contratos
`agent-api-v1.md` §/metrics, §/events (idempotência por `sample_id`/`event_id`).

## Segurança
Limites de lote e tamanho; valores validados (faixas); mensagens de evento escapadas e truncadas.

## Estratégia
1. Ingestão + migrations. 2. Coletor de métricas. 3. Avaliador. 4. Rollup/retenção. 5. Telas/gráficos. 6. Medir custo.

## Erros
Amostras fora de ordem/duplicadas, relógio do agente deslocado (usar `received_at` para sanidade), sensor indisponível (null, não zero), contadores de CPU indisponíveis.

## Testes
Avaliador com relógio simulado (duração, histerese, repetição, recuperação), idempotência, retenção/rollup (valores agregados corretos), isolamento por grupo.

## Aceite
- [ ] Alerta não dispara por 1 leitura - [ ] Histerese e repetição comprovadas por teste - [ ] Retenção apaga raw > 7 d preservando rollup - [ ] Gráfico com dados reais

## Documentação
`data-model.md` (retenção), manual do administrador (regras de alerta).

## Riscos
Contadores de desempenho localizados no Windows (usar índices em inglês via API `PdhAddEnglishCounter`).

## Checklist
- [ ] Coleta - [ ] Ingestão - [ ] Alertas - [ ] Retenção - [ ] UI - [ ] Testes - [ ] Medições - [ ] Docs
