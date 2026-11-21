# UT2004-One-Click-Pilot — Betriebs-Runbook

Stand: **2026-11-21**. Gilt für den Pilot **Unreal Tournament 2004, flat**, auf
Windows, Linux und macOS. Ein Klick heißt: Launcher paired einmal, danach
installiert und startet er das Spiel ohne Terminal, ohne Steam, ohne
DVD-Mount und ohne Wine.

Zentrale Regel: **YAML im Repo ist die Quelle, das Panel ist nur Editor.**
Alles, was im Live-Panel geändert wird und nicht im YAML landet, kann der
nächtliche Sync um 04:15 überschreiben. Deshalb steht in diesem Runbook bei
jedem Live-Eingriff ein Dump davor und ein Plan-Check danach.

## 1. Ziel und Scope

| Ziel | Umsetzung |
| --- | --- |
| Ein Spiel, ein Modus | `unreal-tournament-2004`, flat; VR ist nicht Teil des Piloten |
| Ein Klick | Website `/games` → **Installieren**; ohne Launcher wird **Launcher herunterladen** daraus |
| Drei Betriebssysteme | Windows x64, Linux x64, macOS (Intel + Apple Silicon) |
| Bevorzugte Quelle | portables 3374-Archiv für das Ziel-OS, bereits gepatcht |
| Fallback | UT2004-DVD-ISO bzw. Full-Game-ZIP + OldUnreal-Patch 3374 für das OS |
| Kein Wine | Der Patch liefert den kompletten `System/`-Baum; der Retail-`System/` wird nie übernommen |
| Nutzerdaten | Spieldateien sind user-supplied; im Repo liegt kein Spiel |

**Bevorzugt:** `ut2004-portable-3374-windows.zip`,
`ut2004-portable-3374-linux.zip` oder `ut2004-portable-3374-macos.zip`.
Ein passendes `base`-Requirement gewinnt im Launcher gegen die ISO; der
Installer entpackt es flach (eine Wrapper-Ordner-Ebene wird entfernt) und
überspringt Disc-Kopie und Patch-Schritt.

**Fallback:** `ut2004*.iso` (Disc 1 reicht; Disc 2 ist nur Bonusmaterial) oder
das flache Full-Game-ZIP von archive.org. In beiden Fällen ist der
OS-spezifische OldUnreal-Patch 3374 Pflicht. Auf macOS liefert der Patch
`UT2004.app`; die Assets liegen in `~/Library/Application Support/UT2004`.

**Nicht portabel:** `ut2k4+keygen.zip` ist der nosTEAM-**Installer** (Setup.exe
plus ISO), kein fertiger Spielbaum. Das Rezept matcht ihn absichtlich nicht.
Der Launcher erkennt zwar ein einzelnes ISO in einem Installer-ZIP, aber für
den Ein-Klick-Pfad ist das kein `base`-Archiv.

**Nicht im Pilot:** andere Rezepte, VR/UT2004VR, Venice-Sponsoring, Avatare,
der macOS-Launcher im Manifest (sofern nicht anders vermerkt).

## 2. Live-Stand

Aufgenommen am **2026-11-21** gegen `https://lan.raute1.org`; Rohwerte ohne
Secrets. Der Live-Stand kann durch parallele Smoke-Tests kippen — vor jedem
Eingriff `live-recipe.mjs show` laufen lassen.

| Komponente | Wert |
| --- | --- |
| App | `https://lan.raute1.org`, Server-Header `m12-768-ge8de9dad` (kurz: m12-768) |
| Rezept | `unreal-tournament-2004`, **Filament-ID 20**, Status `published`, Version `3374-rc3` |
| Plattformen | `windows`, `linux`, `macos` (List-API, `updated_at` 2026-11-21T14:27:31Z) |
| ISO-Rolle | `role: iso`, required, **extern** auf das archive.org-Full-Game-ZIP; kein LAN-Treffer |
| Patch live | **eine** Zeile, Label „Windows x64 zip / Linux x64 tar.bz2“, URL/SHA256 des **Linux**-Assets; in der Datei-/Plan-API steht `os: null` |
| Base live | **keine** `base`-Zeile; die portablen Archive sind noch nicht veröffentlicht |
| Dateihost | `https://files.lan.raute1.org:8443`, DNS `10.23.0.12` |
| Rezept-Dateien live | IDs 170–174 (ändern sich bei Sync; nicht als Schlüssel verwenden) |

Die Live-ISO-Rolle zeigt auf:

```text
https://ia800800.us.archive.org/25/items/unreal-tournament-2004_202405/Unreal%20Tournament%202004.zip
```

Der Linux-Patch ist gepinnt auf:

| Feld | Wert |
| --- | --- |
| URL | `https://github.com/OldUnreal/UT2004Patches/releases/download/3374-rc3/OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2` |
| SHA-256 | `d79eb45fda3ace2b7a68e6a0b9730b7d6602004b111e0b16f3f7cc28f3111572` |
| Größe | 59.191.321 B |

**Wichtig — Smoke-Tests schalten die URL um:** `install-smoke.sh` zeigt die
ISO-Rolle für die Dauer eines Laufs auf `http://127.0.0.1:8099/...`; nach
einem Abbruch mit `--no-restore` bleibt sie dort. Vor dem Go mit
`live-recipe.mjs show` prüfen und auf archive.org oder den lokalen
Base-Server zurücksetzen (Abschnitt 5.8).

