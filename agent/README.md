# Agent Windows (C#)

Solução em [`Toolit/Toolit.sln`](Toolit/Toolit.sln) (criada pelo dono do projeto a partir do template *Worker Service*; ver ADR-014). Alvo `net8.0` central em `Toolit/Directory.Build.props` (migrar para `net10.0` quando o SDK 10 estiver instalado — ADR-010).

| Projeto | Papel |
|---|---|
| `Toolit/AgentToolit.csproj` | Host do serviço (`UseWindowsService`), workers e comandos `enroll`, `status`, `rotate-key` |
| `AgentToolit.Core` | Protocolo e assinatura, identidade (ECDSA P-256 + DPAPI), `ManagerClient`, fila SQLite, logs com redação, heartbeat e envio |
| `AgentToolit.Collectors` | Inventário (sistema, hardware, software, rede, periféricos) e métricas |
| `AgentToolit.Tests` | xUnit — inclui leituras **reais** do Windows (`Category=WindowsReal`) |

## Desenvolvimento

```powershell
cd agent\Toolit
dotnet build Toolit.sln
dotnet test Toolit.sln                                     # 149 testes
dotnet test Toolit.sln --filter "Category=WindowsReal" --logger "console;verbosity=detailed"   # leituras reais + tempos
dotnet publish Toolit\AgentToolit.csproj -c Release -o publish
```

### Rodar contra um Manager local (sem instalar serviço)
```powershell
$env:TOOLIT_AGENT_DATA = "$env:TEMP\toolit-agent"            # dados fora de %ProgramData% (dev)
$env:TOOLIT_ENROLLMENT_TOKEN = "tk_..."                       # gerado em "Novo agente" ou toolit:enrollment-token
.\publish\AgentToolit.exe enroll --server http://127.0.0.1:8000   # HTTP só é aceito em loopback
.\publish\AgentToolit.exe                                         # executa em primeiro plano (Ctrl+C encerra)
.\publish\AgentToolit.exe status                                  # estado local, sem segredos
```

## Garantias de segurança (testadas)
- Só HTTPS (HTTP apenas em loopback); validação de certificado padrão, sem bypass.
- Chave privada **nunca** sai da máquina; em disco só como blob DPAPI. Token de enrollment nunca é gravado nem logado.
- Cada request é assinado; nonce e timestamp novos a cada tentativa.
- Logs passam por redação (tokens, assinaturas, senhas, blobs).
- Nenhum shell: só as tarefas tipadas do catálogo local ([`docs/api/task-types.md`](../docs/api/task-types.md)), validadas de novo no Agent, sobre APIs .NET; processos críticos nunca são encerrados. Nada é ocultado: serviço comum do Windows.

## Instalação como serviço
Instalador MSI: projeto `agent/Toolit/ToolitSetup` (ver [`installer/README.md`](../installer/README.md)). Comandos do próprio Agent: `service install --server <url> --token <tk_...>` e `service uninstall` (exigem Administrador; `--dry-run` simula). **Ainda não instalado de verdade como serviço** (L8).
