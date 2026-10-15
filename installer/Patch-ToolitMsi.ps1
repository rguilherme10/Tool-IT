<#
.SYNOPSIS
  Complementa o MSI gerado pelo projeto ToolitSetup (Visual Studio Installer Project) para instalar o
  AgentToolit como SERVIÇO do Windows e pedir a URL do Manager e o token de registro.

.DESCRIPTION
  Projetos de instalação do Visual Studio (.vdproj) não criam serviços para .NET moderno. Este script roda como
  evento pós-build do ToolitSetup e acrescenta ao MSI (tabelas padrão do Windows Installer):

    * Diálogo "ToolitConfigForm" entre a escolha da pasta e a confirmação (URL do Manager + token mascarado).
    * Ações personalizadas que executam o próprio AgentToolit.exe instalado:
        service install --server <URL> --token <token>   (após copiar os arquivos)
        service uninstall                                (antes de remover os arquivos; NÃO roda em atualização)
      além de parar o serviço antes de substituir arquivos e desfazer a criação do serviço se a instalação falhar.
    * Propriedades públicas SERVERURL e ENROLLTOKEN para instalação silenciosa:
        msiexec /i ToolitSetup.msi /qn SERVERURL=https://manager.empresa.local ENROLLTOKEN=tk_xxx
      O token é mascarado em logs verbosos (MsiHiddenProperties).

  Idempotente: rodar duas vezes sobre o mesmo MSI produz o mesmo resultado.
  Sai com código diferente de zero (falhando o build) se qualquer verificação final falhar.

.PARAMETER Msi
  Caminho do .msi gerado (relativo ou absoluto).
#>
[CmdletBinding()]
param([Parameter(Mandatory)][string]$Msi)

$ErrorActionPreference = 'Stop'
$Msi = (Resolve-Path -LiteralPath $Msi).Path
Write-Host "Patch-ToolitMsi: $Msi"

# ----------------------------------------------------------------------------- COM helpers
$installer = New-Object -ComObject WindowsInstaller.Installer
function Invoke-Com($target, [string]$name, [string]$kind, $arguments = $null) {
    [System.__ComObject].InvokeMember($name, [Reflection.BindingFlags]$kind, $null, $target, $arguments)
}

function New-Record($values) {
    $record = Invoke-Com $installer 'CreateRecord' 'InvokeMethod' @($values.Count)
    for ($i = 0; $i -lt $values.Count; $i++) {
        $v = $values[$i]
        if ($null -eq $v) { continue }                       # campo NULL
        if ($v -is [int]) { [void](Invoke-Com $record 'IntegerData' 'SetProperty' @(($i + 1), $v)) }
        else { [void](Invoke-Com $record 'StringData' 'SetProperty' @(($i + 1), [string]$v)) }
    }
    $record
}

function Invoke-Sql($db, [string]$sql, $values = $null) {
    $view = Invoke-Com $db 'OpenView' 'InvokeMethod' @($sql)
    if ($null -ne $values) { [void](Invoke-Com $view 'Execute' 'InvokeMethod' @((New-Record $values))) }
    else { [void](Invoke-Com $view 'Execute' 'InvokeMethod') }
    [void](Invoke-Com $view 'Close' 'InvokeMethod')
}

function Get-Scalar($db, [string]$sql) {
    $view = Invoke-Com $db 'OpenView' 'InvokeMethod' @($sql)
    [void](Invoke-Com $view 'Execute' 'InvokeMethod')
    $record = Invoke-Com $view 'Fetch' 'InvokeMethod'
    $value = $null
    if ($null -ne $record) { $value = Invoke-Com $record 'StringData' 'GetProperty' @(1) }
    [void](Invoke-Com $view 'Close' 'InvokeMethod')
    $value
}

function Get-Count($db, [string]$table, [string]$where) {
    # SQL do MSI: sem "SELECT 1" e sem "WHERE 1=1".
    $sql = "SELECT * FROM ``$table``"; if ($where -ne "1=1") { $sql += " WHERE $where" }
    $view = Invoke-Com $db "OpenView" "InvokeMethod" @($sql)
    [void](Invoke-Com $view 'Execute' 'InvokeMethod')
    $n = 0
    while ($null -ne (Invoke-Com $view 'Fetch' 'InvokeMethod')) { $n++ }
    [void](Invoke-Com $view 'Close' 'InvokeMethod')
    $n
}