### 2.1 Live lesen (ohne Token im Terminal)

Der Bearer-Token liegt nur in
`~/.config/lanomat-launcher/config.json` (Modus `0600`). Nie `cat`en.

```bash
TOKEN="$(python3 -c 'import json,os;print(json.load(open(os.path.expanduser("~/.config/lanomat-launcher/config.json")))["token"])')"

curl -fsS -H "Authorization: Bearer $TOKEN" \
  "https://lan.raute1.org/api/v1/recipes/unreal-tournament-2004" \
  | jq '{slug:.recipe.slug, version:.recipe.version, platforms:.recipe.platforms,
         files:[.recipe.files[]|{role,label,os,url,sha256}]}'

unset TOKEN
```

Plan für ein OS (rein lesend, `dry_run` schreibt keinen Audit-Eintrag):

```bash
TOKEN="$(python3 -c 'import json,os;print(json.load(open(os.path.expanduser("~/.config/lanomat-launcher/config.json")))["token"])')"

for os in windows linux macos; do
  echo "=== $os"
  curl -fsS -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "{\"os\":\"$os\",\"dry_run\":true}" \
    "https://lan.raute1.org/api/v1/recipes/unreal-tournament-2004/plan" \
    | jq '{os, target_dir, missing,
           files:[.files[]|{requirement,label,status,source}]}'
done

unset TOKEN
```

Erwartung im **bereinigten** Live-Stand: drei Pläne, jeweils ein passender
Patch; `missing` enthält höchstens die externen Downloads, keine falsche
Plattform. Im **heutigen** m12-768-Stand bekommt auch `windows` und `macos`
die Linux-Patch-Zeile — das ist der Blocker aus Abschnitt 7.3.

## 3. Artefakte und Quellen

### 3.1 Full-Game-ZIP (archive.org)

| Feld | Wert |
| --- | --- |
| Item | `unreal-tournament-2004_202405` |
| Datei | `Unreal Tournament 2004.zip` |
| Größe | **2.550.381.122 B** |
| MD5 | `55bd1e0f512cde5f7ce7fbf70dabcfaa` |
| SHA-1 | `7dd8b9f5319c2d660a92c486bf1a8055e73c972d` |
| Dateien | 1.558 |
| Form | flacher, bereits installierter Spielbaum (`Maps/`, `Textures/`, `System/`, …), laut Rezept-Kommentar 3369 |
| Direkthost | `ia800800.us.archive.org`, Pfad `/25/items/unreal-tournament-2004_202405/` |
| SHA-256 | **nicht veröffentlicht**; archive.org liefert hier MD5/SHA-1, kein SHA-256 |

Die direkte `ia800800`-URL ist im Rezept gepinnt, weil
`https://archive.org/download/<item>/<file>` derzeit auf einen deutlich
langsameren Mirror umleitet. Das ZIP ist kein Ersatz für den Patch: Der OS-Patch 3374
wird auch auf diesen Baum angewendet.

### 3.2 OldUnreal-Patch 3374-rc3 (2026-11-03)

| OS | Asset | Größe | SHA-256 |
| --- | --- | ---: | --- |
| Windows | `OldUnreal-UT2004Patch3374-Windows-d906d39a.zip` | 47.460.147 B | `16bd65fcf736163e1f00fbb9404a492489bea6869d87496dcd15c2fed4d96dd2` |
| Linux | `OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2` | 59.191.321 B | `d79eb45fda3ace2b7a68e6a0b9730b7d6602004b111e0b16f3f7cc28f3111572` |
| macOS | `OldUnreal-UT2004Patch3374-macOS.dmg` | 61.235.839 B | `8c9bd030127c2fd37c2565aad59cb5f4dbb696909e01c1cb975aecf7e38b8e7c` |

Bezugsbasis:

```text
https://github.com/OldUnreal/UT2004Patches/releases/tag/3374-rc3
https://github.com/OldUnreal/UT2004Patches/releases/download/3374-rc3/
```

Das macOS-Asset ist das **Universal-2**-DMG: `UT2004.app` mit nativem
64-bit-Engine für Intel und Apple Silicon, dazu der gepatchte
`Contents/Resources/System`-Baum. Das DMG enthält keinen `Build.ini` im
Wurzelverzeichnis; der Portable-Build schreibt einen kleinen Marker
(`Label=UT2004_v3374_macOS`), ohne die App-Signatur zu verändern.

### 3.3 7-Zip (`7zz`)

`7zz` ist der einzige zuverlässige Weg, das macOS-DMG und das
nosTEAM-Installer-ZIP auf Linux zu inspizieren. Der Tool-Header pinnt:

```text
https://www.7-zip.org/a/7z2501-linux-x64.tar.xz
Größe:   1.571.044 B
SHA-256: 4ca3b7c6f2f67866b92622818b58233dc70367be2f36b498eb0bdeaaa44b53f4
Version: 25.01
```

`build-portable.sh` und `extract-archive.sh` laden es bei Bedarf nach
`~/.local/share/lanomat/tools/7zz` und prüfen die Summe. Offline:
`LANOMAT_UT2004_7ZZ=/pfad/zu/7zz` bzw. `LANOMAT_7ZZ=/pfad/zu/7zz`.

