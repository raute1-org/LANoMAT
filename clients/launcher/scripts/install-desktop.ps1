#Requires -Version 5.1
<#
.SYNOPSIS
Registers the LANoMAT Launcher as the handler for lanomat:// links on Windows.

.DESCRIPTION
Writes, in the current user's hive only (no administrator rights, no machine-wide change):

  HKCU\Software\Classes\lanomat                                   the URL protocol
  HKCU\Software\Classes\lanomat\DefaultIcon                       the launcher icon
  HKCU\Software\Classes\lanomat\shell\open\command                "launcher.exe" "%1"
  HKCU\Software\Microsoft\Windows\CurrentVersion\App Paths\lanomat-launcher.exe

so that lanomat://install/<slug> and lanomat://launch/<slug> open the launcher even if the
exe was moved after the first start. The app also calls the Tauri deep-link registrar on its
own first start; this script exists for the case where the exe never ran, the entry points at
an old path, or a repackaged build must be registered without starting it.

Running it again is harmless: every key is written, not appended. -Uninstall removes exactly
the two keys above and nothing else.

.PARAMETER Exe
The lanomat-launcher.exe to register. Default: next to this script, then its parent folder,
then %LOCALAPPDATA%\Programs\LANoMAT Launcher, then %LOCALAPPDATA%.

.PARAMETER Uninstall
Remove the URL protocol and App Paths entries again.

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install-desktop.ps1

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install-desktop.ps1 -Exe "D:\LAN\lanomat-launcher.exe"

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install-desktop.ps1 -Uninstall

.NOTES
The file is ASCII on purpose: Windows PowerShell 5.1 reads a BOM-less UTF-8 script as ANSI,
so German umlauts would be mangled.
#>
[CmdletBinding()]
param(
    [string] $Exe,

    [switch] $Uninstall
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ExeName = 'lanomat-launcher.exe'
$Scheme = 'lanomat'
$ProtocolKey = "HKCU:\Software\Classes\$Scheme"
$IconKey = "$ProtocolKey\DefaultIcon"
$CommandKey = "$ProtocolKey\shell\open\command"
$AppPathKey = "HKCU:\Software\Microsoft\Windows\CurrentVersion\App Paths\$ExeName"

function Resolve-LauncherExe {
    param([string] $Given)

    $candidates = @()
    if ($Given) {
        $candidates += $Given
    }
    if ($PSScriptRoot) {
        $candidates += (Join-Path $PSScriptRoot $ExeName)
        $candidates += (Join-Path (Split-Path -Parent $PSScriptRoot) $ExeName)
    }
    if ($env:LOCALAPPDATA) {
        $candidates += (Join-Path $env:LOCALAPPDATA "Programs\LANoMAT Launcher\$ExeName")
        $candidates += (Join-Path $env:LOCALAPPDATA $ExeName)
    }

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate -PathType Leaf)) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    $list = ($candidates | ForEach-Object { "  $_" }) -join "`r`n"
    throw "lanomat-launcher.exe not found. Looked for:`r`n$list`r`nPass -Exe <path>."
}

function Set-RegistryString {
    param(
        [string] $Path,
        [string] $Name,
        [string] $Value
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -Path $Path -Force | Out-Null
    }
    New-ItemProperty -LiteralPath $Path -Name $Name -Value $Value -PropertyType String -Force | Out-Null
}

function Remove-RegistryKey {
    param([string] $Path)

    if (Test-Path -LiteralPath $Path) {
        Remove-Item -LiteralPath $Path -Recurse -Force
        Write-Host "entfernt:         $Path"
    }
    else {
        Write-Host "nicht vorhanden:  $Path"
    }
}

if ($Uninstall) {
    Remove-RegistryKey $ProtocolKey
    Remove-RegistryKey $AppPathKey
    Write-Host "lanomat:// ist wieder abgemeldet (die Dateien bleiben liegen)."
    exit 0
}

$LauncherExe = Resolve-LauncherExe $Exe
$ExpectedCommand = '"{0}" "%1"' -f $LauncherExe
$ExpectedIcon = '"{0}",0' -f $LauncherExe

Set-RegistryString $ProtocolKey '(default)' 'URL:LANoMAT Launcher'
Set-RegistryString $ProtocolKey 'URL Protocol' ''
Set-RegistryString $IconKey '(default)' $ExpectedIcon
Set-RegistryString $CommandKey '(default)' $ExpectedCommand

Set-RegistryString $AppPathKey '(default)' $LauncherExe
Set-RegistryString $AppPathKey 'Path' (Split-Path -Parent $LauncherExe)

# Read back instead of trusting the write: a redirected or locked key must not look like success.
$command = (Get-ItemProperty -LiteralPath $CommandKey).'(default)'
if ($command -ne $ExpectedCommand) {
    throw "registry write failed: $CommandKey is '$command', expected '$ExpectedCommand'"
}
$appPath = (Get-ItemProperty -LiteralPath $AppPathKey).'(default)'
if ($appPath -ne $LauncherExe) {
    throw "registry write failed: $AppPathKey is '$appPath', expected '$LauncherExe'"
}

Write-Host "eingetragen:      $ProtocolKey"
Write-Host "  open command:   $ExpectedCommand"
Write-Host "eingetragen:      $AppPathKey"
Write-Host "lanomat://-Links oeffnen jetzt $LauncherExe"
Write-Host "Entfernen: powershell -ExecutionPolicy Bypass -File $($MyInvocation.MyCommand.Path) -Uninstall"