function Set-Row($db, [string]$table, [string]$keyColumn, [string]$key, [string[]]$columns, $values) {
    Invoke-Sql $db "DELETE FROM ``$table`` WHERE ``$keyColumn``='$key'"
    $cols = ($columns | ForEach-Object { "``$_``" }) -join ','
    $marks = (1..$columns.Count | ForEach-Object { '?' }) -join ','
    Invoke-Sql $db "INSERT INTO ``$table`` ($cols) VALUES ($marks)" $values
}

function Set-Property($db, [string]$name, [string]$value) {
    Set-Row $db 'Property' 'Property' $name @('Property', 'Value') @($name, $value)
}

# ----------------------------------------------------------------------------- definições
# Tipos de ação personalizada (msidbCustomActionType*): Exe=2, Directory=0x20, InScript=0x400, Rollback=0x100,
# NoImpersonate=0x800 (roda como SYSTEM), Continue=0x40 (ignora falha do comando).
$TypeSetProperty  = 51
$TypeDeferredExe  = 2 + 0x20 + 0x400 + 0x800                 # 3106: deferred, SYSTEM, falha = erro
$TypeRollbackExe  = 2 + 0x20 + 0x400 + 0x100 + 0x800         # 3362: rollback, SYSTEM
$TypeDeferredBest = 2 + 0x20 + 0x400 + 0x800 + 0x40          # 3170: deferred, SYSTEM, falha ignorada

$AgentExe = '"[TARGETDIR]AgentToolit.exe"'
$notRemoving = 'NOT REMOVE~="ALL"'

