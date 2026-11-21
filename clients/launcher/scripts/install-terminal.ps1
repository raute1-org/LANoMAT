#Requires -Version 5.1
<#
.SYNOPSIS
Turns this PC into a LANoMAT gaming terminal or arcade cabinet.

.DESCRIPTION
Installs the launcher for the current user only (no administrator rights, no
network, no secrets) and makes it start at login:

  %LOCALAPPDATA%\Programs\LANoMAT Launcher\lanomat-launcher.exe
  %APPDATA%\Microsoft\Windows\Start Menu\Programs\LANoMAT Launcher.lnk
  HKCU\Software\Microsoft\Windows\CurrentVersion\Run\LANoMAT Launcher

- -Arcade starts the launcher fullscreen (Escape leaves it); -Kiosk locks the
  fullscreen so a visitor cannot reach the desktop.
- The Start Menu shortcut carries the same arguments, so an operator can start
  a cabinet by hand after closing it.
- -NoAutostart writes the shortcut only, no Run value.
- -Name writes a tiny .cmd shim that exports LANOMAT_TERMINAL_NAME before
  starting the launcher: that is the name the orga monitor shows for this
  cabinet (default: the computer name).
- -Uninstall removes exactly the files and the Run value this script adds, and
  is safe to repeat.

Running it again is harmless: the exe is copied over, the shortcut replaced and
the Run value overwritten, never appended. The URL scheme (lanomat://) is
registered by the sibling install-desktop.ps1 when it is present.

.PARAMETER Path
The launcher .exe or .zip to install. Default: next to this script, then its
parent folder, then an already installed copy.

.PARAMETER Name
Terminal name for the monitor (letters, digits, space, . _ -; up to 60).

.PARAMETER Arcade
Cabinet preset: fullscreen and autostart.

.PARAMETER Kiosk
Locked fullscreen: Escape cannot leave it.

.PARAMETER NoAutostart
Do not start the launcher at login.

.PARAMETER Uninstall
Remove the shortcut, the Run value, the shim and the installed launcher again.

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install-terminal.ps1 -Arcade -Name "Arcade 3"

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install-terminal.ps1 .\lanomat-launcher.zip -Kiosk

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install-terminal.ps1 -Uninstall

.NOTES
The file is ASCII on purpose: Windows PowerShell 5.1 reads a BOM-less UTF-8
script as ANSI, so German umlauts would be mangled.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string] $Path,

    [string] $Name,

    [switch] $Arcade,

    [switch] $Kiosk,

    [switch] $NoAutostart,

    [switch] $Uninstall
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ExeName = 'lanomat-launcher.exe'
$ShortcutName = 'LANoMAT Launcher.lnk'
$RunValueName = 'LANoMAT Launcher'
$ShimName = 'lanomat-launcher-terminal.cmd'

if (-not $env:LOCALAPPDATA) {
    throw 'LOCALAPPDATA is not set; this script needs a normal user profile.'
}
if (-not $env:APPDATA) {
    throw 'APPDATA is not set; this script needs a normal user profile.'
}

$InstallDir = Join-Path $env:LOCALAPPDATA 'Programs\LANoMAT Launcher'
$InstalledExe = Join-Path $InstallDir $ExeName
$ShimPath = Join-Path $InstallDir $ShimName
$ShortcutPath = Join-Path $env:APPDATA "Microsoft\Windows\Start Menu\Programs\$ShortcutName"
$RunKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
$DesktopScript = if ($PSScriptRoot) { Join-Path $PSScriptRoot 'install-desktop.ps1' } else { $null }

function Get-LaunchArguments {
    $arguments = @()
    if ($Arcade) { $arguments += '--arcade' }
    if ($Kiosk) { $arguments += '--kiosk' }
    return ($arguments -join ' ')
}

function Remove-RunValue {
    if (-not (Test-Path -LiteralPath $RunKey)) {
        return
    }
    $existing = Get-ItemProperty -LiteralPath $RunKey -Name $RunValueName -ErrorAction SilentlyContinue
    if ($null -ne $existing) {
        Remove-ItemProperty -LiteralPath $RunKey -Name $RunValueName -Force
        Write-Host "entfernt:         $RunKey\$RunValueName"
    }
}