### 3.4 Portable Namen

| OS | Dateiname | Rezept-Match |
| --- | --- | --- |
| Windows | `ut2004-portable-3374-windows.zip` | `*UT2004*portable*.zip` (kurz auch `ut2004-portable-3374.zip`) |
| Linux | `ut2004-portable-3374-linux.zip` | `ut2004-portable-3374-linux*` |
| macOS | `ut2004-portable-3374-macos.zip` | `ut2004-portable-3374-macos*` |

Die Namen tragen das OS, damit ein Linux- oder macOS-Plan nie das
Windows-Archiv bekommt. Das Windows-Match ist breit; die drei Namen müssen
deshalb exakt so vergeben werden. Linux akzeptiert auch `.tar.bz2`; das
erhält Symlinks und `0755` exakt, ist aber nicht der veröffentlichte
Standard.

## 4. Tool-Index

Alle Tools liegen in `scripts/ops/ut2004/`. **Der Skriptkopf und `--help`
sind maßgeblich**; die Tabelle ist nur der Einstieg. Kein Tool braucht
Secrets als Argument; Cookies und Token werden aus Dateien gelesen.

| Tool | Zweck | `--help` | Beispiel |
| --- | --- | --- | --- |
| `fetch-base.sh` | archive.org-Base in 4 Ranges resumierbar laden, Mirrors rotieren, Größe prüfen | `scripts/ops/ut2004/fetch-base.sh --help` | `scripts/ops/ut2004/fetch-base.sh --dry-run` |
| `extract-archive.sh` | ZIP/TAR/DMG/ISO/7z/RAR/SFX listen, Baum zeigen, extrahieren, Game-Daten prüfen | `scripts/ops/ut2004/extract-archive.sh --help` | `scripts/ops/ut2004/extract-archive.sh tree "ut2k4+keygen.zip" --depth 2` |
| `build-portable.sh` | aus flachem Spielbaum + drei Patches die drei 3374-ZIPs bauen und verifizieren | `scripts/ops/ut2004/build-portable.sh --help` | `scripts/ops/ut2004/build-portable.sh --source <tree> --patches <dir> --out clients-dist/ut2004` |
| `install-smoke.sh` | Live-Recipe auf lokalen HTTP-Server zeigen, echten Launcher-Install ausführen, restaurieren | `scripts/ops/ut2004/install-smoke.sh --help` | `LANOMAT_ADMIN_COOKIES_FILE=/tmp/opencode/cookies.txt scripts/ops/ut2004/install-smoke.sh --dry-run` |
| `live-recipe.mjs` | Live-Panel (Filament-ID 20) lesen, Plan prüfen, Backup/Restore, URL/Patches setzen | `node scripts/ops/ut2004/live-recipe.mjs --help` | `node scripts/ops/ut2004/live-recipe.mjs show --json` |

Details, die hier bewusst nicht wiederholt werden: Archiv-Layout,
`System/`-Löschung, ZIP-vs-tar.bz2, Patch-Reihenfolge und Exit-Codes stehen im
Kopf von `build-portable.sh`; die Range-/Adopt-Logik im Kopf von
`fetch-base.sh`; die Cookie-/Playwright-Logik im Kopf von
`install-smoke.sh` und `live-recipe.mjs`.

## 5. Runbook

### 5.1 Base laden

Der Normalfall ist das archivierte Full-Game-ZIP. `fetch-base.sh` fragt die
archive.org-Metadata-API nach Größe und Mirrors, lädt vier unabhängige
Byte-Ranges mit `curl -r`, prüft `206`/`Content-Range` und resume't jede
Range einzeln.

```bash
# Erst schauen, nichts schreiben:
scripts/ops/ut2004/fetch-base.sh --dry-run

# Dann laden (Default: 4 Ranges, Ausgabe ~/Downloads/Unreal Tournament 2004.zip):
scripts/ops/ut2004/fetch-base.sh --parallel 4
```

Erwartete Größe: **2.550.381.122 B**. Das Skript bricht bei Größenmismatch
ab (Exit 4) und behält die Teile. Stand 2026-11-21, 15:21, liegt der
Hand-Download bereits vollständig unter
`~/Downloads/Unreal Tournament 2004.zip` (2.550.381.122 B,
MD5 `55bd1e0f512cde5f7ce7fbf70dabcfaa`); `fetch-base.sh --dry-run` erkennt
ihn als `layout: complete output` und schreibt nichts. Bei einem bereits
laufenden Hand-Download aus `/tmp/opencode/utparts`:

```bash
# Layout aus den laufenden curl-Prozessen ableiten, nicht raten:
scripts/ops/ut2004/fetch-base.sh --dry-run \
  --parts-dir /tmp/opencode/utparts --adopt-running

# Nur wenn die alten Curls beendet werden dürfen:
scripts/ops/ut2004/fetch-base.sh \
  --parts-dir /tmp/opencode/utparts --adopt-running --take-over
```

`--take-over` sendet SIGTERM **nur** an die Prozesse, die genau diese
Part-Dateien halten; kein `pkill`-Selbsttreffer. Wenn die alten Ranges nicht
sauber adoptierbar sind, ist `--fresh-parts` der sichere Neustart (bewegt
unbekannte Dateien beiseite, löscht nichts).