# Ação imediata (define a propriedade) + ação deferida de mesmo nome (lê [CustomActionData]).
$actions = @(
    # Parar o serviço antes de substituir arquivos (atualização/reparo). sc.exe: falha ignorada se não existir.
    @{ Name = 'ToolitServiceStop';      Type = $TypeDeferredBest; Seq = 3950; Cond = $notRemoving
       Command = '"[System64Folder]sc.exe" stop ToolitAgent' },
    # Desfaz a criação do serviço se a instalação falhar depois (não em atualização, para não deixar o PC sem agente).
    @{ Name = 'ToolitServiceRollback';  Type = $TypeRollbackExe;  Seq = 4050; Cond = "$notRemoving AND NOT PREVIOUSVERSIONSINSTALLED"
       Command = "$AgentExe service uninstall" },
    # Cria/atualiza o serviço, registra no Manager (se ainda não registrado) e inicia.
    @{ Name = 'ToolitServiceInstall';   Type = $TypeDeferredExe;  Seq = 4100; Cond = $notRemoving
       Command = "$AgentExe service install --server `"[SERVERURL]`" --token `"[ENROLLTOKEN]`"" },
    # Remove o serviço antes de apagar os arquivos. NÃO roda quando a remoção faz parte de uma atualização
    # (o RemoveExistingProducts do vdproj roda DEPOIS da instalação da versão nova).
    @{ Name = 'ToolitServiceUninstall'; Type = $TypeDeferredExe;  Seq = 3450; Cond = 'REMOVE~="ALL" AND NOT UPGRADINGPRODUCTCODE'
       Command = "$AgentExe service uninstall" }
)
$setSeq = 1450  # após InstallValidate (1400), antes de InstallInitialize (1500)

# ----------------------------------------------------------------------------- aplicação
$db = Invoke-Com $installer 'OpenDatabase' 'InvokeMethod' @($Msi, 1)   # 1 = transacional (grava no Commit)

# Pasta/arquitetura: o pacote é x64 autossuficiente.
foreach ($t in @('CustomAction', 'InstallExecuteSequence', 'Dialog', 'Control', 'ControlEvent', 'ControlCondition', 'Property')) {
    # Falha cedo se o MSI não tiver a estrutura esperada (ex.: vdproj em outro formato).
    [void](Get-Count $db $t '1=1')
}

$order = 1
foreach ($a in $actions) {
    $setName = "Set$($a.Name)"
    Set-Row $db 'CustomAction' 'Action' $setName @('Action', 'Type', 'Source', 'Target') @($setName, $TypeSetProperty, $a.Name, $a.Command)
    Set-Row $db 'InstallExecuteSequence' 'Action' $setName @('Action', 'Condition', 'Sequence') @($setName, $a.Cond, ($setSeq + $order))
    # Deferida: diretório de trabalho = TARGETDIR; linha de comando vem de [CustomActionData].
    Set-Row $db 'CustomAction' 'Action' $a.Name @('Action', 'Type', 'Source', 'Target') @($a.Name, $a.Type, 'TARGETDIR', '[CustomActionData]')
    Set-Row $db 'InstallExecuteSequence' 'Action' $a.Name @('Action', 'Condition', 'Sequence') @($a.Name, $a.Cond, $a.Seq)
    $order++
}

# Propriedades públicas e segurança. SecureCustomProperties: permite passar SERVERURL/ENROLLTOKEN também em
# instalações gerenciadas (GPO/transformações) sem elevação do cliente.
$secure = Get-Scalar $db 'SELECT `Value` FROM `Property` WHERE `Property`=''SecureCustomProperties'''
$parts = @(); if ($secure) { $parts = $secure -split ';' }
foreach ($p in @('SERVERURL', 'ENROLLTOKEN')) { if ($parts -notcontains $p) { $parts += $p } }
Set-Property $db 'SecureCustomProperties' ($parts -join ';')
Set-Property $db 'MsiHiddenProperties' 'ENROLLTOKEN;ToolitServiceInstall'

# Fluxo de telas do vdproj: Welcome -> Folder -> Confirm. Inserimos ToolitConfigForm entre Folder e Confirm.
Set-Property $db 'FolderForm_NextArgs' 'ToolitConfigForm'
Set-Property $db 'ToolitConfigForm_PrevArgs' 'FolderForm'
Set-Property $db 'ToolitConfigForm_NextArgs' 'ConfirmInstallForm'
Set-Property $db 'ConfirmInstallForm_PrevArgs' 'ToolitConfigForm'

$dlg = 'ToolitConfigForm'
foreach ($table in @('Control', 'ControlEvent', 'ControlCondition')) { Invoke-Sql $db "DELETE FROM ``$table`` WHERE ``Dialog_``='$dlg'" }
Invoke-Sql $db "DELETE FROM ``Dialog`` WHERE ``Dialog``='$dlg'"

Invoke-Sql $db 'INSERT INTO `Dialog` (`Dialog`,`HCentering`,`VCentering`,`Width`,`Height`,`Attributes`,`Title`,`Control_First`,`Control_Default`,`Control_Cancel`) VALUES (?,?,?,?,?,?,?,?,?,?)' `
    @($dlg, 50, 50, 373, 287, 7, '[ProductName]', 'ServerEdit', 'NextButton', 'CancelButton')

$font = '{\VSI_MS_Sans_Serif13.0_0_0}'
$title = '{\VSI_MS_Sans_Serif16.0_1_0}'
$password = 0x200000  # msidbControlAttributesPasswordInput: o token aparece mascarado
$controls = @(
    # Control, Type, X, Y, W, H, Attributes, Property, Text, Control_Next
    @('BannerText', 'Text', 9, 9, 306, 33, 196611, $null, "${title}Conexão com o Manager", 'BannerBmp'),
    @('BannerBmp', 'Bitmap', 0, 0, 375, 52, 1, $null, 'DefBannerBitmap', 'Line1'),
    @('Line1', 'Line', 0, 52, 375, 6, 1, $null, "${font}MsiHorizontalLine", 'BodyText1'),
    @('BodyText1', 'Text', 9, 63, 354, 60, 131075, $null,
        "${font}Informe o endereço do Toolit Manager e o token de registro gerado em ""Novo agente"" no painel (uso único). Em uma atualização de um computador que já foi registrado, deixe os dois campos em branco.", 'ServerLabel'),
    @('ServerLabel', 'Text', 18, 132, 348, 12, 3, $null, "${font}&Endereço do Manager (https://...):", 'ServerEdit'),
    @('ServerEdit', 'Edit', 18, 146, 342, 18, 7, 'SERVERURL', $font, 'TokenLabel'),
    @('TokenLabel', 'Text', 18, 176, 348, 12, 3, $null, "${font}&Token de registro (tk_...):", 'TokenEdit'),
    @('TokenEdit', 'Edit', 18, 190, 342, 18, (7 + $password), 'ENROLLTOKEN', $font, 'Line2'),
    @('Line2', 'Line', 0, 252, 375, 6, 1, $null, "${font}MsiHorizontalLine", 'PreviousButton'),
    @('PreviousButton', 'PushButton', 156, 261, 66, 18, 3, $null, "${font}< &Voltar", 'CancelButton'),
    @('CancelButton', 'PushButton', 300, 261, 66, 18, 3, $null, "${font}Cancelar", 'NextButton'),
    @('NextButton', 'PushButton', 228, 261, 66, 18, 3, $null, "${font}&Avançar >", 'BannerText')
)
foreach ($c in $controls) {
    Invoke-Sql $db 'INSERT INTO `Control` (`Dialog_`,`Control`,`Type`,`X`,`Y`,`Width`,`Height`,`Attributes`,`Property`,`Text`,`Control_Next`) VALUES (?,?,?,?,?,?,?,?,?,?,?)' `
        (@($dlg) + $c)
}