function Remove-Shortcut {
    if (Test-Path -LiteralPath $ShortcutPath) {
        Remove-Item -LiteralPath $ShortcutPath -Force
        Write-Host "entfernt:         $ShortcutPath"
    }
}

function Remove-InstallDir {
    if (-not (Test-Path -LiteralPath $InstallDir)) {
        return
    }
    # Only our own launcher folder: never delete a directory that does not carry
    # the installed exe.
    if (Test-Path -LiteralPath $InstalledExe) {
        Remove-Item -LiteralPath $InstallDir -Recurse -Force
        Write-Host "entfernt:         $InstallDir"
    }
    else {
        Write-Host "behalten:         $InstallDir (kein $ExeName darin)"
    }
}

function Resolve-Source {
    param([string] $Given)

    $candidates = @()
    if ($Given) {
        $candidates += $Given
    }
    if ($PSScriptRoot) {
        $candidates += (Join-Path $PSScriptRoot $ExeName)
        $candidates += (Join-Path $PSScriptRoot "$ExeName.zip")
        $candidates += (Join-Path (Split-Path -Parent $PSScriptRoot) $ExeName)
        # The release ships lanomat-launcher-<version>-windows-x86_64-portable.exe.
        $candidates += @(Get-ChildItem -LiteralPath $PSScriptRoot -Filter 'lanomat-launcher*.exe' -File -ErrorAction SilentlyContinue |
            Select-Object -ExpandProperty FullName)
    }
    if (Test-Path -LiteralPath $InstalledExe) {
        $candidates += $InstalledExe
    }

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate -PathType Leaf)) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    $list = ($candidates | ForEach-Object { "  $_" }) -join "`r`n"
    throw "no $ExeName found. Looked for:`r`n$list`r`nPass -Path <exe-or-zip>."
}

