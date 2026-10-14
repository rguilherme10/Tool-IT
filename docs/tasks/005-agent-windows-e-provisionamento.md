# 005 — Agent Windows (serviço) e provisionamento

## Objetivo e contexto
Serviço Windows em C# que se registra com segurança, mantém identidade, envia heartbeat, persiste fila local e se recupera de falhas.

## Problema
Segredos fora do instalador; identidade persistente; operação em Session 0 sem sessão interativa.

## Escopo
Incluído: solução .NET (Worker Service), `UseWindowsService`, geração de chave ECDSA P-256 + DPAPI (máquina), enrollment, assinatura de requests, heartbeat, config persistente (`%ProgramData%\Toolit\Agent\config.json` sem segredos), logs (arquivo rotativo, redação), fila SQLite limitada, backoff com jitter, auto-start e *recovery actions* do serviço. Excluído: coletas (006/007), tarefas (008), captura (010), auto-update (012).

## Dependências
004 (contrato e endpoint de enroll).

## Análise da arquitetura atual
Pasta `agent/` vazia. SDK 9.0.308 no host (ADR-010: alvo `net8.0` até SDK 10).

## Arquivos
`agent/Directory.Build.props`, `agent/Toolit.Agent.sln`, projetos `Contracts`, `Core`, `Collectors`, `Service`, `Tests` (criar com `dotnet new worker|classlib|xunit` e `dotnet sln add`).

## Requisitos funcionais
Instalar como serviço (`sc.exe create`/`New-Service` via script), iniciar automático (atrasado), recuperação (reiniciar após falha 1/2/3), estado reportado no heartbeat, comando `--enroll --server <url> --token <t>` no instalador (token não persistido).

## Não funcionais
CPU média < 1 %, memória < 100 MB (a medir), sem dependência de sessão, TLS ≥ 1.2 (preferir 1.3), validação de certificado do servidor obrigatória (sem bypass).

## Regras
Identidade = chave + `agent_id`; hostname/MAC não são identidade. Chave privada exportável = false quando possível (CNG com `CngKeyCreationOptions.MachineKey`) e ACL SYSTEM/Administrators.

## Contratos
`docs/api/agent-api-v1.md`; DTOs em `Contracts` com testes de serialização contra `tests/contracts/*.json`.

## Segurança
Sem ocultação, sem evasão; `config.json` sem segredos; logs sem tokens; ACL de `%ProgramData%\Toolit\Agent`; falhar fechado se assinatura de resposta/certificado inválidos.

## Estratégia
1. Scaffold com `dotnet new`. 2. Signer + **vetor de teste cruzado** com PHP. 3. Enrollment. 4. Cliente HTTP + backoff. 5. Fila SQLite. 6. Heartbeat loop. 7. Script de instalação. 8. Teste em VM/PC de teste.

## Erros
Sem rede (fila/backoff), relógio errado (401 + `server_time`), 403 `agent.revoked` (parar operações), disco cheio (log reduzido), chave corrompida (estado "precisa reativação"), reinicialização (retomar).

## Testes obrigatórios
Unit: assinatura, canonicalização, backoff, fila (limite/ordem/descarte), config; integração: contra Manager de teste (`php artisan serve`); Windows: instalar/remover/reiniciar serviço em máquina de teste, queda de rede.

## Aceite
- [ ] Serviço roda como LocalSystem em Session 0 e sobrevive a reboot - [ ] Enroll + heartbeat visíveis no painel - [ ] Revogação detectada e operações interrompidas - [ ] Vetor cruzado verde - [ ] Medições de CPU/RAM registradas

## Documentação
`installer/README.md`, manual do Agent, `status.md`, `test-evidence.md`.

## Riscos
Falta SDK .NET 10; testes de serviço exigem privilégios de administrador e máquina de teste.

## Checklist (estado em 2026-10-14)
- [x] Solução (sobre o template do usuário, ADR-014) - [x] Signer + vetor cruzado PHP↔C# - [x] Enroll (token nunca em disco) - [x] Heartbeat - [x] Fila SQLite limitada - [x] Backoff/jitter - [x] Logs com redação - [x] Revogação detectada e persistida - [x] Rotação de chave: lado Manager testado; comando `rotate-key` do Agent escrito mas **sem teste automatizado nem execução contra o Manager real** - [x] 100 testes - [x] Medição de CPU/RAM (1 máquina)
- [ ] **Serviço instalado e validado como Administrador** (início automático, recuperação, reinício do PC) - [ ] Teste de instalação/remoção em máquina limpa - [ ] Migrar para `net10.0` (SDK 10) - [ ] Docs de implantação validadas