$events = @(
    @('PreviousButton', 'NewDialog', '[ToolitConfigForm_PrevArgs]', 'ToolitConfigForm_PrevArgs<>""', 0),
    @('CancelButton', 'SpawnDialog', 'Cancel', $null, 0),
    @('NextButton', 'EndDialog', 'Return', 'ToolitConfigForm_NextArgs=""', 0),
    @('NextButton', 'NewDialog', '[ToolitConfigForm_NextArgs]', 'ToolitConfigForm_NextArgs<>""', 1)
)
foreach ($e in $events) {
    Invoke-Sql $db 'INSERT INTO `ControlEvent` (`Dialog_`,`Control_`,`Event`,`Argument`,`Condition`,`Ordering`) VALUES (?,?,?,?,?,?)' (@($dlg) + $e)
}
Invoke-Sql $db 'INSERT INTO `ControlCondition` (`Dialog_`,`Control_`,`Action`,`Condition`) VALUES (?,?,?,?)' @($dlg, 'PreviousButton', 'Disable', 'ToolitConfigForm_PrevArgs=""')
Invoke-Sql $db 'INSERT INTO `ControlCondition` (`Dialog_`,`Control_`,`Action`,`Condition`) VALUES (?,?,?,?)' @($dlg, 'PreviousButton', 'Enable', 'ToolitConfigForm_PrevArgs<>""')

[void](Invoke-Com $db 'Commit' 'InvokeMethod')
$db = $null; [GC]::Collect(); [GC]::WaitForPendingFinalizers()

# ----------------------------------------------------------------------------- verificação final (somente leitura)
$check = Invoke-Com $installer 'OpenDatabase' 'InvokeMethod' @($Msi, 0)
$failures = @()
function Expect($condition, [string]$message) { if (-not $condition) { $script:failures += $message } }

foreach ($a in $actions) {
    Expect ((Get-Count $check 'CustomAction' "``Action``='$($a.Name)' AND ``Type``=$($a.Type)") -eq 1) "CustomAction $($a.Name) ausente/incorreta"
    Expect ((Get-Count $check 'CustomAction' "``Action``='Set$($a.Name)' AND ``Type``=$TypeSetProperty AND ``Source``='$($a.Name)'") -eq 1) "Set$($a.Name) ausente"
    Expect ((Get-Count $check 'InstallExecuteSequence' "``Action``='$($a.Name)' AND ``Sequence``=$($a.Seq)") -eq 1) "Sequência de $($a.Name) ausente"
}
Expect ((Get-Count $check 'Dialog' "``Dialog``='$dlg'") -eq 1) 'Dialog ToolitConfigForm ausente'
Expect ((Get-Count $check 'Control' "``Dialog_``='$dlg'") -eq $controls.Count) 'Controles do diálogo incompletos'
Expect ((Get-Count $check 'Property' "``Property``='FolderForm_NextArgs' AND ``Value``='ToolitConfigForm'") -eq 1) 'Fluxo Folder->Config ausente'
Expect ((Get-Count $check 'Property' "``Property``='ConfirmInstallForm_PrevArgs' AND ``Value``='ToolitConfigForm'") -eq 1) 'Fluxo Config->Confirm ausente'
Expect ((Get-Count $check 'Property' "``Property``='MsiHiddenProperties'") -eq 1) 'MsiHiddenProperties ausente'

# Toda a cadeia Control_Next deve apontar para controles existentes do diálogo.
$names = $controls | ForEach-Object { $_[0] }
foreach ($c in $controls) { Expect ($names -contains $c[9]) "Control_Next inválido em $($c[0]) -> $($c[9])" }

if ($failures.Count -gt 0) { $failures | ForEach-Object { Write-Error $_ -ErrorAction Continue }; exit 1 }
Write-Host ("Patch-ToolitMsi: OK ({0} ações, diálogo com {1} controles)." -f ($actions.Count * 2), $controls.Count)
