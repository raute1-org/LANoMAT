# NFSU2 — installierbares LANoMAT-Paket

Stand: **2026-11-21**. Arbeitsverzeichnis: `/home/reneil1337/Downloads/nfsu2/`.
Keine Secrets in diesem Dokument (nur LAN-Adressen und Ports).

## 1. Ergebnis

| | |
| --- | --- |
| Datei | `/home/reneil1337/Downloads/nfsu2/nfsu2-portable.zip` |
| Größe | 2.602.084.719 Bytes (2,60 GB) |
| SHA-256 | `0d7028987ba4fdb5dfbd92e3c5a77fd2d6e98992b85b14ef0268fba040767219` |
| Einträge | 1.395 Dateien, 3.212.901.185 Bytes entpackt |
| Layout | flach, kein Wrapper-Ordner, kein Installer, keine Registry |
| `unzip -t` | „No errors detected“ |

Enthalten: kompletter Spielbaum (`CARS/`, `TRACKS/`, `SOUND/`, `MOVIES/`, …),
`speed.exe`, `dinput8.dll` (Ultimate ASI Loader), `scripts/net_u2.asi`,
`scripts/net_u2.ini`, `README-LANoMAT.txt`, `start-nfsu2-linux.sh`.

## 2. Quellen

| Teil | Quelle | Größe | Hash |
| --- | --- | ---: | --- |
| Spiel | archive.org `need-for-speed-underground-2_20260707`, Datei `Need for Speed - Underground 2.zip` (EN) | 2.593.519.936 B | MD5 `8386b637e9ea5ac6f98d9704b04b28bc`, SHA-256 `0565c4bc372995b770c72763cf734191759fd974344d15b177676180e83bef2e` |
| Patch 1.2 (Reserve) | archive.org `nfsu2-patches`, `Need-for-Speed-Underground-2_Patch_Win_EN_v12-US.zip` | 8.228.337 B | MD5 `fa945effffe6fcf73267ec72e83383ed`; enthält `nfsu2_v1.2_us.exe` (SHA-256 `4bad059e…`) |
| Mod-Client | `github.com/moio9/NFS-Online-Server`, Commit `96699afb34ed37c00f17dfdacf7207cbfad5a1a6` (2026-11-11), Paket 1.1.3 / Client 0.2.22 | `net_u2.asi` 112.640 B | SHA-256 `8db6557e685cf2dd47f825e62cc202cbace85f6cc4a43af271b8238e0367a04f` |
| ASI-Loader | `github.com/ThirteenAG/Ultimate-ASI-Loader` Release **v10.0.4** (2026-11-19), Asset `Ultimate-ASI-Loader-NoPDB.zip` (x86) | `dinput8.dll` 2.807.520 B | ZIP SHA-256 `b329e2b1fec9a64e051f4bac4d5530c6092327a090564db2c25488c47a4865d6`, DLL SHA-256 `73d6db0481af99b8481e8e6a685dd385e5a3e0e10518bfe97a993f7f368667eb` |
| Zig-Toolchain | `zig-x86_64-linux-0.16.0` (vom Repo-Bootstrap, SHA-geprüft) | – | – |

Das Original-ZIP ist vollständig (Content-Length exakt erreicht) und wurde nicht
neu geladen; die MD5 stimmt mit dem archive.org-Metadatensatz überein.

## 3. Version und Sprache

* ZIP-Top-Level: genau ein Wrapper `Need for Speed - Underground 2/` (1.393 Dateien,
  3.208.226.976 B) — beim Paketbau entfernt.
* Im ZIP heißt die Exe **`SPEED2.EXE`** (kein `speed.exe`): 4.800.512 B,
  Dateidatum 2005-03-09, SHA-256
  `f9dd86c054878ce6276beb07c1fd61874f7a1e4bf1f241b084c65b73e24168a7`.
  Das ist exakt die dokumentierte **v1.2 NTSC/US**-Binary (nfsu2.xyz,
  NFSU2 Unlimiter, nfsu2-recompiled nennen dieselbe Größe und denselben Hash).
