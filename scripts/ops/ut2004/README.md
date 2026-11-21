# UT2004 ops

Zentraler Index für den UT2004-One-Click-Pilot. Das Runbook steht in
[`docs/ops/ut2004-pilot.md`](../../../docs/ops/ut2004-pilot.md). Die
Abschnitte weiter unten pflegen die Tool-Owner; **Skriptkopf und `--help`
bleiben für alle Flags und Details maßgeblich**.

## Reihenfolge

```text
fetch-base.sh ──► extract-archive.sh ──► build-portable.sh ──► install-smoke.sh
       │                  │                      │                      │
   archive.org        ZIP/TAR/DMG/ISO        drei 3374-ZIPs        echter Launcher-Core
       └──────────────────────────────► live-recipe.mjs ◄──────────────┘
                                      Live-Panel (ID 20),
                                      Plan, Backup/Restore
```

`upload-file.sh` ist der optionale tus-Transport der gebauten ZIPs zum
Live-Filehost; er sitzt zwischen `build-portable.sh` und `install-smoke.sh`
bzw. dem echten Client-Download.

## Tool-Index

| Tool | Zweck | `--help` | Beispiel |
| --- | --- | --- | --- |
| `fetch-base.sh` | archive.org-Base parallel/resumierbar laden, Mirrors rotieren, Größe prüfen | `scripts/ops/ut2004/fetch-base.sh --help` | `scripts/ops/ut2004/fetch-base.sh --dry-run` |
| `extract-archive.sh` | ZIP/TAR/DMG/ISO/7z/RAR/SFX listen, Baum zeigen, extrahieren, Game-Daten prüfen | `scripts/ops/ut2004/extract-archive.sh --help` | `scripts/ops/ut2004/extract-archive.sh tree "ut2k4+keygen.zip" --depth 2` |
| `build-portable.sh` | drei flache, bereits gepatchte 3374-Archive bauen und verifizieren | `scripts/ops/ut2004/build-portable.sh --help` | `scripts/ops/ut2004/build-portable.sh --source "$HOME/Downloads/Unreal Tournament 2004.zip" --patches "$HOME/Downloads/ut2004-build/patches" --out clients-dist/ut2004` |
| `install-smoke.sh` | Live-Recipe auf lokalen HTTP-Server zeigen, echten Install ausführen, restaurieren | `scripts/ops/ut2004/install-smoke.sh --help` | `LANOMAT_ADMIN_COOKIES_FILE=/tmp/opencode/cookies.txt scripts/ops/ut2004/install-smoke.sh --dry-run` |
| `live-recipe.mjs` | Live-Panel (Filament-ID 20) lesen, Plan prüfen, Backup/Restore, URL/Patches setzen | `node scripts/ops/ut2004/live-recipe.mjs --help` | `node scripts/ops/ut2004/live-recipe.mjs show --json` |
| `upload-file.sh` | große Datei resumierbar per tus auf den Live-Filehost laden | positionell: `<file> <name> [chunk-mib]` (kein `--help`) | `scripts/ops/ut2004/upload-file.sh clients-dist/ut2004/ut2004-portable-3374-linux.zip ut2004-portable-3374-linux.zip 64` |

## Konventionen

- **`--help` zuerst.** Die Abschnitte unten sind Einstieg, nicht die
  vollständige Schnittstelle.
- **Trockenlauf vor Schreiben.** `fetch-base --dry-run`,
  `build-portable --dry-run`, `install-smoke --dry-run`, `live-recipe set`
  ohne `--apply`; `live-recipe --apply` verlangt `--yes`.
- **Keine Secrets als Argument.** Token aus
  `~/.config/lanomat-launcher/config.json`; Admin-Cookies aus
  `/tmp/opencode/cookies.txt` oder `LANOMAT_ADMIN_COOKIES_FILE`. Nichts
  davon drucken oder committen.
- **Kein `pkill`-Selbsttreffer.** Prozesse über PID oder `[p]attern` beenden;
  `fetch-base --take-over` beendet nur die Halter der eigenen Part-Dateien.
- **Aufräumen.** Private Work-Ordner entfernt jedes Tool selbst; `--keep` nur
  für Diagnose. Lokale HTTP-Server und `/tmp/opencode/utparts` danach von
  Hand aufräumen.
- **Ein Tool, ein Owner.** Wer ein Skript ändert, aktualisiert dessen Kopf und
  `--help`; der Index bleibt stabil. `scripts/ops/ut2004/` ist kein
  Download-Ordner: keine Spielartefakte, Cookies oder Backups committen.

## Archive extraction (Linux, no root)

`extract-archive.sh` unpacks the UT2004 sources the launcher meets in the wild on a
normal Linux account: ZIP repacks, the OldUnreal patches, the WinRAR SFX inside
`ut2k4+keygen.zip`, the macOS `.dmg`, and retail ISO images. It never uses `sudo`,
Wine, `hdiutil`, or a package manager.

```bash
scripts/ops/ut2004/extract-archive.sh --help
```

### Requirements

