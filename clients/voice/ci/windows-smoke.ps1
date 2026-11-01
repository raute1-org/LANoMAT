# Starts the cross-built Windows binaries on a real Windows system and checks that they work.
#
#   windows-smoke.ps1 -Dir <folder with lanomat-voice.exe and lanomat-voice-app.exe> -Version <x.y.z>
#
# 1. `lanomat-voice.exe --version` prints the expected version.
# 2. The app starts, shows a window titled "LANoMAT Voice" and stays up.
# 3. The push-to-talk named pipe exists, and `lanomat-voice.exe ptt status` gets an answer through it.
#    Not being in a room is the expected answer: the reply "not in a room" proves the whole path
#    (pipe, DACL, protocol, engine handler) works.
param(
    [Parameter(Mandatory = $true)][string]$Dir,
    [Parameter(Mandatory = $true)][string]$Version
)
$ErrorActionPreference = 'Stop'

$cli = Join-Path $Dir 'lanomat-voice.exe'
$app = Join-Path $Dir 'lanomat-voice-app.exe'

$out = (& $cli --version) -join ' '
Write-Host "CLI: $out"
if ($out -notmatch [regex]::Escape($Version)) { throw "CLI version '$out' does not contain '$Version'" }

$p = Start-Process -FilePath $app -PassThru
try {
    $title = ''
    for ($i = 0; $i -lt 45; $i++) {
        Start-Sleep -Seconds 1
        $p.Refresh()
        if ($p.HasExited) { throw "the app exited with code $($p.ExitCode)" }
        $title = $p.MainWindowTitle
        if ($title -eq 'LANoMAT Voice') { break }
    }
    if ($title -ne 'LANoMAT Voice') { throw "window 'LANoMAT Voice' not found (title: '$title')" }
    Write-Host "App window is up: '$title'"

    $pipe = $null
    for ($i = 0; $i -lt 15 -and -not $pipe; $i++) {
        $pipe = [System.IO.Directory]::GetFiles('\\.\pipe\') | Where-Object { $_ -like '*lanomat-voice-*' } | Select-Object -First 1
        if (-not $pipe) { Start-Sleep -Seconds 1 }
    }
    if (-not $pipe) { throw 'the push-to-talk pipe was not created' }
    Write-Host "Pipe: $pipe"

    $reply = (& $cli ptt status 2>&1) -join ' '
    Write-Host "ptt status -> $reply (exit $LASTEXITCODE)"
    if ($reply -notmatch 'not in a room') { throw "unexpected reply from the app: '$reply'" }

    # A second client while the app owns the pipe must not break it.
    $again = (& $cli ptt status 2>&1) -join ' '
    if ($again -notmatch 'not in a room') { throw "second request failed: '$again'" }

    $p.Refresh()
    if ($p.HasExited) { throw "the app exited with code $($p.ExitCode)" }
    Write-Host 'Windows smoke test passed.'
}
finally {
    if (-not $p.HasExited) { Stop-Process -Id $p.Id -Force }
}

# The last native command (ptt status) exits 1 on purpose; do not leak that as the script result.
exit 0