**Manueller 4-Range-Fallback** (nur wenn das Skript fehlt; die
Header-/Range-Prüfung entfällt dann):

```bash
URL='https://ia800800.us.archive.org/25/items/unreal-tournament-2004_202405/Unreal%20Tournament%202004.zip'
SIZE=2550381122
mkdir -p /tmp/opencode/utparts
for i in 0 1 2 3; do
  start=$(( i * SIZE / 4 ))
  end=$(( (i + 1) * SIZE / 4 - 1 ))
  [ "$i" -eq 3 ] && end=$(( SIZE - 1 ))
  curl -fL --retry 8 --retry-all-errors \
    --speed-limit 10240 --speed-time 60 --max-time 1800 \
    -r "${start}-${end}" -C - \
    -o "/tmp/opencode/utparts/p${i}" "$URL" &
done
wait
cat /tmp/opencode/utparts/p0 /tmp/opencode/utparts/p1 \
    /tmp/opencode/utparts/p2 /tmp/opencode/utparts/p3 \
  > "$HOME/Downloads/Unreal Tournament 2004.zip"
```

Beobachtung vom 2026-11-21: Einzelstreams liefen mit ~1,5–2,7 MB/s und
blieben dann bei 129,6 MB / 159,2 MB / 178,7 MB über Minuten auf 0 B/s
stehen. Vier Ranges plus lokaler HTTP-Server waren der Workaround; der
dauerhafte Fix ist das portable Archiv auf dem Filehost.

### 5.2 ZIP prüfen

```bash
ZIP="$HOME/Downloads/Unreal Tournament 2004.zip"

stat -c '%s' "$ZIP"                 # 2550381122
md5sum "$ZIP"                       # 55bd1e0f512cde5f7ce7fbf70dabcfaa
sha1sum "$ZIP"                      # 7dd8b9f5319c2d660a92c486bf1a8055e73c972d
unzip -tq "$ZIP"                    # No errors detected

# flach? erwartet: Animations, Maps, Sounds, StaticMeshes, System, Textures, ...
unzip -Z1 "$ZIP" | awk -F/ 'NF>1{print $1}' | sort -u
```

Ein einzelner Wrapper-Ordner ist kein Fehler; `extract-archive.sh
--strip-wrapper` und `build-portable.sh` entfernen genau eine Ebene. Ein
`System/` aus dem Retail-Baum darf später **nicht** mit dem Patch gemischt
werden.

### 5.3 Lokal servieren und Rezept-URL umschalten

Für einen Test auf **einem** Rechner kann der Launcher `127.0.0.1` erreichen.
Für einen zweiten PC im LAN braucht es die LAN-IP des Servers.

```bash
mkdir -p /tmp/opencode/ut2004-serve
ln -sf "$HOME/Downloads/Unreal Tournament 2004.zip" /tmp/opencode/ut2004-serve/
LAN_IP="$(hostname -I | awk '{print $1}')"

# Terminal A: Server (Python liefert 200, kein Range; der Launcher fällt darauf zurück)
python3 -m http.server 8099 --bind 0.0.0.0 --directory /tmp/opencode/ut2004-serve

# Terminal B: erreichbar?
curl -sSI "http://$LAN_IP:8099/Unreal%20Tournament%202004.zip" | head -5
```

Wenn Range/Resume wichtig ist, statt Python z. B. Caddy nehmen:
`caddy file-server --listen :8099 --root /tmp/opencode/ut2004-serve`.

Recipe-URL umschalten (Live-Panel, mit Admin-Cookies):

```bash
node scripts/ops/ut2004/live-recipe.mjs set \
  --iso-url "http://$LAN_IP:8099/Unreal%20Tournament%202004.zip" \
  --apply --yes

node scripts/ops/ut2004/live-recipe.mjs plan --os linux
```

Ohne `--apply --yes` zeigt `set` nur den Diff. Nach dem Test **zurück**:
`live-recipe.mjs restore` aus dem automatischen Backup (Abschnitt 5.8) oder
explizit `set --iso-url '<alter Wert>' --apply --yes`.

Komfortabler ist `install-smoke.sh`: Es startet den lokalen Server selbst,
schaltet die Live-URL auf `http://127.0.0.1:8099/<archiv>`, installiert mit
dem echten Launcher-Core und restauriert per Trap. Es braucht
Admin-Cookies:

```bash
LANOMAT_ADMIN_COOKIES_FILE=/tmp/opencode/cookies.txt \
  scripts/ops/ut2004/install-smoke.sh \
  --base "$HOME/Downloads/Unreal Tournament 2004.zip" \
  --target "$HOME/Games/unreal-tournament-2004" \
  --dry-run

# ohne --dry-run, optional mit --launch:
LANOMAT_ADMIN_COOKIES_FILE=/tmp/opencode/cookies.txt \
  scripts/ops/ut2004/install-smoke.sh \
  --base "$HOME/Downloads/Unreal Tournament 2004.zip" \
  --target "$HOME/Games/unreal-tournament-2004" --launch
```

`--dry-run` ändert nichts. Ein abgebrochener Lauf mit `--no-restore` lässt
die Live-URL absichtlich stehen und druckt das Backup — danach manuell
restaurieren.

### 5.4 Launcher installieren

Der reguläre Weg ist die Website: `https://lan.raute1.org/launcher`, ein
Build für das OS, SHA-256 daneben. Linux:

```bash
cd "$HOME/Downloads"
tar -xzf lanomat-launcher-<version>-linux-x86_64.tar.gz
cd lanomat-launcher-<version>-linux-x86_64
./install-desktop.sh          # ~/.local/bin + .desktop + lanomat://-Handler
# oder direkt: ./lanomat-launcher
```

Windows: portable `.exe` starten; SmartScreen → „Weitere Informationen“ →
„Trotzdem ausführen“. macOS: `.dmg` öffnen, App nach `/Programme` ziehen;
unsignierte Builds mit Rechtsklick → **Öffnen** oder
`xattr -dr com.apple.quarantine "/Applications/LANoMAT Launcher.app"`.

Ohne veröffentlichten Build lokal bauen (Linux, Docker):

```bash
scripts/ops/build-launcher.sh --allow-dirty
ls clients-dist/launcher/
```

Danach im Launcher die Server-URL eintragen, **Verbinden** drücken und den
Code unter `/mcp/connect` freigeben. Der Token landet in
`~/.config/lanomat-launcher/config.json` (Linux), nicht im Repo.

### 5.5 Installation prüfen

Zielordner kommt aus dem Plan, nicht aus diesem Dokument:

| OS | Default-Ziel |
| --- | --- |
| Windows | `C:\Games\Unreal Tournament 2004` |
| Linux | `~/Games/unreal-tournament-2004` |
| macOS | `~/Library/Application Support/UT2004` (App: `~/Applications/UT2004.app`) |

Linux:

```bash
TARGET="$HOME/Games/unreal-tournament-2004"

test -f "$TARGET/System/Build.ini"
grep -E 'v3374' "$TARGET/System/Build.ini"
test -x "$TARGET/System/UT2004"
test -f "$TARGET/System/ut2004-bin-amd64"
test -f "$TARGET/Maps/DM-Rankin.ut2"
```

Windows (PowerShell):

```powershell
$target = 'C:\Games\Unreal Tournament 2004'
Test-Path "$target\System\UT2004.exe"
Test-Path "$target\System\D3D11Drv.dll"
Test-Path "$target\System\Build.ini"
Select-String -Path "$target\System\Build.ini" -Pattern 'v3374'
Test-Path "$target\Maps\DM-Rankin.ut2"
```

macOS:

```bash
test -x "$HOME/Applications/UT2004.app/Contents/MacOS/UT2004"
test -f "$HOME/Applications/UT2004.app/Contents/Resources/System/Packages.md5"
test -f "$HOME/Library/Application Support/UT2004/Maps/DM-Rankin.ut2"
```

Zusätzlich der Server-Plan: `missing` darf nur externe, nicht benötigte
Downloads nennen; ein `base`-Treffer muss als `matched` erscheinen.

```bash
node scripts/ops/ut2004/live-recipe.mjs plan --os linux
```

### 5.6 Starten

| OS | Befehl |
| --- | --- |
| Linux | `"$TARGET/System/ut2004-bin-amd64"` (aus `System/` starten, falls Pakete fehlen) |
| Windows | `Start-Process -FilePath "$target\System\UT2004.exe" -WorkingDirectory "$target\System"` |
| macOS | `open "$HOME/Applications/UT2004.app"` |

Ein frischer Start ist immer flat; VR wird nur über `Play-VR.bat` aktiviert
und ist nicht Teil des Piloten. Wenn das Spiel nach der DVD fragt, ist der
Patch nicht im Ordner mit `System/` gelandet; wenn das alte Menü erscheint,
wurde ein Retail-`System` mit dem Patch gemischt.

### 5.7 Portable Archive bauen und hochladen

Voraussetzungen: flacher Spielbaum (oder ZIP) und ein Verzeichnis mit genau
je einem Windows-, Linux- und macOS-Patch.

```bash
PATCHES="$HOME/Downloads/ut2004-build/patches"
mkdir -p "$PATCHES"
cd "$PATCHES"

curl -fLO https://github.com/OldUnreal/UT2004Patches/releases/download/3374-rc3/OldUnreal-UT2004Patch3374-Windows-d906d39a.zip
curl -fLO https://github.com/OldUnreal/UT2004Patches/releases/download/3374-rc3/OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2
curl -fLO https://github.com/OldUnreal/UT2004Patches/releases/download/3374-rc3/OldUnreal-UT2004Patch3374-macOS.dmg
sha256sum -c <<'EOF'
16bd65fcf736163e1f00fbb9404a492489bea6869d87496dcd15c2fed4d96dd2  OldUnreal-UT2004Patch3374-Windows-d906d39a.zip
d79eb45fda3ace2b7a68e6a0b9730b7d6602004b111e0b16f3f7cc28f3111572  OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2
8c9bd030127c2fd37c2565aad59cb5f4dbb696909e01c1cb975aecf7e38b8e7c  OldUnreal-UT2004Patch3374-macOS.dmg
EOF

cd -
scripts/ops/ut2004/build-portable.sh \
  --source "$HOME/Downloads/Unreal Tournament 2004.zip" \
  --patches "$PATCHES" \
  --out clients-dist/ut2004 \
  --work /tmp/opencode --keep
```

Ergebnis (feste Namen, flaches Layout):