* Sprache: **Englisch** (archive.org-Metadatum `language: eng`, `LANGUAGES/`,
  `memcard/LOCALE_ENGLISH.loc`).
* Der Patch 1.2 ist daher **nicht nötig** und wurde nicht angewendet
  (`patch/nfsu2-v12-en-us.zip` bleibt als Reserve für ältere Bäume liegen).
* Der Repack bringt bereits WidescreenFix, Extra Options und Unlimiter mit
  (`scripts/*.asi`) sowie einen älteren Ultimate-ASI-Loader als `dinput8.dll`.
  Die drei Mods bleiben unverändert drin; der Loader wurde durch v10.0.4 ersetzt.

## 4. Paketaufbau

```
nfsu2-portable.zip
├─ speed.exe                 4.800.512 B  (kanonischer Name, aus SPEED2.EXE)
├─ SPEED2.EXE                4.800.512 B  (identische Kopie, Repack-/Recipe-Name)
├─ dinput8.dll               2.807.520 B  (Ultimate ASI Loader v10.0.4)
├─ scripts/
│  ├─ net_u2.asi               112.640 B  (NFS-Online-Client 0.2.22)
│  ├─ net_u2.ini                   510 B  (Server/Ports)
│  ├─ NFSUnderground2.WidescreenFix.asi/.dat/.ini
│  ├─ NFSU2ExtraOptions.asi + Settings.ini
│  ├─ NFSU2Unlimiter.asi + Settings.ini + UnlimiterData/
├─ CARS/ TRACKS/ SOUND/ MOVIES/ GLOBAL/ FRONTEND/ NIS/ LANGUAGES/ …
├─ README-LANoMAT.txt
└─ start-nfsu2-linux.sh
```

Entfernt gegenüber dem Quell-ZIP: Wrapper-Ordner, `unins000.exe`/`unins000.dat`
(Inno-Setup-Reste des Repacks). Alles andere ist byte-identisch übernommen.

Der ASI-Loader lädt `.asi` aus `scripts/`; der Client sucht seine `.ini` und
schreibt sein Log **neben der ASI** (`scripts/net_u2-trace-<pid>.log`), passend
zur geforderten Ablage `scripts/net_u2.asi` + `scripts/net_u2.ini`.

## 5. Client-Konfiguration (`scripts/net_u2.ini`)

```ini
; Underground 2 Online and LAN client configuration (LANoMAT)
; Host = LANoMAT game server. Change "host" in both sections to move servers.
[network]
enabled = true
host = 10.23.0.12
bootstrap_port = 20921
lobby_port = 20922
control_port = 20923
control_alias_port = 13505
race_port = 20000

[lan]
enabled = true
host = 10.23.0.12
port = 20922
inject_server = true

[patches]
enabled = true

[logging]
enabled = true
udp_trace = false
```

* `host` gilt für alle Dienste (Bootstrap, Lobby, Control, Alias, Race).
* `[lan] inject_server = true` trägt den Server in die LAN-Serverliste ein.
* Ports wie im Server (`config/server.toml`): 20921 TCP, 20922 TCP, 20923 TCP,
  13505 TCP, 20000/UDP Race-Relay (Client-seitig ein Port; der Server lauscht
  auf 20000).
* **Host-Wahl:** Vorgabe ist der Platzhalter `10.23.0.12` (Public-/Dateihost laut
  Recipe und UT2004-Runbook). Die im Repo veröffentlichten Client-Configs
  (`clients/underground2/net_u2.ini`) tragen dagegen `10.23.3.220` — das ist die
  lokale IPv4 dieses Laptops und war zuletzt der tatsächliche Server. Beide Werte
  sind per `--server` austauschbar; im Zweifel den Wert nehmen, den
  `python nfs_online.py check` als „Public host“ meldet (aktuell `10.23.0.12`).

