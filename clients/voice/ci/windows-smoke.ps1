# Starts the cross-built Windows files on a real Windows system and checks that they work.
#
#   windows-smoke.ps1 -Dir <folder> -Version <release string> -WindowsVersion <x.y.z>
#
# <folder> holds lanomat-voice.exe (CLI), lanomat-voice-app.exe (the portable app) and
# lanomat-voice-app-setup.exe (the NSIS installer). <WindowsVersion> is ci/win-version.sh's numeric
# mapping of <Version>.
#
#  1. `lanomat-voice.exe --version` prints the expected version.
#  2. Version metadata: the app exe and the installer carry the numeric Windows version.
#  3. PORTABLE: the exe, copied to an empty folder, starts without installation, shows a window titled
#     "LANoMAT Voice", creates the push-to-talk named pipe and answers `ptt status` through it
#     ("not in a room" proves the whole path: pipe, DACL, protocol, engine handler), writes nothing
#     next to itself (settings live in the user's AppData) and stops when asked.
#  4. INSTALLER: a silent per-user install (/S, /D=<temp dir>) puts the exe and the uninstaller in
#     place, creates the Start Menu shortcut and the uninstall registry entry; the INSTALLED exe
#     passes the same window/pipe checks, and a portable copy started next to the running installed
#     one does not break it (the pipe is per user, so two copies exclude each other: by design).
#  5. UNINSTALL: the silent uninstaller removes the install directory, the shortcut and the
#     registry entry.
param(
    [Parameter(Mandatory = $true)][string]$Dir,
    [Parameter(Mandatory = $true)][string]$Version,
    [Parameter(Mandatory = $true)][string]$WindowsVersion
)
$ErrorActionPreference = 'Stop'

$cli = Join-Path $Dir 'lanomat-voice.exe'
$app = Join-Path $Dir 'lanomat-voice-app.exe'
$setup = Join-Path $Dir 'lanomat-voice-app-setup.exe'
foreach ($f in @($cli, $app, $setup)) {
    if (-not (Test-Path -LiteralPath $f)) { throw "missing file: $f" }
}

function Step([string]$text) { Write-Host "`n=== $text ===" }