```text
clients-dist/ut2004/ut2004-portable-3374-windows.zip
clients-dist/ut2004/ut2004-portable-3374-linux.zip
clients-dist/ut2004/ut2004-portable-3374-macos.zip
```

Der Build löscht den Retail-`System/` und legt den OS-Patch darüber; er
verifiziert `Maps/DM-Rankin.ut2`, `Build.ini` mit `v3374` und die
OS-Binaries. Danach:

```bash
sha256sum clients-dist/ut2004/ut2004-portable-3374-*.zip
```

**Hochladen:** im LANoMAT-Files-Bereich des Events (Live: `/events/event-1/files`)
unter exakt diesen Namen. Große Dateien laufen über den resumierbaren
tus-Pfad; der Filehost signiert nur Downloads, er listet nicht. Die
Rezept-`base`-Rollen haben `url: ''` und matchen den Namen auf dem Filehost;
die gespeicherte SHA-256 des Filehosts wird beim Client geprüft. Einen
Rezept-Pin nur setzen, wenn genau dieses Archiv gepinnt werden soll.

**Reihenfolge:** Erst HEAD deployen (Abschnitt 7.3), dann die portablen
Archive hochladen. Im heutigen Live-Rezept gibt es noch keine
`base`-Rollen; die breite ISO-Match (`*ut2004-portable*.zip`) würde ein
portables ZIP sonst als ISO behandeln und den Linux-Patch darauf anwenden.

Ohne Filehost (nur Test): `install-smoke.sh --base <zip>` oder die URL der
ISO-Rolle auf den lokalen HTTP-Server zeigen (Abschnitt 5.3). Die
`base`-URL wird im Panel gesetzt; `live-recipe.mjs set` kennt derzeit nur
`--iso-url`.

### 5.8 Live-Rezept dumpen und restaurieren

`live-recipe.mjs` arbeitet über die echte Filament-Seite (Livewire), weil
nur das Panel `metadata`, `launch_targets`, Tags und den Review-Stempel
erhält. Das Bearer-Token wird nur für `plan` gelesen; Admin-Traffic nutzt
Cookies aus `/tmp/opencode/cookies.txt` (Default) oder
`LANOMAT_ADMIN_COOKIES_FILE`.

```bash
# Lesen (read-only):
node scripts/ops/ut2004/live-recipe.mjs show --json

# Vollständigen Formzustand sichern:
node scripts/ops/ut2004/live-recipe.mjs backup \
  --out /tmp/opencode/ut2004-live-before.json

# Diff zeigen, dann anwenden:
node scripts/ops/ut2004/live-recipe.mjs set \
  --iso-url 'https://ia800800.us.archive.org/25/items/unreal-tournament-2004_202405/Unreal%20Tournament%202004.zip'
node scripts/ops/ut2004/live-recipe.mjs set \
  --iso-url 'https://ia800800.us.archive.org/25/items/unreal-tournament-2004_202405/Unreal%20Tournament%202004.zip' \
  --apply --yes

# Zurück (ohne --apply nur Diff):
node scripts/ops/ut2004/live-recipe.mjs restore \
  --file /tmp/opencode/ut2004-live-before.json --apply --yes
```

`restore` spielt den kompletten Formzustand zurück, inklusive `metadata`.
Wenn nur Requirements geändert werden sollen und die Live-`launch_targets`
bleiben sollen: `--keep-metadata`. `--apply` legt vorher automatisch ein
Backup in `/tmp/opencode` ab. Nach jedem Eingriff alle drei Pläne prüfen:

```bash
for os in windows linux macos; do
  node scripts/ops/ut2004/live-recipe.mjs plan --os "$os"
done
```

**04:15-Falle:** `routes/console.php` plant
`lanomat:recipes:sync` täglich um 04:15. Der Sync liest
`RECIPES_SOURCE` (Default `resources/recipes`) und überschreibt die Felder,
die im YAML stehen; Status und Datei-Pins bleiben erhalten, freie
Panel-Änderungen nicht. Ein Panel-Edit ist deshalb nur bis zum nächsten
Sync sicher. Dauerhaft gehört die Änderung in
`resources/recipes/unreal-tournament-2004/recipe.yaml` und danach:

```bash
docker compose --profile prod exec -T app \
  php artisan lanomat:recipes:sync /app/resources/recipes \
  --only=unreal-tournament-2004 --json
```

Wenn `RECIPES_SOURCE` auf einer SMB-Freigabe mit alter YAML steht, muss
auch dort die neue `recipe.yaml` liegen; sonst holt der 04:15-Lauf die alte
Fassung zurück.

### 5.9 macOS: App bauen, signieren, notarisieren

**UT2004 selbst:** Das Patch-DMG ist nicht von Apple notarisiert und nicht
signiert.
Auf macOS mounten, `UT2004.app` nach `~/Applications` kopieren, die
Asset-Ordner nach `~/Library/Application Support/UT2004` (ohne `System/`),
dann einmalig:

```bash
xattr -dr com.apple.quarantine "$HOME/Applications/UT2004.app"
```

**Launcher:** `.app`/`.dmg` lassen sich nicht von Linux cross-bauen. Auf
einem macOS-Host mit Xcode Command Line Tools und Rust:

```bash
cd clients/launcher
scripts/ops/build-launcher.sh
# oder direkt:
cd app
cargo tauri build --target universal-apple-darwin --bundles app,dmg
```

