# All-in-one-Client — Inventur der Rezepte (Phase 0, Task 0.6)

Stand: 2026-11-22. Reine Auswertung von `resources/recipes/*/recipe.yaml` gegen
`app/Modules/GameRecipes/Support/LauncherSupport.php`; es wurde kein Rezept geändert. Eingabe für
Plan-Task 2.5 (Rezepte mit Befehlen) und für die Entscheidung 4 im ADR
([`2026-11-22-all-in-one-client-adr.md`](2026-11-22-all-in-one-client-adr.md)).

## 1. Regel, nach der `launcher_supported` entsteht

`LauncherSupport::supports()` ist wahr, wenn eine dieser Bedingungen gilt (Reihenfolge wie im Code):

1. `metadata.launcher_supported` (bool) überschreibt alles. **Kein Rezept im Repo setzt es.**
2. Slug ist der Pilot `unreal-tournament-2004` oder steht in `VERIFIED_SLUGS`
   (heute nur `need-for-speed-underground-2`).
3. Browser-Rezept: `metadata.play_url` gesetzt (`play_url` steht im YAML top-level und wird vom
   `RecipeParser` nach `metadata` gehoben).
4. `metadata.portable: true` **und** eine erforderliche Base-Datei **und** ein
   `file_exists`/`dir_exists`-Check in einem Pflichtschritt **und** mindestens ein Launch-Target
   (`isPortable()`). `portable` ist das Versprechen des Autors, dass Entpacken des Base-Archivs die
   ganze Installation ist.

Der Launcher führt **nie** die `commands:` eines Schritts aus. Er lädt, prüft, entpackt, verifiziert
und startet ein Launch-Target. Für die zwei Sonderfälle (Pilot, `VERIFIED_SLUGS`) ersetzt
Rust-Code (`launcher-core/install.rs`) die Befehle des Rezepts.

Hinweis: `LauncherSupport` prüft den Status nicht. Entwürfe (`draft`) sind nur für Ersteller und
Prüfer sichtbar (`GameRecipe::scopeVisibleTo`); veröffentlicht sind vier Rezepte
(`_catalog.md`).

## 2. Zahlen

| Gruppe                                           | Anzahl                                                                                                                                                 |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Rezepte im Repo                                  | 27 (4 `published`, 23 `draft`)                                                                                                                         |
| `launcher_supported` = wahr                      | 3 (Pilot UT2004, `need-for-speed-underground-2` per `VERIFIED_SLUGS`, `need-for-speed-underground-2-browser` per `play_url`)                           |
| `launcher_supported` = falsch                    | 24 (davon 1 veröffentlicht: `ridge-racer-type-4`)                                                                                                      |
| Rezepte mit mindestens einem `commands:`-Eintrag | 26 (alle außer `need-for-speed-underground-2-browser`)                                                                                                 |
| `metadata.portable: true`                        | 0                                                                                                                                                      |
| `metadata.launcher_supported` gesetzt            | 0                                                                                                                                                      |
| Rezepte mit `play_url`                           | 1                                                                                                                                                      |
| Rezepte mit `launch_targets`                     | 3 (UT2004, NFSU2, Minecraft); 21 haben nur den älteren `launch`-Block, 3 weder noch (`f-zero-x-vr`, `god-of-war-iii-remastered`, `ridge-racer-type-4`) |

Gezählt per kleinem Auswertungsskript über die YAML-Dateien (Befehle = Einträge unter
`steps[].commands[]`, Skriptzeilen separat in Abschnitt 3 klassifiziert). Ein Pest-Ratchet, der die
Zahl der Befehls-Rezepte festhält, ist optional und wurde hier nicht angelegt (kein PHP in diesem
Auftrag).

Rezepte, die über den Portable-Builder in der Datenbank entstehen (`PortableRecipeBuilder`,
`metadata.portable: true`), sind in dieser Zählung nicht enthalten; sie sind genau der Fall, den
der Generic-Engine-Pfad bedient.

## 3. Welche Befehle kommen vor (Klassifizierung der Skriptzeilen)

Alle 26 Befehls-Rezepte nutzen nur wenige Handgriffe. Zeilen je Wortart, über alle Rezepte:

| Handgriff                         | Beispiele                                        | Vorkommen                             | Deklarativ ersetzbar?                                                                                                                       |
| --------------------------------- | ------------------------------------------------ | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Ordner anlegen                    | `mkdir`, `New-Item -ItemType Directory`          | fast alle                             | ja (`mkdir`)                                                                                                                                |
| Archiv entpacken                  | `Expand-Archive`, `unzip`, `tar`                 | fast alle                             | ja (`extract` mit Pfadprüfung, Engine kann ZIP, tar.bz2, ISO)                                                                               |
| Datei(en) kopieren                | `Copy-Item`, `cp`                                | Emulator-Rezepte, NFSU2, UT2004       | ja (`copy` innerhalb von `{{target}}`/`{{downloads}}`)                                                                                      |
| ISO einhängen und kopieren        | `Mount-DiskImage`, `hdiutil attach`              | UT2004                                | ja unter Windows/Linux (`libmkiso` im Pilot); macOS-DMG nur auf dem Mac-Build                                                               |
| Verschachteltes ZIP finden        | `$zip=…`, `$inner=…`, `Get-ChildItem`            | Minecraft, Hookshot Standalone, NFSU2 | ja (`extract` mit `strip_components` oder `find: glob`)                                                                                     |
| Konfigurationsdatei schreiben     | `$lines`, `Set-Content`                          | NFSU2 (`net_u2.ini`)                  | ja (`write_file`, begrenzt auf `{{target}}`)                                                                                                |
| Protokoll in der Registry anlegen | `Set-ItemProperty` (`ut2004://`)                 | UT2004                                | nur als `registry_set` (HKCU, Allowlist-Schlüssel, Spec 5.3); der `ut2004://`-Handler wird mit `lanomat://join` voraussichtlich überflüssig |
| Fremdes Programm starten          | `Start-Process` (Installer, Builder, Injector)   | Hookshot, UEVR, CS 1.6                | nein (Regel "kein Installer, nie UAC")                                                                                                      |
| Steam anstoßen                    | `Start-Process "steam://install/10"`, `xdg-open` | CS 1.6                                | nein (Entscheidung 11)                                                                                                                      |
| Bauen mit Fremdwerkzeugen         | `git`, `npm`, `node`, `./Setup.sh`               | Ridge Racer 4, MM3D-PC                | nein (Shell, Netz, beliebiger Code)                                                                                                         |
| Server-Start-Skript               | `start-test.bat`                                 | Ridge Racer 4                         | nein; Server gehört auf den Server, nicht auf den Spieler-PC                                                                                |

Folgerung: ein kleiner Satz deklarativer Schritt-Arten deckt, was die Rezepte tatsächlich brauchen:
`mkdir`, `extract` (ZIP, tar.bz2, ISO über die vorhandene Engine), `copy`, `write_file`, und nur
für UT2004 optional `registry_set` (Spec 5.3 nennt zusätzlich `shortcut`, kein Rezept braucht es
heute). Alles andere bleibt manuell oder ausgeschlossen.

## 4. Inventur je Rezept

Spalten: **Sup.** = `launcher_supported` nach `LauncherSupport` für die Datei im Repo.
**Befehle** = Anzahl Befehle in `steps[].commands[]` (Skriptzeilen mitgezählt wären mehr).
Vorschlag für Task 2.5: **P** = Portable-Archiv (Orga lädt ein fertiges Archiv auf den Dateihost und
setzt `metadata.portable: true`), **D** = deklarative Allowlist (Abschnitt 3), **M** = manuell
(Anleitung im Spiele-Tab, kein Auto-Install), **X** = ausgeschlossen, **B** = Browser (`play_url`),
**A** = bereits abgedeckt (Pilot-/Engine-Pfad).

### Veröffentlicht

| Slug                                   | Titel                                   | Sup.                  | Befehle | Grund                                                                                                                                                                                                              | Vorschlag                                                                                                                                                                                                           |
| -------------------------------------- | --------------------------------------- | --------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `unreal-tournament-2004`               | Unreal Tournament 2004                  | ja (Pilot)            | 26      | Dedizierter Engine-Pfad: Base-Archiv, ISO (`libmkiso`), OldUnreal-Patch mit gepinntem SHA-256. Die Rezeptbefehle (`Mount-DiskImage`, `tar`, Registry für `ut2004://`) werden nicht ausgeführt, Rust übernimmt sie. | **A**. Registry-Schritt prüfen: entfällt mit `lanomat://join`, sonst `registry_set`. macOS-DMG erst mit dem macOS-Build (Phase 5).                                                                                  |
| `need-for-speed-underground-2`         | Need for Speed: Underground 2           | ja (`VERIFIED_SLUGS`) | 8       | Portable 1.2-Baum plus NFS-Online-Plugin, ASI-Loader und `net_u2.ini`. Der Generic-Engine-Pfad entpackt das Base-Archiv; die Konfigschritte sind Handarbeit gewesen.                                               | **A + D**: `write_file` für `net_u2.ini` und `copy` für die ASI-Dateien deklarieren, danach "von Hand verifiziert" durch einen Test ersetzen. Unter Linux läuft das Spiel in Wine: Plan nur Windows, Linux manuell. |
| `need-for-speed-underground-2-browser` | Need for Speed: Underground 2 (Browser) | ja (`play_url`)       | 0       | Nichts zu installieren, öffnet `https://nfsu2.xyz/` im Browser.                                                                                                                                                    | **B**. Keine Aktion.                                                                                                                                                                                                |
| `ridge-racer-type-4`                   | Ridge Racer Type 4                      | nein                  | 18      | Selbstgehosteter Browser-Automat: `git clone`, `npm`, `node`, `start-test.bat`; braucht Node.js 20 beim Spieler und ein Disc-Image (CHD/CUE) vom Nutzer.                                                           | **X** für den Spieler-PC. Wenn der Server den Automaten hostet: als **B** (`play_url`) ausliefern. Die optionale native Recomp ist ein eigenes **P**-Archiv.                                                        |

