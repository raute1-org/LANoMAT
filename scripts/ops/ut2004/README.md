# UT2004 ops

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
scripts/ops/ut2004/fetch-base.sh --expected-size 2550381122 --sha256 <64-hex>

# anderes Item/Ziel, mehr Verbindungen
scripts/ops/ut2004/fetch-base.sh --parallel 6 \
    --item <item> --file '<datei>' --output /data/<datei>
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