- Bash 4.3+ (namerefs, arrays).
- `bsdtar` (libarchive) or `unzip` for ZIP/ISO; `bsdtar`, `tar` or `7zz` for tarballs.
  Arch's `libarchive` package provides `bsdtar`.
- `curl` or `wget`, `xz`, and `sha256sum` (coreutils) for the one-time 7-Zip
  download.
- 7-Zip standalone (`7zz`) is cached under `~/.local/share/lanomat/tools`; no
  package manager and no root are needed.
- Free space for the extracted tree: the macOS patch DMG is ~1 GiB apparent,
  the nosTEAM SFX is ~6.3 GiB extracted.

### 7-Zip (`7zz`) acquisition

The script keeps its own binary at:

```text
${LANOMAT_UT2004_TOOLS_DIR:-${XDG_DATA_HOME:-$HOME/.local/share}/lanomat/tools}/7zz
```

On first use it downloads **7-Zip 25.01 x64** from
`https://www.7-zip.org/a/7z2501-linux-x64.tar.xz` and verifies the pinned SHA-256:

```text
4ca3b7c6f2f67866b92622818b58233dc70367be2f36b498eb0bdeaaa44b53f4
```

If that download fails, it tries **24.09** from the official GitHub release
(`ip7z/7zip`, tag `24.09`; 7-zip.org no longer keeps the old asset) with:

```text
914c7e20ad5ef8e4d3cf08620ff8894b28fe11b7eb99809d6930870fbe48a281
```

The tarball is unpacked in a temporary directory **next to the installed binary**
(not in `/tmp`; `/tmp` is a small tmpfs on many machines). The installed binary must
report the expected version. A pre-existing working `7zz` in the cache is reused
without a download.

- Force a specific binary (offline runs, tests):
  `LANOMAT_UT2004_7ZZ=/path/to/7zz`
- Change the cache directory: `LANOMAT_UT2004_TOOLS_DIR=/path/to/tools`
- Download only: `extract-archive.sh ensure-7zz` (prints the path and version)

If both downloads fail, a system `7zz` in `PATH` is used with a warning. If none is
available, the script exits 3.

### Commands

```text
list <archive> [--only <glob>]
tree <archive> [--depth N] [--only <glob>]
extract <archive> <dest> [--strip-wrapper] [--only <glob>]
ensure-7zz
--help
```

Examples (quote names with spaces, `+`, `!`, or `^^`):

```bash
# Inspect a repack
scripts/ops/ut2004/extract-archive.sh list "ut2k4+keygen.zip"
scripts/ops/ut2004/extract-archive.sh tree "ut2k4+keygen.zip" --depth 2

# ZIP -> SFX (two steps; the ZIP only wraps the SFX and keygen)
scripts/ops/ut2004/extract-archive.sh extract "ut2k4+keygen.zip" /tmp/ut2k4-sfx \
  --only '*nosTEAM.exe' --strip-wrapper
scripts/ops/ut2004/extract-archive.sh extract \
  "/tmp/ut2k4-sfx/Unreal Tournament 2004 nosTEAM.exe" /tmp/ut2k4 --strip-wrapper

# WinRAR SFX directly
scripts/ops/ut2004/extract-archive.sh extract \
  "Unreal Tournament 2004 nosTEAM.exe" /tmp/ut2k4 --strip-wrapper

# macOS patch DMG (no hdiutil, no mount)
scripts/ops/ut2004/extract-archive.sh extract \
  OldUnreal-UT2004Patch3374-macOS.dmg /tmp/ut2004-mac --strip-wrapper

# Linux patch tarball; it has several top-level directories, so stripping is a no-op
scripts/ops/ut2004/extract-archive.sh extract \
  OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2 /tmp/ut2004-linux --strip-wrapper

# Retail ISO or the repo's acceptance fixture
scripts/ops/ut2004/extract-archive.sh extract scripts/ops/fixtures/ut2004-fixture.iso /tmp/ut2k4-iso
```

`list` writes paths to stdout and progress/summary to stderr, so
`... list archive | grep Maps` works. `tree` prints two spaces per path component;
`--depth 3` is the default, `0` means unlimited.

### Formats

| Input | Opened with | Notes |
| --- | --- | --- |
| ZIP | `bsdtar`, then `unzip`, then `7zz` | `--only` is delegated to the chosen tool. |
| tar.bz2 / tar.gz / tar.xz | `bsdtar`, then `tar`, then `7zz` | Handles archives whose first entry is `./`. |
| 7z / RAR | `7zz` | Multi-volume RAR: pass the first volume; 7zz follows the set. |
| WinRAR SFX (`*.exe`) | `7zz` | PE stub + RAR stream; see below. |
| Apple DMG | `7zz` | 7zz reads the Dmg container and its HFS payload directly. |
| ISO 9660 | `bsdtar`, then `7zz` | Joliet/Rock Ridge names come from libarchive. |
| Unknown | `bsdtar`, then `7zz` | Last resort. |

Encrypted archives are attempted with an empty/dummy password (`7zz -p`,
`bsdtar --passphrase lanomat`, `unzip -P lanomat`) so they fail immediately
instead of prompting and hanging a script.