### Entwürfe (`draft`)

| Slug                                | Titel                                   | Sup. | Befehle | Grund                                                                                                             | Vorschlag                                                                                                           |
| ----------------------------------- | --------------------------------------- | ---- | ------- | ----------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `apocalypse`                        | Apocalypse                              | nein | 9       | DuckStation plus PS1-Image und BIOS; `mkdir`/`Copy-Item`/`Expand-Archive`. Image und BIOS liefert der Nutzer.     | **D** (Emulator-Archiv entpacken, Dateien kopieren, Start-Parameter als Launch-Target).                             |
| `crash-bandicoot`                   | Crash Bandicoot                         | nein | 9       | wie Apocalypse (DuckStation, PS1).                                                                                | **D**                                                                                                               |
| `crash-team-racing`                 | Crash Team Racing                       | nein | 9       | wie Apocalypse (DuckStation, PS1).                                                                                | **D**                                                                                                               |
| `dragon-ball-z-budokai-tenkaichi-3` | Dragon Ball Z: Budokai Tenkaichi 3      | nein | 9       | PCSX2 plus PS2-Image und BIOS.                                                                                    | **D**                                                                                                               |
| `shadow-of-the-colossus`            | Shadow of the Colossus                  | nein | 9       | PCSX2 plus PS2-Image und BIOS.                                                                                    | **D**                                                                                                               |
| `super-mario-galaxy`                | Super Mario Galaxy                      | nein | 5       | Dolphin plus Wii-Image.                                                                                           | **D**                                                                                                               |
| `super-mario-sunshine`              | Super Mario Sunshine                    | nein | 5       | Dolphin plus GameCube-Image.                                                                                      | **D**                                                                                                               |
| `zelda-wind-waker`                  | The Legend of Zelda: The Wind Waker     | nein | 5       | Dolphin plus Image, optional Texturpaket.                                                                         | **D**                                                                                                               |
| `zelda-majoras-mask-3d`             | The Legend of Zelda: Majora's Mask 3D   | nein | 6       | Azahar plus 3DS-Dump und optionale Schlüsseldatei.                                                                | **D**                                                                                                               |
| `zelda-ocarina-of-time-3d`          | The Legend of Zelda: Ocarina of Time 3D | nein | 6       | wie Majora's Mask 3D.                                                                                             | **D**                                                                                                               |
| `bloodborne`                        | Bloodborne                              | nein | 1       | shadPS4 plus PS4-Dump, optional Systemmodule. Nur `Expand-Archive`.                                               | **D**                                                                                                               |
| `god-of-war-iii`                    | God of War III                          | nein | 1       | RPCS3 plus PS3-Image und Firmware (`PS3UPDAT.PUP`). Nur `tar`.                                                    | **D**; die Firmware-Installation geschieht im Emulator, nicht im Client (Anleitungstext).                           |
| `god-of-war-iii-remastered`         | God of War III Remastered               | nein | 1       | wie God of War III (gleiche PS3-Disc).                                                                            | **D**                                                                                                               |
| `uncharted`                         | Uncharted: Drake's Fortune              | nein | 1       | wie God of War III (RPCS3).                                                                                       | **D**                                                                                                               |
| `f-zero-x-vr`                       | F-Zero X VR                             | nein | 2       | PCVR-Port plus N64-ROM, OpenXR-Laufzeit (SteamVR). SteamVR kann der Client nicht installieren.                    | **D** für den Port, SteamVR **M** (Hinweis im Tab).                                                                 |
| `pokemon-snap`                      | Pokémon Snap                            | nein | 4       | Snap64Recomp-Release plus ROM; entpacken und kopieren.                                                            | **D**                                                                                                               |
| `hookshot-standalone`               | Hookshot Standalone                     | nein | 1       | Fertiges Paket mit `hookshot.exe`, Spiel und Mods, verschachteltes ZIP (`$inner`).                                | **P** (Orga packt es flach) oder **D** mit `strip_components`.                                                      |
| `minecraft`                         | Minecraft: Java Edition                 | nein | 5       | Portable Prism Launcher plus LAN-Instanz; verschachteltes ZIP; Microsoft-Anmeldung muss der Nutzer selbst machen. | **D** für Entpacken und Instanz, Anmeldung **M**.                                                                   |
| `azeroth-survivors`                 | Azeroth Survivors                       | nein | 2       | Warcraft III (Reforged oder 1.29, extern/Kauf) plus Map; kopieren.                                                | **M** (Spiel ist Kaufware, kein Entpacken); die Karte allein ist **D** (`copy`).                                    |
| `counter-strike-1-6`                | Counter-Strike 1.6                      | nein | 7       | Rezept stößt Steam an (`steam://install/10`, `steam://rungameid/10`); Portable-Pfad mit `unzip` daneben.          | **M** für Steam ("Besitzt du es?", Store-Link, nur `steam://connect` bleibt erlaubt); der Portable-Pfad wäre **P**. |
| `hookshot`                          | Hookshot                                | nein | 1       | Startet einen heruntergeladenen Builder (`HookshotBuilder.exe`), der aus einem Nutzer-Dump kompiliert.            | **X** (fremde EXE ausführen, Kompilieren). Siehe `hookshot-standalone`.                                             |
| `mm3d-pc`                           | Majora's Mask 3D PC                     | nein | 3       | `Setup.sh` baut aus dem Nutzer-ROM mit `python3`; Bauen auf dem Spieler-PC.                                       | **X**; denkbar als fertiges **P**-Archiv, falls Rechte klar sind.                                                   |
| `uevr`                              | UEVR                                    | nein | 2       | Injektor, der in fremde Spielprozesse einhakt; Start per `Start-Process`.                                         | **X** (Spec Abschnitt 10: keine Injection, kein Anti-Cheat-Risiko).                                                 |