function Get-ExeFromSource {
    param([string] $Source)

    if ($Source -notlike '*.zip') {
        $leaf = [System.IO.Path]::GetFileName($Source)
        # Accept the release name (lanomat-launcher-<version>-windows-x86_64-portable.exe)
        # next to the canonical lanomat-launcher.exe.
        if ($leaf -ne $ExeName -and $leaf -notlike 'lanomat-launcher*.exe') {
            throw "$Source is not $ExeName, not a lanomat-launcher*.exe and not a .zip archive."
        }
        return $Source
    }

    $script:UnpackDir = Join-Path ([System.IO.Path]::GetTempPath()) ("lanomat-terminal-" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $script:UnpackDir -Force | Out-Null
    Expand-Archive -LiteralPath $Source -DestinationPath $script:UnpackDir -Force

    $found = Get-ChildItem -LiteralPath $script:UnpackDir -Filter $ExeName -File -Recurse | Select-Object -First 1
    if (-not $found) {
        throw "no $ExeName inside $Source"
    }
    return $found.FullName
}

function Test-TerminalName {
    param([string] $Value)

    if (-not $Value) {
        return
    }
    if ($Value -notmatch '^[A-Za-z0-9 ._-]{1,60}$') {
        throw "-Name accepts letters, digits, space, . _ and - only (up to 60 characters): '$Value'"
    }
}

function Write-Shim {
    param(
        [string] $Exe,
        [string] $Arguments
    )

    $lines = @(
        '@echo off',
        ('set "LANOMAT_TERMINAL_NAME=' + $Name + '"'),
        ('start "" "' + $Exe + '" ' + $Arguments)
    )
    Set-Content -LiteralPath $ShimPath -Value $lines -Encoding Ascii
    Write-Host "shim:             $ShimPath"
}

function New-Shortcut {
    param(
        [string] $Target,
        [string] $Arguments,
        [string] $Icon,
        [switch] $Minimized
    )

    $folder = Split-Path -Parent $ShortcutPath
    if (-not (Test-Path -LiteralPath $folder)) {
        New-Item -ItemType Directory -Path $folder -Force | Out-Null
    }

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($ShortcutPath)
    $shortcut.TargetPath = $Target
    if ($Arguments) {
        $shortcut.Arguments = $Arguments
    }
    $shortcut.WorkingDirectory = $InstallDir
    $shortcut.IconLocation = "$Icon,0"
    # 7 = minimized: a .cmd shim must not leave a console window on a cabinet.
    # The exe itself keeps 1 (normal) so the launcher window is not minimized.
    $shortcut.WindowStyle = if ($Minimized) { 7 } else { 1 }
    $shortcut.Description = 'LANoMAT Launcher (gaming terminal)'
    $shortcut.Save()
    Write-Host "start menu:       $ShortcutPath"
}

function Set-RunValue {
    param([string] $Command)

    if (-not (Test-Path -LiteralPath $RunKey)) {
        New-Item -Path $RunKey -Force | Out-Null
    }
    New-ItemProperty -LiteralPath $RunKey -Name $RunValueName -Value $Command -PropertyType String -Force | Out-Null

    # Read back instead of trusting the write.
    $written = (Get-ItemProperty -LiteralPath $RunKey -Name $RunValueName).$RunValueName
    if ($written -ne $Command) {
        throw "registry write failed: $RunKey\$RunValueName is '$written', expected '$Command'"
    }
    Write-Host "autostart:        $Command"
}

if ($Uninstall) {
    Remove-RunValue
    Remove-Shortcut
    Remove-InstallDir

    if ($DesktopScript -and (Test-Path -LiteralPath $DesktopScript)) {
        & $DesktopScript -Uninstall
    }

    Write-Host 'LANoMAT-Terminal entfernt.'
    exit 0
}

Test-TerminalName $Name

$Source = Resolve-Source $Path
$UnpackDir = $null

try {
    $SourceExe = Get-ExeFromSource $Source

    New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
    Copy-Item -LiteralPath $SourceExe -Destination $InstalledExe -Force
    if (-not (Test-Path -LiteralPath $InstalledExe)) {
        throw "copy failed: $InstalledExe is missing"
    }
    Write-Host "binary:           $InstalledExe"

    $arguments = Get-LaunchArguments
    $target = $InstalledExe

    if ($Name) {
        Write-Shim -Exe $InstalledExe -Arguments $arguments
        $target = $ShimPath
    }

    New-Shortcut -Target $target -Arguments $(if ($Name) { '' } else { $arguments }) -Icon $InstalledExe -Minimized:([bool]$Name)

    if (-not $NoAutostart) {
        $command = '"{0}"' -f $target
        if (-not $Name -and $arguments) {
            $command = '{0} {1}' -f $command, $arguments
        }
        Set-RunValue $command
    }
    else {
        Remove-RunValue
        Write-Host 'autostart:        none (-NoAutostart)'
    }

    if ($DesktopScript -and (Test-Path -LiteralPath $DesktopScript)) {
        & $DesktopScript -Exe $InstalledExe
    }
    else {
        Write-Host 'hinweis:          install-desktop.ps1 fehlt; lanomat:// bleibt unregistriert'
    }

    $shownName = if ($Name) { $Name } else { $env:COMPUTERNAME }
    Write-Host ''
    Write-Host 'Fertig: dieses Geraet ist ein LANoMAT-Terminal.'
    Write-Host "  Start:    $InstalledExe $arguments"
    Write-Host "  Name:     $shownName (LANOMAT_TERMINAL_NAME)"
    Write-Host '  Monitor:  die Terminal-Liste im Admin-Panel (alle 30 s ein Heartbeat)'
    Write-Host 'Entfernen: powershell -ExecutionPolicy Bypass -File install-terminal.ps1 -Uninstall'
}
finally {
    if ($UnpackDir -and (Test-Path -LiteralPath $UnpackDir)) {
        Remove-Item -LiteralPath $UnpackDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
