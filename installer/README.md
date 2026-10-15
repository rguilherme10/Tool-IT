# installer/

O instalador oficial é o projeto **`agent/Toolit/ToolitSetup`** (Visual Studio Installer Project → `ToolitSetup.msi`). Este diretório guarda o que o complementa.

| Arquivo | Papel | Estado |
|---|---|---|
| `Patch-ToolitMsi.ps1` | Roda como **evento pós-build** do `ToolitSetup`: acrescenta ao MSI o diálogo de URL/token e as ações que instalam o **serviço** | ✔ validado nas tabelas do MSI e na extração dos arquivos; **instalação real não testada** (L8) |
| `Install-ToolitAgent.ps1` | Alternativa em script (sem MSI): copia os arquivos e chama `AgentToolit.exe service install` | ✔ validado em `-DryRun` e nas guardas; **sem Administrador não foi executado de verdade** |
| MSI assinado, GPO, atualização automática | — | task 012 |

## Como o MSI instala o serviço

Projetos de instalação do Visual Studio não criam serviços para .NET moderno (só classes `Installer` do .NET Framework). Então:

1. O **próprio `AgentToolit.exe`** sabe instalar/remover o serviço: `AgentToolit.exe service install --server <url> --token <tk_...>` e `service uninstall` (cria o serviço `ToolitAgent` como LocalSystem, início automático atrasado, recuperação automática 5 s/30 s/60 s, registra o equipamento no Manager se ainda não registrado, inicia e confere que chegou a `RUNNING`; log em `%ProgramData%\Toolit\Agent\logs\install.log`).
2. `Patch-ToolitMsi.ps1` completa o MSI gerado com **ações personalizadas** (tabelas padrão `CustomAction`/`InstallExecuteSequence`) que executam esse comando como SYSTEM:

| Momento | Ação | Observação |
|---|---|---|
| antes de copiar arquivos | `sc.exe stop ToolitAgent` | libera o executável em atualização/reparo; falha ignorada |
| depois de copiar | `service install …` | idempotente; em PC já registrado não pede token |
| falha na instalação | `service uninstall` (rollback) | não roda em atualização, para não deixar o PC sem agente |
| desinstalação | `service uninstall` (antes de apagar arquivos) | **não roda** quando a remoção faz parte de uma atualização (`UPGRADINGPRODUCTCODE`) |

A identidade do agente (`%ProgramData%\Toolit\Agent`) é **mantida** na desinstalação/atualização. Para apagá-la: `AgentToolit.exe service uninstall --remove-data` (e revogue no painel).

## Gerar o MSI

Pré-requisitos: Visual Studio 2022 com a extensão **Microsoft Visual Studio Installer Projects**.

```powershell
# Pelo Visual Studio: Build > Build ToolitSetup (Release). Ou, sem abrir a IDE:
& "C:\Program Files\Microsoft Visual Studio\2022\Community\Common7\IDE\devenv.com" `
  agent\Toolit\Toolit.sln /Build "Release|Any CPU" /Project ToolitSetup\ToolitSetup.vdproj
# Saída: agent\Toolit\ToolitSetup\Release\ToolitSetup.msi (≈ 33 MB, x64, autossuficiente — não exige .NET instalado)
```
O evento pós-build chama `Patch-ToolitMsi.ps1` (o log mostra `Patch-ToolitMsi: OK`); se a verificação interna falhar, o build falha. O `.vdproj` **não** compila com `dotnet build`.

## Instalar

**Interativo** (duplo clique em `ToolitSetup.msi`, como Administrador): Bem-vindo → Pasta → **Conexão com o Manager** (URL `https://…` e token de “Novo agente”, mascarado) → Confirmar.

**Silencioso / script / GPO**
```powershell
msiexec /i ToolitSetup.msi /qn /l*v C:\Windows\Temp\toolit-setup.log `
        SERVERURL=https://manager.empresa.local ENROLLTOKEN=tk_xxxxxxxxxxxxxxxxxxxx
```
Para GPO (instalação atribuída ao computador) as propriedades vão numa **transformação `.mst`** (Orca). Em reparo/atualização de um PC já registrado, `SERVERURL`/`ENROLLTOKEN` podem ficar em branco.

**Atualização**: aumente `ProductVersion` no projeto (o MSI atual é 1.0.0; versões iguais não disparam atualização) e gere de novo; o Windows Installer instala a versão nova e remove a antiga sem apagar o serviço nem a identidade.

## Segurança do token (leia)
- Uso único, expira (padrão 24 h) e fica só como hash no Manager — use **um token por máquina**, ou multiuso com validade curta para GPO.
- Durante a instalação o token aparece por instantes na linha de comando do `AgentToolit.exe` (visível a administradores locais) e nas propriedades do MSI. O log verboso do Windows Installer o **mascara** (`MsiHiddenProperties`); mesmo assim, não guarde logs de instalação com o token e use tokens de validade curta.
- O token nunca é gravado em disco pelo Agent nem aparece em `install.log`.

## Limitações
Ver `docs/known-limitations.md` (L8, L16–L18). Resumo: o serviço ainda não foi criado numa instalação real (exige Administrador), o MSI não é assinado (SmartScreen/UAC mostram “editor desconhecido”) e o pacote autossuficiente não recebe correções do runtime .NET pelo Windows Update — o Agent precisa ser reempacotado (task 012).