### WinRAR SFX detection and the nosTEAM shape

A WinRAR SFX is a normal Windows PE executable (`MZ` header) with a real RAR stream
appended. The script:

1. reads the first 8 MiB and looks for the ASCII RAR signature `Rar!`;
2. labels the file `WinRAR SFX (PE stub + Rar!)` and opens it with `7zz l` / `7zz x`;
3. if the PE has no archive signature, still tries `7zz` (some SFX use another
   loader), and otherwise exits 4 with a clear "installer, no Rar!/7z/zip payload"
   message.

`7zz` reports the embedded stream as `Type = Rar5` (or `Rar`) plus an `Offset`
inside the `.exe`, which is why `7zz` can ignore the stub. The real
`Unreal Tournament 2004 nosTEAM.exe` from `ut2k4+keygen.zip` is detected this way:
its RAR signature starts around byte 202752.

`ut2k4+keygen.zip` itself is **not** a game tree. It contains:

```text
Unreal Tournament 2004 PC multiplayer + SP ^^nosTEAM^^/
  Unreal Tournament 2004 nosTEAM.exe      # 2.5 GiB WinRAR SFX
  UNREAL.TOURNAMENT.2004.KEYGEN-DEViANCE/
  README FIRST !!!.txt
  visit-nosteam.ro.html
```

Extracting the ZIP naively leaves the SFX next to the keygen. The script detects
that shape and exits 5 with the exact next command (extract the SFX). The two-step
commands above work; the SFX payload contains `Unreal Tournament 2004/` plus two
loose files (`update-UT2004.bat`, `visit-nosteam.ro.html`), which `--strip-wrapper`
handles.

A repack that only contains `setup.exe` (Inno/NSIS) and no embedded archive is not
runnable without Wine. The script says so instead of pretending it extracted a game.
If the ZIP contains `setup.exe` plus one nested `.iso`, the error names the ISO and
prints the command that extracts it.

### DMG extraction

`7zz` understands the Apple DMG container and its HFS+ payload. The script does not
need `hdiutil`, `ditto`, a mount point, or root:

```bash
scripts/ops/ut2004/extract-archive.sh tree OldUnreal-UT2004Patch3374-macOS.dmg --depth 4
scripts/ops/ut2004/extract-archive.sh extract OldUnreal-UT2004Patch3374-macOS.dmg \
  /tmp/ut2004-mac --strip-wrapper
```

The patch DMG mounts as a volume directory named
`OldUnreal-UT2004Patch-3374/`, containing `UT2004.app`, `MacREADME.txt`, and
`LICENSE.md`. The app bundle is a **patch**, not the full game: per its own
`MacREADME.txt`, the game assets (`Animations`, `Maps`, `Textures`, `Sounds`, …)
must come from another source. That is why the sanity check does not require
`Maps/DM-Rankin.ut2` for this DMG.

`--strip-wrapper` moves the payload out of the volume folder. It also removes the
HFS bookkeeping entries `[HFS+ Private Data]` and `.HFS+ Private Directory Data`
(7zz may spell the latter with a trailing CR), `.DS_Store`, `.VolumeIcon.icns`,
`.background`, and the volume's self-referential `Applications` symlink.

### `--strip-wrapper`

Many sources put the game in one directory. After extraction:

- exactly one top-level directory (plus any loose files) is moved up one level;
- loose files stay at the destination root;
- a destination with multiple top-level directories is left untouched with a
  warning (the Linux patch is this shape: `System/`, `Textures/`, `Sounds/`, …);
- a directory that is already a payload root (`Maps`, `Textures`, `System`,
  `UT2004.app`, …) is not stripped again;
- `--strip-wrapper` requires an empty destination so a merge cannot clobber
  unrelated files.

### `--only`

`--only` is repeatable and uses archive-relative patterns:

```bash
--only 'UT2004/Maps/*' --only 'UT2004/Textures/*.utx'
--only '*/UT2004.app/Contents/MacOS/*'
```

For `extract`, the glob is passed to `bsdtar`, `unzip`, or `7zz`; for `list`/`tree`
it is matched by the script. Quote globs so the shell does not expand them. With
`--only`, a missing sanity marker is a warning, not an error, because a partial
tree is expected.

### Sanity checks

After extraction (and after wrapper stripping) the script counts files and checks
for:

- `Maps/DM-Rankin.ut2` (retail/full install)
- any `*.ut2` map
- any `*.utx` texture
- `System/Build.ini`
- `UT2004.app/Contents/MacOS/UT2004`
- `System/UT2004` (OldUnreal Linux/Unix patch)
- `ut2004-bin*` (legacy Linux binary)
- `UT2004.exe` (Windows install)

At least one marker must be found. If there is a Windows `.exe` and no game data,
the script explains that it is an installer/SFX wrapper. A nested ISO is named with
a ready-to-run extraction command.

### Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Extraction/listing succeeded (warnings possible). |
| 1 | Extraction or listing failed (truncated, encrypted, bad glob, tool error). |
| 2 | Usage error, missing archive, invalid `--depth`, non-empty destination with `--strip-wrapper`. |
| 3 | 7-Zip download/SHA/version check failed. |
| 4 | Unsupported format, or a PE installer with no embedded archive. |
| 5 | Extraction worked but no UT2004 data was found. |