## 6. Reproduktion

```bash
cd /home/reneil1337/Downloads/nfsu2
./make_package.sh --server 10.23.0.12
# optional: --src <entpackter-spielbaum>  --out <ziel.zip>
```

`make_package.sh` entpackt nichts selbst: es erwartet den flachen Spielbaum unter
`build/game` (Wrapper entfernt, `SPEED2.EXE` → `speed.exe`), kopiert Loader, ASI
und die generierte INI hinein, prüft `speed.exe`, `dinput8.dll`,
`scripts/net_u2.asi`, `scripts/net_u2.ini`, `CARS/`, `TRACKS/` und zippt flach.

Client-Build (einmalig):

```bash
cd NFS-Online-Server/source/client-zig
# .tools aus dem Quellbaum heraushalten, sonst zählt tools/check_source.py
# die musl-.c-Dateien der Toolchain als "non-Zig implementation files":
mv .tools ../../zig-toolchain
# veröffentlichte INIs != Build-INIs (nur host) -> angleichen:
cp ../../clients/underground2/net_u2.ini config/
cp ../../clients/most-wanted/net_mw.ini  config/
cp ../../clients/carbon/net_carbon.ini   config/
ZIG=../../zig-toolchain/zig-x86_64-linux-0.16.0/zig ./build_linux.sh
```

Ergebnis: `source check: OK`, `port audit: OK`, PE-Audit OK für alle drei ASIs.
Beide Stolpersteine sind Eigenheiten des Upstream-Repos, keine Änderung daran.

## 7. Start

**Windows** — `speed.exe` doppelklicken (Ordner mit `dinput8.dll`).

**Linux, System-Wine**

```bash
export WINEDLLOVERRIDES="dinput8=n,b"
wine speed.exe
```

**Linux, Proton (auf diesem Laptop getestet: Proton Experimental, Wine 11.0)**

```bash
./start-nfsu2-linux.sh
```

Das Skript nimmt `wine`, sonst `proton run` aus
`~/.local/share/Steam/steamapps/common/Proton*` mit
`STEAM_COMPAT_CLIENT_INSTALL_PATH` und `STEAM_COMPAT_DATA_PATH=$PWD/.proton`.
Wichtig: **nicht** die nackte `files/bin/wine` aus Proton verwenden — in einem
frischen Prefix fehlen dann die `libvkd3d-*.dll`, `d3d9.dll` lädt nicht und das
Spiel bricht mit `c0000135` ab. `proton run` richtet den Prefix korrekt ein.

**Steam** — `speed.exe` als Nicht-Steam-Spiel, „Proton Experimental“ erzwingen,
Startoption `WINEDLLOVERRIDES="dinput8=n,b" %command%`.

**Flatpak/Bottles** — Bottle (Windows 10), `speed.exe` starten, Env
`WINEDLLOVERRIDES=dinput8=n,b`.

Der Override ist in allen Fällen Pflicht: ohne ihn lädt Wine sein eingebautes
`dinput8`, der ASI-Loader läuft nie und `net_u2.asi` bleibt wirkungslos.

## 8. Tests (auf diesem Laptop)

| Test | Ergebnis |
| --- | --- |
| Quell-ZIP `unzip -t` | OK, MD5 = archive.org |
| Paket-ZIP `unzip -t` | OK, 1.395 Einträge, flach |
| Pflichtdateien im ZIP | `speed.exe`, `SPEED2.EXE`, `dinput8.dll`, `scripts/net_u2.asi`, `scripts/net_u2.ini`, `CARS/`, `TRACKS/` vorhanden; Member-Hashes geprüft |
| Entpacken in Temp-Ziel (`test-final/game`, nicht `~/Games`) | 31 Root-Einträge, 3,2 GB |
| Start über `./start-nfsu2-linux.sh` (Proton Experimental) | Spiel startet und rendert (Titelbild/„Press Enter Key“, Intro-FMVs, 1800×1125) |
| ASI-Laden | `net_u2.asi` + die drei Repack-ASIs werden aus `scripts/` geladen |
| Client-Log | `network=true bootstrap=10.23.0.12:20921 …`, `lan=true … inject=true`, `U2 base patches installed`, `U2 LAN injection installed` |
| Dll-Override | ohne Override kein ASI-Laden (Wine-builtin `dinput8`), mit Override sofort aktiv |

