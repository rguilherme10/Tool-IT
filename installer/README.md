# installer/

| Arquivo | Estado |
|---|---|
| `Install-ToolitAgent.ps1` | Instala/atualiza/remove o serviço `ToolitAgent` (LocalSystem, início automático atrasado, recuperação automática). **Validado só em `-DryRun` e nas guardas de erro**; a criação real do serviço exige Administrador e ainda não foi testada (L8). |
| MSI / GPO / atualização assinada | Não iniciados — [task 012](../docs/tasks/012-instalacao-atualizacao-e-distribuicao.md) |

## Uso (como Administrador)

```powershell
# 1. Publicar o agente numa pasta "payload" ao lado do script
dotnet publish ..\agent\Toolit\Toolit\AgentToolit.csproj -c Release -o .\payload

# 2. Gerar um token em "Novo agente" no painel (uso único) e instalar
.\Install-ToolitAgent.ps1 -ServerUrl https://manager.empresa.local -EnrollmentToken tk_xxx

# Simular sem alterar nada (não exige Administrador)
.\Install-ToolitAgent.ps1 -ServerUrl https://manager.empresa.local -EnrollmentToken tk_x -DryRun

# Remover (-RemoveData apaga também a identidade do agente). Depois, revogue no painel.
.\Install-ToolitAgent.ps1 -Uninstall [-RemoveData]
```

Regras: nenhum segredo compartilhado no instalador — só o token de uso único **por máquina**, entregue ao processo por variável de ambiente (nunca em linha de comando, arquivo ou log). Binários em `%ProgramFiles%\Toolit\Agent` graváveis só por Administradores/SYSTEM; dados em `%ProgramData%\Toolit\Agent`.