### Limits

- No Wine. `setup.exe`, Inno Setup, and NSIS installers are not executed. Only
  payloads that are real archives (RAR SFX, ZIP/7z SFX) can be opened.
- No root and no mounting: DMG/ISO are read as files by `7zz`/`bsdtar`.
- No recursive auto-descent. A ZIP containing a nested ISO or SFX produces a clear
  error and the next command, rather than guessing where the game is.
- Encrypted DMGs/archives that need a password fail; there is no password option.
- `--strip-wrapper` will not merge multiple top-level directories.
- `tree` on a DMG lists the HFS payload directly, but very large images still take
  as long as 7zz needs to read the metadata.
- Extraction is not atomic: a failed extraction can leave partial files in `<dest>`.

### Typical errors

| Symptom | Cause / fix |
| --- | --- |
| `error: could not download or verify 7zz` | No network or blocked GitHub/7-zip.org. Set `LANOMAT_UT2004_7ZZ` to a working binary. |
| `SHA-256 mismatch` | Truncated download or changed asset. Retry; the script tries 24.09 next. |
| `error: only a Windows installer was extracted` | Inno/NSIS `setup.exe`, no embedded archive. Use the retail ISO or a real SFX/repack. |
| `error: the archive is a wrapper ... WinRAR SFX` | Extract the named `.exe` next (the message prints the command). |
| `error: ... installer and a nested ISO image` | Extract the named `.iso` next (the message prints the command). |
| `error: no UT2004 markers found` | Wrong/truncated archive, or an installer. Run `list` and inspect the top level. |
| `--strip-wrapper requires an empty destination` | Use a fresh `<dest>` or omit `--strip-wrapper`. |
| `ERROR: Wrong password` | Encrypted archive; the script tries an empty password. |
| Extraction fills `/tmp` | Choose a `<dest>` on a large filesystem. The 7zz download already uses the tools dir. |

### Verified inputs

Tested on this checkout with:

- `ut2004-build/patches/OldUnreal-UT2004Patch3374-macOS.dmg` — `list`, `tree`,
  full `extract --strip-wrapper`, and `--only` for the app binary; 1526 files,
  `UT2004.app/Contents/MacOS/UT2004` present.
- `ut2004-build/patches/OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2` —
  full extraction, `System/Build.ini`, `System/UT2004`, `Textures/*.utx`.
- `ut2k4+keygen.zip` — `list`, `tree`, full ZIP extraction (SFX hint), and the
  inner `Unreal Tournament 2004 nosTEAM.exe` SFX extraction: 1811 files, 6.3 GiB,
  `Maps/DM-Rankin.ut2`, `System/Build.ini`, `System/UT2004.exe`.
- `scripts/ops/fixtures/ut2004-fixture.iso` and
  `ut2004-patch-fixture.tar.bz2` — list/extract, including the leading-`./` tar.
