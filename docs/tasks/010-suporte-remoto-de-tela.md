# 010 — Suporte remoto: visualização de tela somente leitura

## Objetivo e contexto
Operador autorizado vê a tela de um endpoint no navegador, sem controle de teclado/mouse e sem gravação.

## Problema
Session 0 impede captura direta por serviço; privacidade e abuso.

## Escopo
Incluído: **PoC de sessões Windows primeiro** (gate), CaptureHelper, IPC por named pipe, canal WebSocket (Reverb), UI de visualização, limites e auditoria. Excluído: input remoto, áudio, gravação, transferência de clipboard, captura de tela de bloqueio/UAC.

## Dependências
005, 008 (tarefa `screen.start`), Reverb configurado (002/014).

## Análise da arquitetura atual
Ver `architecture.md` §6 e ADR-008.

## Fases
**10a — PoC (sem tela no painel):** serviço SYSTEM lança Helper na sessão ativa (`WTSGetActiveConsoleSessionId`, `WTSEnumerateSessions`, `WTSQueryUserToken`, `CreateProcessAsUser`); captura de 1 quadro; trata: sem usuário, estação bloqueada, troca rápida de usuário, sessão RDP, múltiplos monitores. Registrar matriz de resultados em `test-evidence.md`. **Sem passar do 10a antes de documentar os resultados.**
**10b — Canal e UI:** sessão temporária, WebSocket, exibição.
**10c — Qualidade e limites:** FPS/qualidade/resolução, medições de CPU/rede.

## Arquivos
Agent: `CaptureHelper/*` (usa `Windows.Graphics.Capture` ou GDI `BitBlt`/DXGI Desktop Duplication — decidir na PoC), `Service/Screen/{SessionLauncher,PipeServer,FrameRelay}`. Manager: `RemoteSession`, `RemoteSessionService`, `ScreenSessionController`, canais privados, view Blade/JS, `ExpireSessionsJob`.

## Requisitos funcionais
Selecionar endpoint conectado; iniciar/encerrar a qualquer momento; mostrar última captura, estado, atraso; limite de sessões simultâneas (global e por operador); expiração automática (padrão 15 min, renovável com confirmação); estados: `requested → starting → active → ended|failed|expired|denied`.

## Não funcionais
Padrão 1 fps, JPEG q60, máx. 1280 px de largura; tetos configuráveis; CPU do Agent < 10 % durante sessão (medir); sem arquivos temporários (frames em memória, buffers zerados).

## Regras
- Permissão `screen.view` + escopo de grupo; auditoria de início/fim/operador/endpoint/resultado.
- Política de estação bloqueada/sem usuário: **não capturar** — informar "indisponível" (sem bypass).
- Aviso visível no endpoint enquanto a sessão está ativa (indicador/balão do Helper) conforme política corporativa; consentimento configurável (`screen.require_consent`).
- Navegador recebe token de sessão efêmero (≤5 min, vinculado a operador+endpoint+sessão); nunca credenciais do Agent.

## Contratos
Mensagens WS (`frame`, `status`, `error`, `end`) com schema e limite de tamanho; documentar em `docs/api/screen-protocol.md`.

## Segurança
Pipe com ACL (SYSTEM + SID do serviço); helper com token reduzido, sem input/arquivos/comandos; validação de cada mensagem do pipe; Helper autentica o Service por PID/SID; threat model T10/T11.

## Estratégia
10a PoC → decidir API de captura → 10b → 10c → documentação de privacidade.

## Erros
Helper morre, pipe cai, usuário faz logoff, bloqueio, troca de usuário, WS cai, operador fecha aba (expiração), limite excedido, endpoint offline.

## Testes
Unit: máquina de estados, limites, tokens; integração: fluxo completo em VM Windows 10 e 11 (console, RDP, bloqueada, logoff, 2 usuários); medições de recursos.

## Aceite
Os 10 critérios do enunciado §9.6, cada um com evidência em `test-evidence.md`.

## Documentação
`architecture.md`, `api/screen-protocol.md`, política de privacidade/LGPD, limitações.

## Riscos
Captura de janelas com proteção de conteúdo (DRM) fica preta; elevação UAC (desktop seguro) não capturável; LGPD exige base legal e aviso.

## Checklist
- [ ] 10a PoC + matriz - [ ] Helper - [ ] Pipe - [ ] Sessão/WS - [ ] UI - [ ] Limites - [ ] Testes - [ ] Medições - [ ] Docs