Zusammenfassung der 27 Zeilen: **A/B** 3 (`need-for-speed-underground-2` zusätzlich mit D), **D** 17,
**P** oder D 1 (`hookshot-standalone`), **M** 2 (`azeroth-survivors`, `counter-strike-1-6`),
**X** 4 (`ridge-racer-type-4` für den PC, `hookshot`, `mm3d-pc`, `uevr`).

## 5. Folgerung für Task 2.5 und offene Punkte

1. **Kein Shell-Runner** bleibt tragfähig: 20 der 26 Befehls-Rezepte (17 Emulator-/Port-Entwürfe,
   `hookshot-standalone`, NFSU2 und der UT2004-Pilot) brauchen nur Entpacken, Kopieren, Ordner und
   höchstens eine kleine Konfigdatei; die übrigen sechs (`ridge-racer-type-4`, `hookshot`,
   `mm3d-pc`, `uevr`, `counter-strike-1-6`, `azeroth-survivors`) laufen über Fremdprogramme, Steam
   oder Kaufware und bleiben manuell oder ausgeschlossen. Die Allowlist aus Abschnitt 3
   (`mkdir`, `extract`, `copy`, `write_file`) ist klein, prüfbar und testbar (Pfadprüfung zentral).
2. **Rezeptformat:** die Allowlist braucht ein deklaratives Schritt-Schema (Plan-Feld `actions[]`)
   neben den freien `commands:`; `commands:` bleiben Doku für Menschen und MCP. Das ist ein
   additives Feld (`plan_version` 1, Client ignoriert Unbekanntes).
3. **Launch-Targets fehlen** in den meisten Entwürfen (nur UT2004, NFSU2 und Minecraft haben
   `launch_targets`; 21 Rezepte haben den älteren `launch`-Block). `isPortable()` verlangt aber
   `launchTargets() !== []`. Vor Phase 2 muss geklärt werden, ob `launch` (der ältere Block) als
   Launch-Target gilt (`launchCommandFrom` liest beide Formen) oder die Rezepte migriert werden.
4. **Vom Nutzer gelieferte Dateien:** die meisten Emulator-Rezepte brauchen Spiel-Images, BIOS oder
   Firmware (`iso`/`base` ohne `url`). Ob der Dateihost solche Dateien führt, ist eine Betreiber-
   und Rechtsfrage und nicht Aufgabe des Clients; der Client behandelt sie wie jede LAN-Datei
   (SHA-256 Pflicht, signierter Link).
5. **Externe Downloads** (`url` gesetzt, meist GitHub-Releases der Emulatoren): Entscheidung 10
   greift, nur mit gepinntem SHA-256 und Opt-in. Heute tragen nur die UT2004-Patches und
   `NFSU2 Split Screen` einen `sha256`; alle Emulator-`tool`-Einträge haben keinen. Für **D** muss
   der Pin pro Emulator-Release nachgezogen werden (Orga-Arbeit, kein Code).
6. **Optionaler Ratchet (Pest):** Zahl der Befehls-Rezepte und der `launcher_supported`-Rezepte im
   Repo festhalten, damit sich die Inventur nicht unbemerkt ändert. Gehört zum Server-Agenten.