function Get-PttPipe {
    [System.IO.Directory]::GetFiles('\\.\pipe\') | Where-Object { $_ -like '*lanomat-voice-*' } | Select-Object -First 1
}

function Stop-App([System.Diagnostics.Process]$p) {
    if ($null -ne $p -and -not $p.HasExited) {
        Stop-Process -Id $p.Id -Force
        [void]$p.WaitForExit(15000)
    }
}

# Waits for the window, the pipe and a `ptt status` answer. Returns the process (still running).
function Start-AndCheck([string]$exe, [string]$label) {
    $p = Start-Process -FilePath $exe -PassThru
    $title = ''
    for ($i = 0; $i -lt 45; $i++) {
        Start-Sleep -Seconds 1
        $p.Refresh()
        if ($p.HasExited) { throw "[$label] the app exited with code $($p.ExitCode)" }
        $title = $p.MainWindowTitle
        if ($title -eq 'LANoMAT Voice') { break }
    }
    if ($title -ne 'LANoMAT Voice') { Stop-App $p; throw "[$label] window 'LANoMAT Voice' not found (title: '$title')" }
    Write-Host "[$label] window is up: '$title'"

    $pipe = $null
    for ($i = 0; $i -lt 15 -and -not $pipe; $i++) {
        $pipe = Get-PttPipe
        if (-not $pipe) { Start-Sleep -Seconds 1 }
    }
    if (-not $pipe) { Stop-App $p; throw "[$label] the push-to-talk pipe was not created" }
    Write-Host "[$label] pipe: $pipe"

    $reply = (& $cli ptt status 2>&1) -join ' '
    Write-Host "[$label] ptt status -> $reply"
    if ($reply -notmatch 'not in a room') { Stop-App $p; throw "[$label] unexpected reply from the app: '$reply'" }
    $again = (& $cli ptt status 2>&1) -join ' '
    if ($again -notmatch 'not in a room') { Stop-App $p; throw "[$label] second request failed: '$again'" }
    return $p
}

function Assert-Stopped([System.Diagnostics.Process]$p, [string]$label) {
    Stop-App $p
    if (-not $p.HasExited) { throw "[$label] the process did not stop" }
    $gone = $false
    for ($i = 0; $i -lt 10 -and -not $gone; $i++) {
        $gone = -not (Get-PttPipe)
        if (-not $gone) { Start-Sleep -Seconds 1 }
    }
    if (-not $gone) { throw "[$label] the pipe is still there after the app stopped" }
    Write-Host "[$label] process stopped, pipe released"
}

Step 'CLI version'
$out = (& $cli --version) -join ' '
Write-Host "CLI: $out"
if ($out -notmatch [regex]::Escape($Version)) { throw "CLI version '$out' does not contain '$Version'" }

Step "Version metadata (expected Windows version $WindowsVersion)"
$want = [version]$WindowsVersion
foreach ($f in @($app, $setup)) {
    $vi = (Get-Item -LiteralPath $f).VersionInfo
    Write-Host ("{0}: FileVersion='{1}' ProductVersion='{2}' ProductName='{3}' FileDescription='{4}'" -f (Split-Path $f -Leaf), $vi.FileVersion, $vi.ProductVersion, $vi.ProductName, $vi.FileDescription)
    $raw = [version]('{0}.{1}.{2}' -f $vi.FileMajorPart, $vi.FileMinorPart, $vi.FileBuildPart)
    if ($raw -ne $want) { throw "$(Split-Path $f -Leaf): file version $raw, expected $want" }
}
if ((Get-Item -LiteralPath $app).VersionInfo.ProductName -ne 'LANoMAT Voice') { throw 'the app exe has the wrong ProductName' }

Step 'PORTABLE exe'
$portableDir = Join-Path ([System.IO.Path]::GetTempPath()) ('lanomat-portable-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $portableDir | Out-Null
$portable = Join-Path $portableDir 'lanomat-voice-app-portable.exe'
Copy-Item -LiteralPath $app -Destination $portable
$p = Start-AndCheck $portable 'portable'
Assert-Stopped $p 'portable'
$extra = @(Get-ChildItem -LiteralPath $portableDir -Force | Where-Object { $_.Name -ne 'lanomat-voice-app-portable.exe' })
if ($extra.Count -gt 0) { throw "the portable exe wrote next to itself: $($extra.Name -join ', ')" }
Write-Host '[portable] nothing written next to the exe (settings go to the user profile)'

Step 'INSTALLER (silent, per user)'
$installDir = Join-Path ([System.IO.Path]::GetTempPath()) ('lanomat-install-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
$startMenu = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs'
$uninstallKeys = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall'

function Get-UninstallEntry {
    if (-not (Test-Path $uninstallKeys)) { return $null }
    Get-ChildItem $uninstallKeys | ForEach-Object { Get-ItemProperty $_.PSPath } |
        Where-Object { $_.PSObject.Properties['DisplayName'] -and $_.DisplayName -eq 'LANoMAT Voice' } | Select-Object -First 1
}
function Get-Shortcut { Get-ChildItem -LiteralPath $startMenu -Recurse -Filter '*.lnk' -ErrorAction SilentlyContinue | Where-Object { $_.BaseName -eq 'LANoMAT Voice' } }

if (Get-UninstallEntry) { throw 'a LANoMAT Voice installation already exists on this machine' }

# /D must be the last argument and unquoted: the temp path has no spaces.
$proc = Start-Process -FilePath $setup -ArgumentList '/S', "/D=$installDir" -PassThru -Wait
if ($proc.ExitCode -ne 0) { throw "the installer exited with code $($proc.ExitCode)" }
$mainExe = @(Get-ChildItem -LiteralPath $installDir -Filter '*.exe' -ErrorAction Stop | Where-Object { $_.Name -ne 'uninstall.exe' })
Write-Host ("installed: " + ((Get-ChildItem -LiteralPath $installDir -Recurse | ForEach-Object { $_.FullName.Substring($installDir.Length) }) -join ', '))
if ($mainExe.Count -ne 1) { throw "expected exactly one program in the install dir, found: $($mainExe.Name -join ', ')" }
$uninstaller = Join-Path $installDir 'uninstall.exe'
if (-not (Test-Path -LiteralPath $uninstaller)) { throw 'no uninstall.exe in the install dir' }
$sc = @(Get-Shortcut)
if ($sc.Count -lt 1) { throw 'no Start Menu shortcut "LANoMAT Voice" was created' }
Write-Host "Start Menu shortcut: $($sc[0].FullName)"
$entry = Get-UninstallEntry
if (-not $entry) { throw 'no uninstall registry entry (HKCU) was created' }
Write-Host "uninstall entry: DisplayName='$($entry.DisplayName)' DisplayVersion='$($entry.DisplayVersion)' Publisher='$($entry.Publisher)'"
if ($entry.DisplayVersion -ne $WindowsVersion) { throw "DisplayVersion '$($entry.DisplayVersion)' is not the Windows version $WindowsVersion" }

$installed = $mainExe[0].FullName
# A finished install may launch the app (the Tauri template can run it after the last page): close
# whatever is running so the checks below start from a clean state.
Get-Process -Name 'lanomat-voice-app' -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 2
$pi =Start-AndCheck $installed 'installed'

# A second copy (the portable file) next to the running installed one must not break it.
$second = Start-Process -FilePath $portable -PassThru
Start-Sleep -Seconds 8
$second.Refresh()
Write-Host "[second copy] still running: $(-not $second.HasExited)"
Stop-App $second
$stillOk = (& $cli ptt status 2>&1) -join ' '
if ($stillOk -notmatch 'not in a room') { Stop-App $pi; throw "the installed copy stopped answering after a second copy was started: '$stillOk'" }
Write-Host '[installed] still answers after a second copy came and went'
Assert-Stopped $pi 'installed'

Step 'UNINSTALL (silent)'
# The NSIS uninstaller copies itself to a temp folder and returns at once: wait for the result.
Start-Process -FilePath $uninstaller -ArgumentList '/S' -Wait | Out-Null
$ok = $false
for ($i = 0; $i -lt 60 -and -not $ok; $i++) {
    $ok = -not (Test-Path -LiteralPath $installDir)
    if (-not $ok) { Start-Sleep -Seconds 1 }
}
if (-not $ok) {
    Write-Host ("left in the install dir: " + ((Get-ChildItem -LiteralPath $installDir -Recurse -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName }) -join ', '))
    throw 'the install directory is still there after the silent uninstall'
}
if (@(Get-Shortcut).Count -gt 0) { throw 'the Start Menu shortcut is still there after the uninstall' }
if (Get-UninstallEntry) { throw 'the uninstall registry entry is still there after the uninstall' }
Write-Host 'install dir, Start Menu shortcut and registry entry are gone'

Remove-Item -LiteralPath $portableDir -Recurse -Force -ErrorAction SilentlyContinue
Write-Host "`nWindows smoke test passed."
# The last native command (ptt status) exits 1 on purpose; do not leak that as the script result.
exit 0