Artefakte:
`clients/launcher/target/universal-apple-darwin/release/bundle/macos/LANoMAT Launcher.app`
und `.../bundle/dmg/LANoMAT Launcher_0.1.0_*.dmg`.
`LANOMAT_LAUNCHER_MACOS_TARGET=aarch64-apple-darwin|x86_64-apple-darwin`
baut nur eine Architektur.

Signieren/notarisieren (Secrets nur im Repo-Settings, nie im Command-Log):

| Secret | Zweck |
| --- | --- |
| `APPLE_CERTIFICATE` | base64 des Developer-ID-Application-`.p12` |
| `APPLE_CERTIFICATE_PASSWORD` | Passwort des `.p12` |
| `APPLE_SIGNING_IDENTITY` | z. B. `Developer ID Application: … (TEAMID)` |
| `APPLE_ID` | Apple-ID für die Notarisierung |
| `APPLE_PASSWORD` | App-spezifisches Passwort |
| `APPLE_TEAM_ID` | 10-stellige Team-ID |

Prüfen:

```bash
codesign --verify --deep --strict --verbose=2 "LANoMAT Launcher.app"
spctl -a -vvv -t install "LANoMAT Launcher.app"
```

Bis die Secrets existieren, bleibt der Build `signed: false`; die
`/launcher`-Seite und das Manifest sagen das ehrlich. Ohne macOS-Build im
Manifest gibt es keinen macOS-Launcher-Download; die macOS-Installation
läuft dann über Rezept/Agent.

## 6. Sicherheit

| Regel | Konkret |
| --- | --- |
| Token nur in der Config | `~/.config/lanomat-launcher/config.json`, Modus `0600`; nie `cat`, nie in einen Chat, nie in ein Log |
| Admin-Cookies nur temporär | `/tmp/opencode/cookies.txt`, `chmod 600`, nicht committen; `install-smoke.sh` liest `LANOMAT_ADMIN_COOKIES_FILE` |
| Keine Passwörter | Keine `curl -u`, keine `sshpass`-Zeilen, keine Klartext-Secrets in Kommandos |
| Keine Token-Ausgabe | `set -x` aus; `live-recipe.mjs`/`install-smoke.sh` drucken keine Cookies/Token |
| Signierte URLs | Filehost-Links (`/dl/...?expires=…&md5=…`) sind kurzlebig und signiert; nicht in Doku/Commit kopieren |
| Kein `pkill`-Selbsttreffer | `pkill -f '[p]ython3 -m http.server 8099'`, nie `pkill -f python` oder `pkill -f lanomat` |
| Aufräumen | Server-PID stoppen, `/tmp/opencode/ut2004-serve`, Parts und Cookies entfernen; Backups nur bewusst behalten |
| Keine Secrets im Repo | `git status --short` vor dem Commit; `scripts/ops/check-public-safe.sh` laufen lassen |

Token lesen, ohne ihn zu zeigen:

```bash
python3 - <<'PY'
import json, os
c = json.load(open(os.path.expanduser('~/.config/lanomat-launcher/config.json')))
print(c['url'], c['user']['name'], c['server_version'])
PY
```

Aufräumen nach einem Test:

```bash
pkill -f '[p]ython3 -m http.server 8099' || true
rm -rf /tmp/opencode/ut2004-serve /tmp/opencode/utparts
unset TOKEN LANOMAT_ADMIN_COOKIES
```

**Public-Safety-Gate:** Das Repo ist öffentlich;
`scripts/ops/check-public-safe.sh` flaggt private IPs. Dieses Dokument nennt
`10.23.0.12` und `10.13.69.x` bewusst. Beim Commit braucht die Allowlist
mindestens:

```text
docs/ops/ut2004-pilot.md:^10\.23\.0\.12$
scripts/ops/ut2004/install-smoke.sh:^10\.23\.0\.12$
```

`10.13.69.x` ist bewusst kein voller Quad und matcht den Checker nicht.
Die Allowlist selbst ist ein separater Ops-Schritt (nicht Teil dieses
Dokuments).

## 7. Bekannte Blocker und Entscheidungen