- A small ZIP fixture whose paths contain spaces and `+`.
- A real PE WinRAR SFX (built with WinRAR's `Default.SFX`), an installer-only ZIP,
  and an installer+ISO ZIP (both exit 5 with the expected hint).
- 7-Zip 25.01 (primary SHA) and 24.09 (fallback SHA/version) verified.
- `bash -n` and `shellcheck 0.11.0 -S style` clean.

## fetch-base.sh — UT2004-Basisarchiv von archive.org

**Zweck.** `fetch-base.sh` lädt `unreal-tournament-2004_202405 /
Unreal Tournament 2004.zip` (2.550.381.122 B) als N parallele Byte-Ranges mit
Resume. Ein einzelnes `curl -C -` bricht bei archive.org regelmäßig nach
45–120 MB mit SSL EOF oder Stall ab; jede Range ist hier eine eigene
append-only Part-Datei, sodass ein Abbruch nur die Bytes im Flug kostet.
Das Skript braucht nur `bash`, `curl`, `python3`, `sha256sum`, `stat`, `flock`
und `df`; keine Secrets, kein `pkill`.

**Usage.** Defaults: Item `unreal-tournament-2004_202405`, Datei
`Unreal Tournament 2004.zip`, Ziel `~/Downloads/Unreal Tournament 2004.zip`,
Parts `<ziel>.parts`, 4 Ranges, 30 min pro Versuch, Stall-Abbruch nach 60 s
unter 10 KiB/s, 12 Versuche pro Range. Alle Flags stehen im Skriptkopf und in
`--help`; die wichtigsten:

```bash
# nur Metadaten, Mirrors, Wiederverwendung und Ranges zeigen (schreibt nichts)
scripts/ops/ut2004/fetch-base.sh --dry-run

# normaler Download
scripts/ops/ut2004/fetch-base.sh

# Größe und SHA-256 erzwingen
scripts/ops/ut2004/fetch-base.sh --expected-size 2550381122 --sha256 "$SHA256"

# anderes Item/Ziel, mehr Verbindungen
scripts/ops/ut2004/fetch-base.sh --parallel 6 \
    --item unreal-tournament-2004_202405 \
    --file 'Unreal Tournament 2004.zip' --output /data/ut2004.zip
```

**Mirror-Fallback.** Die Metadaten-API liefert `size`, optional `sha256` sowie
`server`, `d1`, `d2` und `dir`. Die Reihenfolge ist `d2`, `server`, `d1`, dann
`https://archive.org/download/<item>/<datei>` als redirectender Fallback. Jeder
Mirror wird per HEAD geprüft und muss exakt die erwartete Größe melden; defekte
Mirrors werden übersprungen. Pro Versuch rotiert der Downloader zum nächsten
Mirror. `--no-probe` überspringt die Prüfung, `--mirror URL` (wiederholbar)
setzt eigene Mirror-URLs (z. B. für Tests).

**Resume-Verhalten.** Eine vorhandene Zieldatei gilt als gültiger Byte-Prefix:
geplant wird exakt `[Prefix, size)`. Ein Manifest
`<parts-dir>/manifest.json` pinnt Item/Datei/Größe/Prefix/Range-Grenzen über
Läufe hinweg; ohne Manifest werden die Grenzen aus `part-XX-<start>.bin`
rekonstruiert. Vorhandene Parts werden an ihrer aktuellen Größe fortgesetzt,
vollständige Parts nie erneut geladen. Die Prüfung ist streng: erste Range am
Prefix, Ranges lückenlos, letzte Range bei `size-1`; sonst Exit 2 statt Raten.
Handgestartete `p1..p4` werden über `--adopt 'name=start-end,...'` oder – solange
die Curls noch laufen – über `--adopt-running` (liest `-r`/`-o` aus `/proc`)
übernommen. Schreibt noch ein Prozess in die Dateien, verweigert ein echter Lauf
mit Exit 7; `--take-over` beendet nur die PIDs, die genau diese Dateien halten.
Bei Abbruch bleiben Parts und Manifest erhalten; temporäre Tail-/Header-Dateien
liegen unter `$TMPDIR` und werden beim nächsten Lauf nicht wiederverwendet.

**Grenzen.** Das Prefix-Modell setzt voraus, dass kein anderer Prozess das Ziel
während des Laufs schreibt (Writer-Erkennung über `/proc/*/fd`, nur
Schreibmodus). Die Montage schreibt `<ziel>.tmp.<pid>` und benennt atomar um;
dafür muss während der Montage ungefähr die volle Dateigröße frei sein. Für
dieses Item veröffentlicht archive.org nur md5, keinen sha256 – die
Standard-Endprüfung ist daher die exakte Größe; `--sha256` prüft zusätzlich,
wenn ein Digest vorliegt. `--adopt-running` braucht noch laufende Curls oder
deren Wrapper; danach hilft nur ein explizites `--adopt`-Snapshot. Mid-Stream
wird der Mirror nicht gewechselt, nur zwischen Versuchen. Maximal 16 parallele
Ranges.

**Aktueller Blocker.** archive.org stallt oder resettet lange
Single-Stream-Transfers weiterhin bei ca. 45–120 MB. Die parallelen Ranges
umgehen das, aber ein einzelner Mirror kann für einen ganzen Versuch langsam
oder gedrosselt sein; `--max-time` und `--speed-limit` machen daraus einen
Resume statt eines Hängers. Ein voller 2,55-GB-Download wurde vom Tool selbst
nicht gestartet (nur HEAD, ein 1-MiB-Range und lokale Range-Server-Tests).

**Beispiel für den vorhandenen Hand-Download.** Zeitweise lagen
`~/Downloads/Unreal Tournament 2004.zip` als 577.169.443-B-Prefix und
`/tmp/opencode/utparts/{p1,p1rest,p2,p3,p3rest,p4}` als passende, teils
unvollständige Ranges. Mit dem damaligen Snapshot meldete

```bash
scripts/ops/ut2004/fetch-base.sh --dry-run \
  --parts-dir /tmp/opencode/utparts \
  --adopt 'p1=577169443-1034164258,p1rest=1034164259-1070000000,p2=1070000001-1570000000,p3=1570000001-1990425728,p3rest=1990425729-2070000000,p4=2070000001-2550381121'
```

2.550.381.122 / 2.550.381.122 B (100 %) und hätte nur noch montiert. Der
Hand-Download ist inzwischen abgeschlossen: Das Ziel ist exakt 2.550.381.122 B
groß, md5 `55bd1e0f512cde5f7ce7fbf70dabcfaa` (identisch mit den Metadaten), und
`/tmp/opencode/utparts` existiert nicht mehr. `fetch-base.sh` erkennt das als
`layout: complete output`, prüft die Größe und beendet sich mit Exit 0, ohne
etwas zu schreiben.

**Exit-Codes.** `0` vollständig; `2` Usage/Layout; `3` Download nach allen
Retries fehlgeschlagen (Parts bleiben); `4` Größenfehler (Parts bleiben);
`5` SHA-256-Fehler (Datei und Parts bleiben); `6` Metadaten/Mirror nicht
erreichbar; `7` fremder Writer oder Lock; `130/143` unterbrochen (Parts
bleiben).

**Getestet.** `bash -n`; `--help`; `--dry-run` gegen das echte Item inkl.
dreier Mirrors; HEAD plus 1-MiB-Range gegen `ia800800` und
`archive.org/download` (HTTP 206, identische Bytes); lokaler Range-Server mit
Volldownload + SHA-256, Prefix + teilgefüllten Legacy-Parts via `--adopt`,
wiederholten Verbindungsabbrüchen, SIGINT (Exit 130, Parts bleiben, nächster
Lauf vollendet), `--adopt-running` (Exit 7 ohne, erfolgreich mit
`--take-over`) und falschem SHA-256 (Exit 5). `shellcheck` ist in dieser
Umgebung nicht installiert.

---

## Native install smoke test — `install-smoke.sh`

`install-smoke.sh` exercises the whole native UT2004 install path — plan, base
download, extraction, OldUnreal 3374 patch, verification — against a **local
full-game archive**, without the real LAN file host (`<LAN_FILEHOST_IP>`).

The live recipe `20` (`unreal-tournament-2004`) normally names a file on the
LAN host. For the duration of a run the script points that recipe at a
throwaway HTTP server on `127.0.0.1`, lets the real launcher engine install,
checks the result, then restores the previous recipe URL and stops the server
it started. This proves the launcher and the recipe, not the NAS/file-host
mount, and works from an off-site machine.

```
scripts/ops/ut2004/install-smoke.sh --dry-run
scripts/ops/ut2004/install-smoke.sh
```

`--dry-run` changes nothing. It reads the archive, detects an in-flight
download, reads the live plan and the admin form, and prints `READY` (exit 0)
or `NOT READY` (exit 2) with the reason.

### Why bypass the file host?

The real recipe resolves its base file through the LAN file host. A smoke test
must not depend on that host being reachable, mounted or fast, and a failed
mount would look like a launcher bug. Serving the same bytes over loopback
keeps every other part of the path real: the server builds the plan, the
launcher downloads with its normal engine, extracts the same wrapper layout,
applies the same patch and runs the same verification. Only the transport
(`http://127.0.0.1:8099/...` instead of the LAN host) differs.

### What a run does

1. **Readiness probe.** Opens the zip central directory and checks for real
   game data (`Maps/DM-Rankin.ut2`, at any wrapper depth) or exactly one nested
   ISO. A truncated archive, a `.part`, a running `curl`/`wget`, or a
   multi-range download directory (`/tmp/opencode/utparts` plus the partial
   prefix) makes it stop with exit 2. It never starts or resumes the download.
2. **Local HTTP server.** Creates a temp dir with a symlink to the archive and
   runs `python3 -m http.server 8099 --bind 127.0.0.1 --directory <temp>`.
   Only the archive is exposed. If a server already answers the same URL with
   the same `Content-Length`, it is reused and left running; if the port is
   taken by something else, the run stops (use `--port`).
3. **Recipe switch.** Uses `scripts/ops/ut2004/live-recipe.mjs` when present
   (the shared editor: `show --json` / `set --iso-url ... --apply --yes`),
   otherwise an inline Playwright + Livewire reader. It captures the current
   requirement URL, sets it to the local URL, and waits until
   `/api/v1/recipes/unreal-tournament-2004/plan` returns that URL. The original
   value is written to `recipe-backup.json` before the change; the shared
   editor also leaves its own full-form backup in the temp dir.
4. **Install.** Runs the installed deep link (`lanomat://install/...`) or the
   `launcher-core` `install_local` example (default) with the launcher token
   from `~/.config/lanomat-launcher/config.json`. Progress is streamed and
   logged. The default timeout is 30 minutes.
5. **Verify.** Requires `System/Build.ini`, `System/ut2004-bin-amd64` (or
   `System/UT2004`), and `Maps/DM-Rankin.ut2` at the target root. A base that
   stayed inside its wrapper folder is reported explicitly, because the patch
   alone can make `System/` look complete. `--launch` starts the game for 15
   seconds afterwards (best effort).
6. **Cleanup (trap).** Restores the exact previous recipe URL (unless
   `--no-restore`), stops only the HTTP server this run started (no `pkill`,
   no pattern matches), removes the browser profile and the token header file,
   and deletes the temp dir. A failed real run keeps the temp dir with the
   install log; a failed restore keeps the backup and exits 1. `--keep` keeps
   the logs of a successful run too.

### Options

| Option | Meaning |
|---|---|
| `--base <zip>` | full-game base archive to serve (default: `~/Downloads/Unreal Tournament 2004.zip`) |
| `--target <dir>` | install target (default: `~/Games/unreal-tournament-2004`) |
| `--url <url>` | recipe URL for the run (default: `http://127.0.0.1:8099/<archive name>`) |
| `--timeout <dur>` | install timeout in seconds, `30m` or `2h` (default: `30m`) |
| `--dry-run` | check everything, change nothing |
| `--keep` | keep temp dir/logs and the recipe backup (secrets are still removed) |
| `--no-restore` | leave the live recipe on the local URL; prints the backup |
| `--engine <mode>` | `auto` (default), `example`, `deeplink` |
| `--recipe-id <id>` | live recipe row (default: `20`) |
| `--port <port>` | local HTTP port (default: `8099`) |
| `--launch` | start the game for 15 seconds after a verified install |

### Environment

| Variable | Use |
|---|---|
| `LANOMAT_ADMIN_COOKIES` | raw `Cookie` header for the admin session |
| `LANOMAT_ADMIN_COOKIES_FILE` | file with a `Cookie` header, Netscape cookies or a Playwright `storageState` JSON (default: `~/.config/lanomat-launcher/admin-cookies.txt`; if that is missing, `/tmp/opencode/cookies.txt` is used when present) |
| `LANOMAT_PLAYWRIGHT_ENTRY` | path to `playwright/index.mjs` (default: repo `node_modules`) |
| `LANOMAT_CHROMIUM` | explicit Chromium/headless-shell binary |
| `LANOMAT_INSTALL_EXAMPLE` | explicit `install_local` example binary |
| `LANOMAT_LAUNCHER_BIN` | launcher binary for `--engine deeplink` |
| `LANOMAT_ALLOW_STALE_ENGINE=1` | accept an example binary older than the sources |
| `LANOMAT_UT2004_PARTS` | parts directory for readiness detection (default: sibling `utparts/`, plus `/tmp/opencode/utparts` for this archive) |
| `LANOMAT_UT2004_BASE`, `LANOMAT_UT2004_PORT`, `LANOMAT_RECIPE_ID` | alternate defaults for `--base`, `--port`, `--recipe-id` |

The admin session is separate from the launcher token. The token is read only
from `~/.config/lanomat-launcher/config.json`, sent through a mode-600 header
file (never argv), and never printed. Admin cookies are passed through the
environment to the headless browser and its profile is deleted afterwards.

### Expected size and duration

| Item | Size |
|---|---|
| `Unreal Tournament 2004.zip` | 2,550,381,122 bytes (2.37 GiB, 1,587 entries) |
| OldUnreal 3374 Linux patch | 59,191,321 bytes |
| installed game | ~5.4 GiB |

Observed on this host (NVMe, loopback, current example build): **2 min 53 s**
from recipe switch to restore. Budget 10–15 minutes on a slower disk and keep
the default 30-minute timeout. Peak disk is roughly **10–11 GiB**: the archive
itself, the launcher's copy in the plan's downloads dir, and the extracted
tree. `--target` moves the install tree, but the engine still uses the plan's
`downloads_dir` (`~/Games/unreal-tournament-2004/.lanomat-downloads`), so a
custom target does not isolate the downloaded copy.

The patch is still downloaded from the URL in the recipe (GitHub, 59 MB), so
the machine needs outbound HTTPS for that one file. The base archive comes
from loopback.

### Download readiness

The script detects the multi-range download used for this archive:
`/tmp/opencode/utparts` (or `$LANOMAT_UT2004_PARTS`, or a sibling `utparts/`)
plus the partial prefix. `--dry-run` reports the prefix size, part count, the
known range end if available, and why the archive is not usable yet:

```
==> NOT READY
    the archive is incomplete or still being written; nothing was changed
    when every part is complete, append them in name order:
      cat /tmp/opencode/utparts/* >> "~/Downloads/Unreal Tournament 2004.zip"
    the test is ready when the zip opens and carries Maps/DM-Rankin.ut2 or one nested ISO
```

Once the zip opens and contains game data, dry-run turns `READY` even if old
part files are still lying around. When the test became ready here, the full
2.55 GB run above was executed and passed.

### Concurrency and cleanup

Recipe `20` is live and shared. Two runs at the same time will fight over its
URL, and two installs share the plan's downloads dir. Run it serially.
The backup file is `recipe-backup.json` in the temp dir; with `--no-restore`
the temp dir is kept and the path is printed. To restore by hand, set the
requirement URL in `/admin/recipes/20/edit` back to the `url` value in that
file. Do not simply re-run the tool: a second run would capture the local URL
as its own backup and restore that.

The script never uses `pkill`/`pgrep` patterns. It stops exactly the PID it
started and leaves a pre-existing server on `8099` untouched.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | install verified, or dry-run ready |
| 1 | install, verify or recipe restore failed |
| 2 | usage error or missing precondition (including incomplete base archive, stale engine, admin session, port) |

### Troubleshooting

- **Wrapper folder left behind** (`<target>/Unreal Tournament 2004/Maps/...`):
  the `install_local` example is older than `launcher-core`. Rebuild it:
  `docker run --rm -v "$PWD/clients/launcher:/work" -w /work -e CARGO_TARGET_DIR=/work/target lanomat-launcher-dev cargo build -p launcher-core --example install_local`.
  The script refuses a stale example unless `LANOMAT_ALLOW_STALE_ENGINE=1`.
- **`port 8099 is in use`**: another server is there. If it serves the same
  file size the script reuses it; otherwise pass `--port 8098`.
- **`admin session expired`**: refresh the cookies, e.g.
  `LANOMAT_ADMIN_COOKIES_FILE=/path/to/cookies.txt`.
- **Recipe changed during the run**: the final plan check fails and the trap
  restores the value captured at the start. Do not run two smoke tests at once.
- **`--dry-run` says the download is incomplete**: leave the base archive
  alone, finish/merge the parts, then rerun. Nothing was changed.

## upload-file.sh — resumable tus upload to the live file host

Uploads a large file (the 2.55 GB `ut2004-base.zip` is the pilot case) to the
event file host through the resumable tus endpoint. The bytes go **directly**
to `https://files.lan.raute1.org:8443/upload/`; `lan.raute1.org` only issues the
ticket and hook. The laptop must be on the venue WLAN `LANoMAT`
(`<LAN_SUBNET>`), not on `qc` (`<OFFICE_SUBNET>`).

```bash
# admin session cookie in Header format, e.g. /tmp/opencode/cookies.txt
scripts/ops/ut2004/upload-file.sh \
  '/home/reneil1337/Downloads/ut2004-base.zip' ut2004-base.zip 64
```

- Ticket: `POST /events/raute1-lan-26/files/uploads` (admin => the finished
  SharedFile is immediately `approved`).
- `POST` creates the tus upload, then 64 MiB `PATCH` chunks.
- State (tus URL) lives in `~/.cache/opencode-upload-test/state-*.env`; rerun
  the same command after a broken connection and it resumes from `HEAD`.
- Measured: ~1.2–1.9 MB/s on the venue WLAN; 2.55 GB ≈ 22–35 min.
- `--` no automatic network switch; keep `LANoMAT` connected for the whole run.
- Cleanup: the script leaves the tus state until complete; delete the state
  file after verifying the SharedFile in `/admin`.

## Live-Rezept 20: Filament, CSRF und der 04:15-Sync

`live-recipe.mjs` bearbeitet `unreal-tournament-2004` (Filament-ID 20) über die
echte Admin-Seite und Livewire (`get`/`set`/`call('save')`), nicht über
`POST /api/v1/recipes`.

**Warum Filament statt `ContributeRecipe`.** `ContributeRecipe` hat kein
`metadata`-Feld in der Controller-Validierung; ein Aufruf ohne `metadata`
setzt `{"contributed_via":"mcp"}` und verliert `metadata.launch_targets`
(Flat/VR), Tags, Genre, `players`, `live_url` und `lan_recommended`; außerdem
werden Version, Homepage, Lizenz und die Datei-Zeilen/Pins neu aufgebaut. Das
Filament-Formular speichert nur seine registrierten Felder — `metadata` bleibt
unangetastet. Der Form-Save wendet zusätzlich `ReviewStamp` und den
Requirement-Pin-Sync an, sodass eine veröffentlichte Version `published` und
den ursprünglichen Reviewer behält.

**Session und CSRF.** Kein Bearer für den Admin-Teil: `--cookie-file`
(Default `/tmp/opencode/cookies.txt`, alternativ
`LANOMAT_ADMIN_COOKIES_FILE`) liest einen `Cookie:`-Header, Netscape-Cookies
oder einen Playwright-JSON-Export. Playwright setzt daraus den
Browser-Kontext; das Laravel-`session`-Cookie authentifiziert, das
`XSRF-TOKEN`-Cookie lässt Livewire automatisch `X-XSRF-TOKEN` an
`/livewire/update` senden. Cookies und Token werden nie ausgegeben. Nur
`plan` liest den Bearer aus `~/.config/lanomat-launcher/config.json` und sendet
standardmäßig `dry_run: true` (kein Install-Audit-Eintrag).

**04:15 überschreibt Panel-Änderungen.** `routes/console.php` startet
`lanomat:recipes:sync` täglich um 04:15. Das Rezept hat `source: bundled`, die
Live-Zeile wird also aus
`resources/recipes/unreal-tournament-2004/recipe.yaml` upserted. Änderungen
hier überleben nur bis zum nächsten Sync; danach gewinnt die YAML. Für eine
dauerhafte Änderung muss die YAML mitgeändert werden.

**Use-Cases.** Akute Korrektur für den Launcher, Prüfen des Panel-Stands vor
dem Sync, Diff/Backup/Rollback um einen Sync oder eine manuelle Änderung,
gezieltes Setzen von ISO-URL/-Match, Plattformen oder Patch-URL/SHA. `set`
schreibt nur mit `--apply --yes`; danach wird per Reload geprüft, dass
`status` und `metadata` unverändert sind und jedes geänderte Feld
zurückgelesen wurde.

**Restore-Grenze.** `restore` spielt die Formularfelder aus einer
JSON-Sicherung zurück. `metadata` kann weder das Panel (kein Feld) noch
`POST /api/v1/recipes` (Controller verwirft den Key) schreiben; deshalb bleibt
das Live-Metadata erhalten. Weicht eine Sicherung darin ab, meldet das Tool
`metadata_changed: true`, `metadata_restored: false`. Nur der 04:15-YAML-Sync
kann Metadata wiederherstellen. Beispiele und alle Flags:
`node scripts/ops/ut2004/live-recipe.mjs --help`.
