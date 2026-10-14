# 014 — Backup, observabilidade e operação

## Objetivo e contexto
Operar o Manager no Laragon com backup testado, monitoramento dos próprios serviços e manuais.

## Problema
Sem backup restaurável e sem visibilidade dos serviços (MySQL, Redis, queue, scheduler, Reverb) o sistema não é operável.

## Escopo
Incluído: scripts de backup/restauração (MySQL + `storage/app` + `.env` cifrado + chaves públicas de release), agendamento, serviços persistentes (NSSM/Agendador), health endpoint, métricas básicas, rotação de logs, retenção/LGPD (exportar/excluir dados de usuário/endpoint), manuais. Excluído: HA/cluster.

## Dependências
002; 003 para auditoria; 013 para testar restauração.

## Análise da arquitetura atual
Sem scripts em `deploy/`.

## Arquivos
`deploy/windows/*.ps1` (backup, restore, install-services), `deploy/laragon/README.md`, `app/Http/Controllers/HealthController` (`/up` do Laravel + `/health` autenticado com checks de DB/Redis/queue lag/scheduler heartbeat), `docs/deployment/{server,backup,operations}.md`, `docs/manual-admin.md`.

## Requisitos funcionais
Backup diário com retenção; restauração documentada e **testada**; checagem de fila parada (heartbeat do scheduler) gerando alerta no painel; comando `data:purge` conforme retenção; `audit:verify` agendado.

## Não funcionais
RPO ≤ 24 h, RTO alvo a medir no teste de restauração; backup cifrado (7-Zip/AES ou BitLocker) fora do servidor.

## Regras
Backups contêm dados pessoais: acesso restrito e cifrado; restauração registra auditoria; `APP_KEY` guardada separadamente (sem ela, dados cifrados e sessões se perdem).

## Contratos
N/A.

## Segurança
Credenciais do MySQL para backup com privilégio mínimo; scripts sem senha em texto (usar `mysql_config_editor`/arquivo com ACL); logs sem segredos.

## Estratégia
1. Scripts. 2. Serviços persistentes. 3. Health. 4. Teste de restauração em máquina limpa. 5. Manuais.

## Erros
Backup falha (alerta), disco cheio, restauração com versão de schema diferente (rodar migrations), Redis perdido (fila vazia: reprocessar tarefas por lease).

## Testes
Restauração completa em ambiente limpo com verificação `audit:verify` e contagens; simulação de queda de cada serviço.

## Aceite
- [ ] Restauração validada com evidência - [ ] Serviços sobem após reboot do servidor - [ ] Health detecta fila/scheduler parados - [ ] Manuais revisados

## Documentação
Todos os manuais listados nos entregáveis §19 (admin, instalação, agente, permissões, ameaças, decisões, limitações, evidências).

## Riscos
Laragon não é pensado para produção: registrar limitações e endurecimento (ADR-013).

## Checklist
- [ ] Backup - [ ] Restore testado - [ ] Serviços - [ ] Health - [ ] LGPD (purge/export) - [ ] Manuais - [ ] Docs
