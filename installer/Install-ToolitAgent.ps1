<#
.SYNOPSIS
  Alternativa em script ao MSI (ToolitSetup): instala, atualiza ou remove o Toolit Agent como serviço do Windows.

.DESCRIPTION
  Instalação (execute como Administrador):
    .\Install-ToolitAgent.ps1 -ServerUrl https://manager.empresa.local -EnrollmentToken tk_xxx

  - Copia os binários publicados para %ProgramFiles%\Toolit\Agent (somente Administradores/SYSTEM escrevem).
  - Delega a criação do serviço ao próprio executável (`AgentToolit.exe service install`), a MESMA lógica usada pelo
    MSI: registro no Manager com o token de uso único (entregue por variável de ambiente, nunca na linha de comando),
    serviço LocalSystem com início automático atrasado e recuperação automática (5 s / 30 s / 60 s).
  - Idempotente: rodar de novo atualiza os binários e mantém a identidade já registrada.

  Remoção:  .\Install-ToolitAgent.ps1 -Uninstall [-RemoveData]
  O Manager NÃO é avisado: revogue a instalação no painel (Computadores > Revogar agente).

.PARAMETER DryRun
  Mostra o que seria feito sem alterar o sistema (não exige Administrador).
#>
[CmdletBinding()]
param(
    [string]$ServerUrl,
    [string]$EnrollmentToken = $env:TOOLIT_ENROLLMENT_TOKEN,
    [string]$SourceDir,
    [string]$InstallDir = (Join-Path $env:ProgramFiles 'Toolit\Agent'),
    [switch]$Uninstall,
    [switch]$RemoveData,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
if (-not $SourceDir) { $SourceDir = Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) 'payload' }
$Exe = Join-Path $InstallDir 'AgentToolit.exe'

function Step([string]$Message) { Write-Host "==> $Message" }
function Invoke-Action([string]$Description, [scriptblock]$Action) { Step $Description; if (-not $DryRun) { & $Action } }
function Test-Admin {
    ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not $DryRun -and -not (Test-Admin)) { throw 'Execute este script como Administrador (ou use -DryRun para apenas simular).' }

# ------------------------------------------------------------------ remoção
if ($Uninstall) {
    Invoke-Action 'Removendo o serviço ToolitAgent' {
        if (Test-Path $Exe) {
            $args = @('service', 'uninstall'); if ($RemoveData) { $args += '--remove-data' }
            & $Exe @args
            if ($LASTEXITCODE -ne 0) { throw "Falha ao remover o serviço (código $LASTEXITCODE)." }
        }
    }
    Invoke-Action "Removendo $InstallDir" { if (Test-Path $InstallDir) { Remove-Item -Path $InstallDir -Recurse -Force } }
    Step 'Concluído. Lembre-se de revogar esta instalação no painel do Manager.'
    return
}

# ------------------------------------------------------------------ instalação
if ([string]::IsNullOrWhiteSpace($ServerUrl)) { throw '-ServerUrl é obrigatório (https://...).' }
$uri = $null
if (-not [Uri]::TryCreate($ServerUrl, [UriKind]::Absolute, [ref]$uri) -or
    ($uri.Scheme -ne 'https' -and -not ($uri.Scheme -eq 'http' -and $uri.IsLoopback))) {
    throw 'ServerUrl inválida: use HTTPS (HTTP só é aceito para localhost em desenvolvimento).'
}
if (-not (Test-Path (Join-Path $SourceDir 'AgentToolit.exe'))) {
    throw "Binários não encontrados em '$SourceDir'. Publique antes: dotnet publish agent\Toolit\Toolit\AgentToolit.csproj -c Release -o <pasta>."
}

Invoke-Action 'Parando o serviço existente (se houver)' {
    & sc.exe stop ToolitAgent | Out-Null   # 1060/1062 (inexistente/parado) são esperados
}

Invoke-Action "Copiando binários para $InstallDir" {
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
    Copy-Item -Path (Join-Path $SourceDir '*') -Destination $InstallDir -Recurse -Force
    # Binários: leitura/execução para todos; escrita só Administradores e SYSTEM (impede troca por usuário comum).
    & icacls.exe $InstallDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
}

Invoke-Action 'Registrando o equipamento e criando o serviço' {
    if (-not [string]::IsNullOrWhiteSpace($EnrollmentToken)) { $env:TOOLIT_ENROLLMENT_TOKEN = $EnrollmentToken }
    try { & $Exe service install --server $ServerUrl } finally { Remove-Item Env:\TOOLIT_ENROLLMENT_TOKEN -ErrorAction SilentlyContinue }
    if ($LASTEXITCODE -ne 0) { throw "Falha na instalação do serviço (código $LASTEXITCODE). Veja %ProgramData%\Toolit\Agent\logs\install.log" }
}

Invoke-Action 'Estado final' { & $Exe status }
Step 'Instalação concluída.'
