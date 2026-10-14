<#
.SYNOPSIS
  Instala, reinstala ou remove o Toolit Agent como serviço do Windows.

.DESCRIPTION
  Instalação (execute como Administrador):
    .\Install-ToolitAgent.ps1 -ServerUrl https://manager.empresa.local -EnrollmentToken tk_xxx

  - Copia os binários publicados para %ProgramFiles%\Toolit\Agent (somente Administradores/SYSTEM escrevem).
  - Registra o equipamento no Manager com o token de uso único (o token NUNCA é gravado em disco nem passado
    na linha de comando do serviço; é entregue ao processo por variável de ambiente).
  - Cria o serviço "ToolitAgent": LocalSystem, início automático (atrasado) e recuperação automática
    (reinicia após falha em 5 s / 30 s / 60 s).
  - Idempotente: rodar de novo atualiza os binários e mantém a identidade já registrada.

  Remoção:  .\Install-ToolitAgent.ps1 -Uninstall [-RemoveData]
  O Manager NÃO é avisado: revogue a instalação no painel (Computadores > Revogar agente).

  O serviço usa apenas os mecanismos documentados de serviços do Windows; nada é ocultado.

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
$ServiceName = 'ToolitAgent'
$DataDir = Join-Path $env:ProgramData 'Toolit\Agent'
$Exe = Join-Path $InstallDir 'AgentToolit.exe'

function Step([string]$Message) { Write-Host "==> $Message" }

function Invoke-Action([string]$Description, [scriptblock]$Action) {
    Step $Description
    if (-not $DryRun) { & $Action }
}

function Test-Admin {
    $principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Stop-AgentService {
    $svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($svc -and $svc.Status -ne 'Stopped') {
        Stop-Service -Name $ServiceName -Force
        $svc.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
    }
}

if (-not $DryRun -and -not (Test-Admin)) {
    throw 'Execute este script como Administrador (ou use -DryRun para apenas simular).'
}

# ------------------------------------------------------------------ remoção
if ($Uninstall) {
    Invoke-Action "Parando e removendo o serviço $ServiceName" {
        Stop-AgentService
        if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) { & sc.exe delete $ServiceName | Out-Null }
    }
    Invoke-Action "Removendo $InstallDir" {
        if (Test-Path $InstallDir) { Remove-Item -Path $InstallDir -Recurse -Force }
    }
    if ($RemoveData) {
        Invoke-Action "Removendo dados locais em $DataDir (identidade, fila e logs)" {
            if (Test-Path $DataDir) { Remove-Item -Path $DataDir -Recurse -Force }
        }
    } else {
        Step "Dados locais mantidos em $DataDir (use -RemoveData para apagar a identidade do agente)."
    }
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
    throw "Binários não encontrados em '$SourceDir'. Publique antes: dotnet publish -c Release -o <pasta>."
}

Invoke-Action 'Parando o serviço existente (se houver)' { Stop-AgentService }

Invoke-Action "Copiando binários para $InstallDir" {
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
    Copy-Item -Path (Join-Path $SourceDir '*') -Destination $InstallDir -Recurse -Force
    # Binários: leitura/execução para todos, escrita só Administradores e SYSTEM (impede troca por usuário comum).
    & icacls.exe $InstallDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
}

Invoke-Action 'Registrando o equipamento no Manager (token de uso único)' {
    $state = Join-Path $DataDir 'state.json'
    if (Test-Path $state) {
        Write-Host '    já registrado: identidade existente mantida.'
    } else {
        if ([string]::IsNullOrWhiteSpace($EnrollmentToken)) { throw '-EnrollmentToken é obrigatório no primeiro registro.' }
        $env:TOOLIT_ENROLLMENT_TOKEN = $EnrollmentToken
        try { & $Exe enroll --server $ServerUrl } finally { Remove-Item Env:\TOOLIT_ENROLLMENT_TOKEN -ErrorAction SilentlyContinue }
        if ($LASTEXITCODE -ne 0) { throw "Falha no registro (código $LASTEXITCODE). O token não foi gravado em lugar algum." }
    }
}

Invoke-Action "Criando o serviço $ServiceName" {
    if (-not (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue)) {
        & sc.exe create $ServiceName binPath= "`"$Exe`"" start= delayed-auto obj= LocalSystem DisplayName= 'Toolit Agent' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "sc create falhou ($LASTEXITCODE)." }
    } else {
        & sc.exe config $ServiceName binPath= "`"$Exe`"" start= delayed-auto | Out-Null
    }
    & sc.exe description $ServiceName 'Inventário e monitoramento do equipamento para o Toolit Manager.' | Out-Null
    & sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/30000/restart/60000 | Out-Null
    & sc.exe failureflag $ServiceName 1 | Out-Null
}

Invoke-Action 'Iniciando o serviço' {
    Start-Service -Name $ServiceName
    (Get-Service -Name $ServiceName).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
}

Invoke-Action 'Estado final' { & $Exe status }
Step 'Instalação concluída.'