Nicht getestet: vollständiger Login-/Lobby-/Renn-Handshake mit zwei echten
Clients, Klickpfad im LAN-Menü, Split-Screen parallel zum ASI.

Ein lokaler Serverstart in `NFS-Online-Server` scheiterte an belegten Ports:
ein bereits laufender root-`nfs_online.py`-Prozess hielt 20000/UDP und die
TCP-Ports; `nfs_online.py status` meldet dafür „Stopped“, weil
`runtime/services.json` fehlt (bekannter Punkt im Recipe).

## 9. Offene Punkte

1. **Server-Host festlegen:** `10.23.0.12` (Public/Dateihost) vs. `10.23.3.220`
   (lokale IPv4 des Testlaptops, Wert der veröffentlichten Client-Configs).
   Der Server muss dieselbe Adresse annoncieren, sonst passt die Lobby nicht.
2. **Zwei-Client-Test** Login → Lobby → Rennen inkl. UDP-Relay 20000.
3. **Mod-Verträglichkeit:** Unlimiter/ExtraOptions patchen dieselbe Exe wie der
   Online-Client. Bei Abstürzen oder fehlenden LAN-Einträgen die drei
   Repack-ASIs testweise deaktivieren (`*.asi.off`), `net_u2.asi` bleibt.
   Split-Screen + ASI laut Recipe ungetestet.
4. **Verteilung:** 2,6-GB-Upload auf den Dateihost (`nfsu2-portable.zip`) und
   Recipe-Veröffentlichung stehen noch aus; das Recipe erwartet `SPEED2.EXE`
   (deshalb die identische Kopie im Paket) und dokumentiert derzeit noch
   „raw archive, flat package pending“.
5. **Save-Pfad:** WidescreenFix legt Profile unter `SAVE/` im Spielordner ab
   (`CustomUserFilesDirectoryInGameDir = SAVE`) — portabel, aber pro Installation
   getrennt; Profile nicht zwischen Rechnern kopieren, ohne die `.ini` zu kennen.
6. **Log-Wachstum:** pro Start entsteht `scripts/net_u2-trace-<pid>.log`; bei
   Turnieren vorher aufräumen (`udp_trace` bleibt aus).
7. `server.cfg`/`server.dll` im Repack sind EA-Originale (ADDR
   `159.153.253.82`); der Client überschreibt die Ziele, nicht anfassen.

## 10. Dateien in `/home/reneil1337/Downloads/nfsu2/`

| Pfad | Inhalt |
| --- | --- |
| `NFSU2-portable.zip` | Original-Download (2,59 GB) |
| `nfsu2-portable.zip` | **fertiges Paket** (2,60 GB) |
| `make_package.sh` | Paketbau, `--server`/`--src`/`--out` |
| `package/` | README + Linux-Starter (Quellen fürs Staging) |
| `build/game/` | flacher Spielbaum (Quelle für Rebuilds) |
| `NFS-Online-Server/` | Client-Quelle + Build (Zig), `zig-out/bin/net_u2.asi` |
| `zig-toolchain/` | Zig 0.16.0 (aus dem Repo-Bootstrap ausgelagert) |
| `asi-loader/` | Ultimate-ASI-Loader v10.0.4 + entpackte `dinput8.dll` |
| `patch/` | v1.2-EN-US-Patch (Reserve, nicht angewendet) |
| `test-final/`, `test-target/` | Temp-Testziele inkl. Logs/Screenshots |
| `PACKAGE.md` | dieses Dokument |