| # | Blocker | Wirkung | Entscheidung / Workaround | Owner | Exit-Kriterium |
| --- | --- | --- | --- | --- | --- |
| 7.1 | archive.org stallt Einzelstreams bei 45–180 MB | Base-Download dauert Stunden oder hängt | `fetch-base.sh` mit 4 Ranges, Mirror-Rotation, per-Range-Resume; zusätzlich lokaler HTTP-Server | Ops | Base liegt auf dem Filehost, nicht auf archive.org |
| 7.2 | `files.lan.raute1.org:8443` (10.23.0.12) aus `10.13.69.x` nicht erreichbar | Client im WG-Netz bekommt keinen Download | vom 10.23-Netz laden/servieren oder Route/Firewall fixen; kein zweiter Upload | Infra | `curl -sSI https://files.lan.raute1.org:8443/` antwortet aus beiden Netzen |
| 7.3 | Live m12-768 filtert `os` nicht: eine Patch-Zeile ohne `os`, Linux-URL | Windows/macOS bekommen den Linux-Patch; falsches `System/` | HEAD deployen (drei OS-Patches, drei OS-Basen) und YAML synchronisieren; bis dahin kein Windows-/macOS-Go | Backend | `plan --os windows/linux/macos` zeigt je den passenden Patch |
| 7.4 | macOS-Launcher fehlt im Manifest; `.app`/`.dmg` braucht macOS + Sign/Notarization | Kein macOS-One-Click-Download | macOS-Installation über Rezept/Agent; Release auf `macos-14` mit Apple-Secrets | Release | `.dmg` im Manifest, `spctl` akzeptiert |
| 7.5 | `metadata.launch_targets` geht nicht über `recipe_contribute` | Contribute ersetzt `metadata` und verliert Launch-Targets | Panel oder YAML-Sync; `live-recipe.mjs restore --keep-metadata` | Backend/Ops | `launch_targets` nach Contribution unverändert |
| 7.6 | 04:15-Sync überschreibt Panel-Änderungen | Manueller Live-Fix ist nach einem Tag weg | YAML ist Quelle; `--only`-Sync nach Deploy; Dump vor Panel-Edit | Backend | Sync idempotent, Panel-Diff leer |
| 7.7 | `ut2k4+keygen.zip` ist nosTEAM-Installer | Kein portabler Baum, Setup.exe/ISO statt Spiel | nicht als `base` matchen; `extract-archive.sh tree` zur Inspektion | Ops | Base kommt aus `ut2004-portable-3374-*` |
| 7.8 | Keine `base`-Zeilen live, keine portablen Archive hochgeladen | Jeder Plan fällt auf ISO + Patch zurück | portables Archiv bauen/hochladen; danach Base-Match prüfen | Orga | `base` erscheint als `matched` im Plan |
| 7.9 | Live-ISO-URL kann auf `127.0.0.1:8099` zeigen (Smoke-Rest) | Client kann die Base nicht laden | `live-recipe.mjs show`, dann `set --iso-url <richtig>` oder `restore` | Ops | `show` zeigt archive.org oder lokalen, erreichbaren Server |

**Reihenfolge bis zum Pilot-Go:**

1. Base laden und prüfen (5.1–5.2).
2. Portable Archive lokal bauen (5.7), noch nicht hochladen.
3. HEAD deployen und YAML synchronisieren (7.3, 5.8); damit existieren die
   drei `base`-Rollen und die drei OS-Patches.
4. Die drei portablen Archive auf dem Filehost ablegen (5.7).
5. Drei Pläne prüfen; `base` muss matchen, jeder Patch muss zum OS passen.
6. Linux- und Windows-Smoke (`install-smoke.sh`) auf einem frischen Ziel.
7. macOS-Rezept-Smoke (DMG + Assets) auf einem echten Mac.
8. Optional: macOS-Launcher bauen/signieren und ins Manifest aufnehmen.

## 8. Verantwortlichkeiten

| Rolle | Verantwortung | Artefakt / Ort |
| --- | --- | --- |
| Orga/Operator | Event-Dateien, Filehost-Upload, Rezept veröffentlichen, Go/No-Go | `/events/event-1/files`, `/admin/recipes/20/edit` |
| Backend/Deploy | HEAD auf `origin/main`, Deploy, YAML-Sync, Plan-Filter | App-Host, `scripts/deploy/deploy.sh` |
| Launcher/Rust | Download/Archiv/Install/Verify, Manifest, Release | `clients/launcher/**`, `clients-dist/launcher` |
| macOS-Release | `.app`/`.dmg`, Codesign, Notarization, Gatekeeper | macOS-Host, Apple-Secrets |
| Ops-Tools | `fetch-base`, `extract-archive`, `build-portable`, `install-smoke`, `live-recipe` | `scripts/ops/ut2004/**` |
| Docs | dieses Runbook und der Tool-Index | `docs/ops/ut2004-pilot.md`, `scripts/ops/ut2004/README.md` |
| Helfer | Teilnehmer-PC: Launcher, Pairing, Verify, Start | Client-PC |
| Teilnehmer | Pairing freigeben, Zielordner/Platz wählen | `/mcp/connect`, Launcher |

Der Merge-Captain entscheidet, in welcher Reihenfolge die Karten landen;
niemand deployt eine Karte, deren Proof-Kommando nicht grün ist.

## 9. Verweise

- Programmspec: [`docs/superpowers/specs/2026-11-21-one-click-launcher.md`](../superpowers/specs/2026-11-21-one-click-launcher.md)
- Wave-Plan: [`docs/superpowers/plans/2026-11-21-ut2004-launcher-waves.md`](../superpowers/plans/2026-11-21-ut2004-launcher-waves.md)
- Rezept: [`resources/recipes/unreal-tournament-2004/recipe.yaml`](../../resources/recipes/unreal-tournament-2004/recipe.yaml)
- Skill: [`resources/recipes/unreal-tournament-2004/SKILL.md`](../../resources/recipes/unreal-tournament-2004/SKILL.md)
- Launcher: [`clients/launcher`](../../clients/launcher), [`clients/launcher/README.md`](../../clients/launcher/README.md)
- Agent: [`clients/agent`](../../clients/agent), [`clients/agent/README.md`](../../clients/agent/README.md)
- Nutzer-Guide: [`docs-site/pages/guides/launcher.mdx`](../../docs-site/pages/guides/launcher.mdx)
- Tool-Index: [`scripts/ops/ut2004/README.md`](../../scripts/ops/ut2004/README.md)
