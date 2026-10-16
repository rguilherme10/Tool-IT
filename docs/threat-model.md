# Modelo de ameaças (STRIDE resumido)

## Ativos
1. Capacidade de executar ações em ~100 endpoints (o ativo mais crítico: o Manager é um "controlador de frota").
2. Inventário, métricas, arquivos recuperados, imagens de tela (dados pessoais — LGPD).
3. Chaves: APP_KEY, chave de release do Agent, chaves públicas dos agentes.
4. Trilha de auditoria.

## Fronteiras de confiança
- Navegador do operador ↔ Manager (sessão + CSRF + MFA).
- Agent ↔ Manager (rede corporativa; não confiável a nível de rede).
- Agent Service (SYSTEM) ↔ CaptureHelper (sessão do usuário) via pipe local.
- Conteúdo vindo do endpoint (nomes de arquivo, processos, software) = **dados não confiáveis**.

## Ameaças e controles

| # | Ameaça | Controle | Onde |
|---|---|---|---|
| T1 | Agente falso se registra | Enrollment com token de uso único, hash, expiração, escopo de grupo; rate limit; auditoria; aprovação opcional | task 003/004 |
| T2 | Roubo/replay de requisição do Agent | Assinatura ECDSA por request, janela ±120 s, nonce único, TLS | ADR-004 |
| T3 | Chave do Agent copiada | DPAPI escopo máquina; revogação individual imediata; rotação; detecção de uso de duas origens (alerta) | task 005/007 |
| T4 | Agent comprometido envia payload malicioso | Validação estrita de schema/tamanho; saída tratada como dado (escape em Blade); isolamento por endpoint (BOLA) | task 004/006 |
| T5 | Operador acessa endpoint fora do seu escopo (IDOR/BOLA) | `AccessResolver` por grupo em toda rota/consulta; global scope em listagens; testes de isolamento | task 003 |
| T6 | Escalação de privilégio via papel | Permissões granulares (inventário/suporte/arquivos/comandos/admin); papéis semeados; mudanças auditadas | docs/permissions-matrix.md |
| T7 | Execução arbitrária via tarefa | Catálogo fechado de tipos de tarefa; parâmetros validados no Manager **e** no Agent; allowlist local; PowerShell separado, desligado por padrão, aprovação dupla | task 008 |
| T8 | Servidor comprometido empurra update malicioso | Update assinado com chave offline; Agent valida assinatura/hash; rollback | task 012 |
| T9 | Path traversal / sobrescrita / exfiltração em transferência | **Implementado (task 009, ADR-022):** política de diretórios por grupo (sem política = desligado) ∩ veto local do Agent (`TransferRoots`, raízes conferidas por dono/DACL); `PathGuard` espelhado PHP/C# com vetor compartilhado (`docs/api/path-guard.md`: `..`, UNC, ADS, reservados, 8.3, ponto/espaço final, Cc/Cf, homógrafos, comprimento); extensões executáveis sempre bloqueadas; nenhum caminho nos parâmetros da tarefa; Agent abre tudo por handle (`OpenChecked`: recusa reparse point e hard link, confere o caminho final), grava em `.part`, confere SHA-256 e renomeia pelo handle; sem sobrescrita por padrão; nome original só como metadado; download `attachment` + `nosniff` + `no-store`; BOLA (404/403); auditoria. E2E 2026-10-16: 28 entradas hostis → 422, junctions e pastas fora das raízes → `rejected`, canário intacto. Riscos residuais: L42–L50 | task 009 |
| T10 | Vazamento de tela / abuso de visualização | Permissão específica, token de sessão curto vinculado a operador+endpoint, limite de sessões, expiração, auditoria, aviso ao usuário, sem gravação | task 010 |
| T11 | Helper de captura usado para outra coisa | Pipe com ACL; protocolo mínimo (start/stop/quality); token restrito; sem input/arquivos/comandos | task 010 |
| T12 | Adulteração da auditoria | Append-only (trigger), hash encadeado, `audit:verify`, usuário de banco da aplicação sem DELETE/UPDATE (produção) | ADR-009 |
| T13 | Brute force/credential stuffing no painel | Rate limit, bloqueio progressivo, MFA, senha forte, sessão com expiração e revogação | task 002/003 |
| T14 | XSS via inventário (nome de software, caminho) | Blade escapa por padrão; CSP; proibido `{!! !!}` para dados do endpoint | task 011 |
| T15 | SSRF | Manager não faz fetch de URLs fornecidas por agentes/operadores; webhooks (futuro) com allowlist | geral |
| T16 | SQL injection | Eloquent/bindings; sem SQL cru com entrada | geral |
| T17 | DoS por agentes (flood de métricas/payload gigante) | Limite de payload, rate limit por agente, validação, descarte | task 004 |
| T18 | Perda de dados | Backup + teste de restauração | task 014 |
| T19 | Uso indevido como ferramenta de vigilância | Escopo restrito, aviso/consentimento, sem keylogging/gravação, auditoria, política de retenção | docs/privacy (task 010/014) |

## Fora de escopo (explicitamente)
Ocultação de processo, evasão de antivírus, persistência clandestina, desativação de ferramentas de segurança, keylogging, captura de credenciais, gravação permanente de tela, bypass de UAC/tela de bloqueio. Qualquer pedido nesse sentido deve ser recusado.

## Riscos residuais
- Administrador do Manager com permissão de comando é, por definição, poderoso: mitigado por MFA, aprovação dupla para alto risco e auditoria, não eliminado.
- Rede interna sem TLS válido: Agent recusa HTTP; certificado deve ser confiável (CA interna).
