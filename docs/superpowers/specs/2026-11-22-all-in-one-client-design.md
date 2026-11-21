# All-in-one-Client — Design

Status: Entwurf zur Entscheidung durch den Betreiber, 2026-11-22. Reine Planung, kein Produktivcode.
Umsetzungsplan: [`../plans/2026-11-22-all-in-one-client.md`](../plans/2026-11-22-all-in-one-client.md).

Begriffe und Code bleiben englisch, Fließtext ist deutsch. Alle Hosts, Adressen und Schlüssel sind
Platzhalter (`<app>`, `<file-host>`).

## 1. Ziel

Teilnehmer sollen **eine** Desktop-App installieren: Voice, Chat, Spiele-Bibliothek mit
Installation, Server beitreten (One-Click), Event-Infos. Heute gibt es dafür vier getrennte Dinge
mit getrennter Anmeldung. Langfristig soll "Renes Launcher" in den LANoMAT-Client aufgehen.

## 2. Korrektur der Ausgangslage: es gibt einen fünften Baustein

Die Aufgabenstellung nannte drei Komponenten (Web-Launcher, Node-Agent, native Voice-App). Die
Bestandsaufnahme zeigt eine **vierte Client-Komponente**, die den Kern der Frage verändert:

- **`clients/launcher`** ist Renes native Launcher-App: ein Tauri-v2-Workspace mit
  `launcher-core` (rund 5.700 Zeilen Rust: `api`, `auth`, `state`, `types`, `download`, `archive`,
  `install`), der App-Schale (rund 3.350 Zeilen: `lib`, `deeplink`, `heartbeat`, `arcade`,
  `installs`, `env`, `errors`) und einem statischen HTML/CSS/JS-Frontend (rund 4.800 Zeilen).
  Sie liefert bereits: Geräte-Pairing, Rezeptkatalog, Install-Plan, fortsetzbare Downloads mit
  SHA-256, Entpacken von ZIP, tar.bz2 und ISO (`libmkiso`) mit Pfadprüfung, Plan-Verifikation,
  Start, `lanomat://install|launch|join`, Arcade-/Kiosk-Modus samt Flotten-Heartbeat und
  macOS-Build. Der Wellenplan `2026-11-21-ut2004-launcher-waves.md` hält die Entscheidung
  "Install-Engine ist Rust, kein gebündelter Node-Sidecar" bereits fest.

Folgerungen für dieses Dokument:

1. Die Frage "Launcher-Funktionen in Rust neu bauen oder den Node-Agenten mitliefern" ist zu
   einem großen Teil **schon entschieden und umgesetzt**: Rust, in `launcher-core`.
2. Variante A heißt deshalb nicht "neu bauen", sondern **"`launcher-core` in den Voice-Workspace
   zusammenführen"**. Variante B (Node-Sidecar) widerspricht der dokumentierten Entscheidung.
3. Die Phasen 0 bis 2 des Plans sind Konsolidierung (zwei Rust-Workspaces, zwei Tokens, zwei
   Download-Engines, zwei UIs), keine Grünfeld-Spikes.

## 3. Ist-Analyse

### 3.1 Die Bausteine

| Baustein                                                               | Technik                                                                                | Zielgruppe                                                       | Auth                                                                            | API                                      |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------- | ---------------------------------------- |
| Web-Launcher `/launcher`, `/app`, `/talk/client`, `/games`, `/servers` | Laravel/Inertia, Modul `GameRecipes`, `Events` (Start-Chat)                            | alle Teilnehmer im Browser                                       | Session (Discord)                                                               | web                                      |
| Node-Agent `clients/agent` (`lanomat-agent`)                           | Node 22+, TypeScript, commander, pi-SDK, MCP-SDK, rund 6.900 Zeilen                    | Nerds, Orga, Vibe-Coder; "installiere UT2004 per Chat"           | MCP-Token `lmcp_` (Device-Flow `api/mcp/device`)                                | MCP `/mcp` und REST `/api/v1`            |
| **Rust-Launcher `clients/launcher`**                                   | Rust, Tauri v2, Identifier `app.lanomat.launcher`, Rust 1.96                           | Spieler, Arcade-Terminals                                        | **eigener** MCP-Token `lmcp_` (selber Device-Flow, eigener Token)               | `/api/v1`                                |
| Native Voice-App `clients/voice`                                       | Rust, Tauri v2, Identifier `app.lanomat.voice`, Image Rust 1.91 + clang 21 (libwebrtc) | Teilnehmer: Voice, Chat, "Wo ist wer", Dateien, Turniere, Server | Voice-Token `lvc_` (Device-Flow `api/voice-client/device`, Sliding-TTL 90 Tage) | `/api/voice-client/v1` (`api_version` 1) |

Der Web-Launcher selbst ist **rein serverseitig** und enthält keine Installationslogik: die Seiten
`/launcher`, `/games`, `/servers`, der deterministische Start-Chat (`HomeChatData`, ruft nie ein
LLM) und die `lanomat://`-Links.

### 3.2 Was jede Komponente kann

**Server (wiederverwendbar, nichts davon liegt in einem Client):**

- Rezepte: `resources/recipes/*` (rund 30 Einträge, Schema `RecipeValidator::SCHEMA`), `SyncRecipes`,
  `RecipeParser`, `RecipeValidator`, Katalog `_catalog.md`, Teilen/Veröffentlichen (`ShareGame`,
  `PublishRecipe`, `ProposeRecipe`, Pull-Request-Pipeline).
- `BuildInstallPlan`: macht aus Rezept + Betriebssystem einen deterministischen Plan (Dateien mit
  LAN-Match, `download_url` auf `mcp.files.download`, `fallback_url` extern, `missing`, `steps`,
  `launch`, `avatars`, `downloads_dir`). Der Server führt nichts aus.
- `LauncherSupport`: entscheidet an **einer** Stelle `launcher_supported` (UT2004-Pilot,
  verifizierte Slugs, Browser-Rezepte mit `play_url`, `metadata.portable: true` mit Basisarchiv,
  Datei-Check und Startziel, oder Override). **Der Launcher führt nie Schritt-Befehle aus.**
- `LauncherLineup`, `LauncherJoinLink`, `RecipeInstallLinks`: Spieleliste, `lanomat://join/<slug>?address=…`
  und die Installationslinks auf jeder Serverzeile.
- `LauncherBuilds` + `LauncherPageController`: Manifest-validierte Downloads pro OS (`clients-dist/launcher/manifest.json`).
- `GamePageController`, `GameShareController`: Spieleseite, "Spiel teilen".
- `TerminalController`/`RecordTerminalHeartbeat`: Arcade-Flotte (`POST /api/v1/terminals/heartbeat`).
- Dateihost: signierte Download-Links, tus-Upload, Zertifikats-Sync (`docs/infra/file-host.md`).

**Node-Agent (Größen in Zeilen, `clients/agent/src`):**

| Modul                                                                                              | Zeilen                                      | Zweck                                                                               | Status in Rust                                                                                |
| -------------------------------------------------------------------------------------------------- | ------------------------------------------- | ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `install.ts`                                                                                       | 343                                         | Plan holen, Downloads, Avatare, Schritte ausführen                                  | in `launcher-core/install.rs` (1.682) für Archiv/ISO-Pfad vorhanden                           |
| `download.ts`                                                                                      | 389                                         | Range-Resume, SHA-256, Timeout, parallel                                            | in `launcher-core/download.rs` (753) vorhanden; zusätzlich `voice-core/transfer/*`            |
| `launch.ts`                                                                                        | 232                                         | Startbefehl aus Rezept, Shell-Quoting, macOS-Bundles                                | in `install.rs` (`launch*`) vorhanden                                                         |
| `recipes.ts`                                                                                       | 229                                         | Plan formatieren, Downloads sammeln                                                 | `types.rs` (711) + UI                                                                         |
| `avatars.ts`                                                                                       | 347                                         | Avatar-Auswahl und -Downloads                                                       | `PlanAvatar` in `types.rs`, Download über die generische Engine; Auswahl-UI offen (Plan T2.3) |
| `deeplink.ts`, `register.ts`                                                                       | 62, 186                                     | `lanomat://` parsen und registrieren                                                | `app/deeplink.rs` (384) + Tauri-Plugin                                                        |
| `runner.ts`                                                                                        | 626                                         | **führt Rezept-Schritte als PowerShell/cmd/bash aus**, Timeout, Prozessbaum, Verify | **bewusst nicht portiert**                                                                    |
| `skills.ts`                                                                                        | 166                                         | Rezept-Skills für pi synchronisieren                                                | nur für den LLM-Pfad, nicht nötig                                                             |
| `models.ts`, `ask.ts`, `mcp.ts`, `ui.ts`, `doctor.ts`, `config.ts`, `cli.ts`, `auth.ts`, `http.ts` | 691, 241, 157, 264, 642, 549, 875, 134, 295 | pi/Venice-Modellschicht, MCP-Verdrahtung, CLI                                       | LLM-spezifisch bzw. durch die Tauri-UI ersetzt                                                |

Der **einzige echte Funktionsunterschied** zwischen Agent und Rust-Launcher ist `runner.ts`: der
Agent installiert auch Rezepte, die Befehle brauchen (Installer-EXEs, Winetricks, Patches per
Skript), der Rust-Launcher nur deklarativ (Archiv entpacken, Dateien prüfen, Startziel). Das ist
eine Sicherheitsentscheidung des Servers (`LauncherSupport`), kein Versäumnis.

Ein Port von `runner.ts` nach Rust wäre technisch rund 500 bis 700 Zeilen (Shell-Auswahl,
Quoting, Prozessbaum töten, Timeouts, Verify-Checks), **wird aber nicht empfohlen** (Abschnitt 6).
Ein Port aller restlichen Agent-Funktionen ist nicht nötig, weil `launcher-core` sie schon
abdeckt.

**Rust-Launcher:** siehe 2. Dazu die Oberfläche: Bibliothek, Install-Fenster mit Prozent/Tempo/ETA,
"Zuletzt gespielt", "Jetzt live"-Liste mit Beitreten, Chat-Fenster (802 Zeilen JS, deterministisch,
`HomeChatData`-nah, kein LLM), "Spiel teilen" (öffnet die Webseite), QR-Anmeldung für Arcade-Terminals,
Fußzeile mit "Neue Launcher-Version verfügbar". Die UI folgt der "UT2004/Epic"-Markenwelt, **nicht**
dem Signalpult-Designsystem (`docs/design.md`).

**Voice-App:** fünf Teilnehmer-Tabs, LiveKit-Voice mit globalem PTT (auch Wayland), Chat,
Benachrichtigungen, "Wo ist wer" mit Follow, Dateien mit tus-Upload und Download (signierte Links,
`voice-core/transfer`: `.part`, Range-Resume, Retry, atomares Umbenennen), Turniere
(Check-in/Report), Server-Liste mit `steam://connect`-Beitritt (`app/joinurl.rs` mit strenger
Validierung), Freunde, Profil, Verbindungen, Auto-Update-Hinweis, Release über `release-client.yml`
(Windows NSIS + portable, unsigniert, Linux-Tarball, kein macOS).

### 3.3 Schnittstellen: was es für Rezepte und Dateien wirklich gibt

**Unter `/api/voice-client/v1` existiert keine Rezept- und keine Plan-Schnittstelle.** Vorhanden
sind: `me`, `config`, `rooms`, `events`, `now`, `tournaments`, `my-matches`, `servers` (Event und
permanent), `events/{event}/files` (+ `quota`, `uploads/ticket`), `files/{sharedFile}/download`
(liefert eine signierte Dateihost-URL), `polls`, `catering`, `people`, `friends`, `profile`,
`connections`, `notifications`, `avatars/{user}/{hash}` (das sind Nutzer-Avatare, nicht VRM-Modelle).

Unter `/api/v1` (MCP-REST, `AuthenticateMcpToken`, Throttle `mcp-api`): `me`, `avatars`, `recipes`
(Liste, `POST` Beitrag), `recipes/{slug}`, `recipes/{slug}/skill`, `recipes/{slug}/plan`,
`recipes/{slug}/files`, `recipes/{slug}/avatars`, `files`, `servers`, `terminals` (+ `heartbeat`) und
`files/{sharedFile}/download` (Route `mcp.files.download`, vom Plan-Builder als absolute URL
ausgeteilt). Dazu rund 30 MCP-Tools (u. a. `recipes_list`, `recipe_get`, `recipe_install_plan`,
`recipe_avatars`, `file_download_url`, `servers_list`).

**Harte Randbedingung:** ein Voice-Token (`lvc_`) kann heute weder einen Plan holen noch eine
Plan-Datei laden. `BuildInstallPlan` verdrahtet `route('mcp.files.download')` hinter
`AuthenticateMcpToken`. Jede Lösung muss diese Auth-Lücke schließen (Abschnitt 7).

### 3.4 Doppelungen

| Thema                          | Doppelt in                                                                                                                                                                      | Folge                                                                                |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| Geräte-Pairing                 | `api/mcp/*` (Agent + Launcher) und `api/voice-client/*` (Voice)                                                                                                                 | Nutzer koppeln zwei- bis dreimal; drei Token-Arten in "Verbundene Geräte"            |
| Token-Speicher                 | `~/.config/lanomat/agent.json`, `lanomat-launcher/config.json`, Voice-Credential-Store                                                                                          | drei Orte, drei Löschwege                                                            |
| Download-Engine                | `launcher-core/download.rs` (Range, SHA-256, Ursprungs-gebundener Bearer, `download_many`, Cancel) und `voice-core/transfer` (Manager-Queue, Fortschritt, tus, signierte Links) | zwei Resume-Implementierungen, nur eine mit Hash-Prüfung                             |
| Deep-Link und Join-Validierung | `launcher/app/deeplink.rs` und `voice/app/joinurl.rs` (`steam://connect`)                                                                                                       | zwei Parser, je eigene Sicherheitsregeln                                             |
| Update-Hinweis                 | beide Apps vergleichen mit der Server-Manifest-Seite                                                                                                                            | zwei Manifeste (`clients-dist/launcher` und Client-Manifest), zwei Release-Workflows |
| Toolchain                      | Voice-Image Rust 1.91 + clang 21; Launcher-Image Rust 1.96; `rust-version` 1.85 vs. 1.96                                                                                        | ein Workspace braucht eine Version, die beides baut                                  |
| Oberfläche                     | Launcher "Epic-Welt", Voice (Signalpult-nah); jeweils statisches JS ohne Framework                                                                                              | Zusammenführen heißt Neuordnung der Navigation                                       |
| Chat                           | Web-Start-Chat (`HomeChatData`), Launcher-Chat (`chat.js`), Agent-Chat (pi + Venice), Assistent-Modul (laravel/ai, nur Orga)                                                    | vier Chat-Stile, nur der Agent nutzt ein LLM beim Spieler                            |
| Arcade/Kiosk                   | nur im Launcher (`arcade.rs`, `heartbeat.rs`, QR-Login, `install-terminal.*`)                                                                                                   | muss beim Zusammenführen erhalten bleiben                                            |
| macOS                          | nur der Launcher baut `.app/.dmg`; die Voice-App nicht (libwebrtc, PTT-Rechte)                                                                                                  | Zusammenführen darf macOS-Nutzer nicht verlieren                                     |

### 3.5 Was Rene gebaut hat und wiederverwendet wird

Rene (Git-Autor Reneil, rund 250 Commits seit 2026-11-15) hat im Wesentlichen: Rezeptkatalog und
`LauncherSupport`, Install-Plan, Dateihost-Anbindung, Lineup, Join-Link, Spiel-teilen-Pipeline,
Rust-Launcher mit Arcade-Flotte, Start-Chat und die Seiten `/games`, `/servers`, `/launcher`.
**Alles davon bleibt.** Es ändert sich nur der Ort, an dem die Client-Hälfte läuft.

## 4. Zielbild

### 4.1 Navigation

Eine App "LANoMAT" (Arbeitstitel; heute "LANoMAT Voice" bzw. "LANoMAT Launcher"):

| Tab    | Inhalt                                                                                            | Herkunft                                |
| ------ | ------------------------------------------------------------------------------------------------- | --------------------------------------- |
| Jetzt  | Event-Feed, nächste Termine, Hinweise, Chat-Eingang (deterministisch)                             | Voice `now` + Launcher-Start            |
| Voice  | Räume, PTT, Chat, Overlay                                                                         | Voice (unverändert)                     |
| Spiele | Bibliothek, Verfügbarkeit, Installationsstatus, Installieren/Reparieren/Starten, Zuletzt gespielt | **neu aus Launcher**                    |
| Server | Laufende Server, One-Click-Beitritt, direkte Join-Wege                                            | Voice `servers` + Launcher "Jetzt live" |
| Leute  | "Wo ist wer", Freunde                                                                             | Voice                                   |
| Mehr   | Turniere/Check-in, Dateien, Profil, Verbindungen, Einstellungen, Geräte                           | Voice                                   |

Die fünf bisherigen Voice-Tabs bleiben bestehen; "Spiele" ist ein sechster Tab oder ersetzt einen
nach Sichtbarkeitsregel (`config.modules`: wenn `game_recipes` aus ist, verschwindet der Tab, wie
alle optionalen Module). Der Arcade-Modus ist ein **Laufzeitschalter** (`--arcade`/`--kiosk`), kein
eigenes Produkt.

### 4.2 Verantwortlichkeiten

| Ebene                             | Verantwortung                                                                                                                      |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Server (Laravel)                  | Rezepte, Plan, Dateihost-Zuordnung, Policies, Lineup, Join-Links, Manifest, Version. Entscheidet, **was** installiert werden darf. |
| `voice-core`                      | Auth, Netz, Transfer-Manager, Realtime, LiveKit.                                                                                   |
| `launcher-core` (zusammengeführt) | Plan-Typen, Archiv-Entpacken, Plan-Verifikation, Install-Orchestrierung, Start. Keine Shell-Ausführung.                            |
| App-Schale (Tauri)                | Fenster, Tray, Deep-Links, Single-Instance, Kiosk, UI.                                                                             |

## 5. Architekturentscheidung

### 5.1 Varianten

**A. Launcher-Funktionen im Rust-Kern, Tauri-UI zusammenführen** (Download, Installation, Start,
Verwaltung lokal in Rust; Rezepte vom Server; Deep-Links; Dateihost-Downloads mit Fortschritt und
Wiederaufnahme). Konkret: `launcher-core` zieht in den Voice-Workspace, die UI wird ein Tab.

- Pro: ein Stack (Rust + statisches Web-UI) und eine Signatur/Update-Kette; deterministisch,
  ohne Interpreter beim Spieler; kleiner (kein Node, kein pi); LAN-first; Windows-Fokus passt
  (WebView2 ist ohnehin da); der Code existiert und hat Sicherheitstests (`security_paths`,
  `archive_symlink`, `download_security`, `tar_symlink_launch`).
- Contra: Zusammenführen kostet Arbeit (Toolchain, Auth, UI); Rezepte mit Befehlen bleiben
  ausgeschlossen; die Voice-App wird größer (libwebrtc ist schon der Löwenanteil).

**B. Node-Agent als Sidecar in der Tauri-App** (pi bleibt).

- Pro: alle Rezepte installierbar (inklusive `runner.ts`), KI-Chat wie heute.
- Contra: Node-Runtime oder SEA-Binary (rund 60 bis 100 MB pro Plattform) im Installer; zweiter
  Stack und zweites Update-Verfahren; ein LLM entscheidet über Installationen auf dem Spieler-PC;
  Rezept-Befehle laufen mit Nutzerrechten als Shell (Angriffsfläche); widerspricht dem Wellenplan
  und dem Projektgrundsatz "Sidecars sind reiner Transport"; Venice-Schlüssel und Provider-Bindung
  auf dem Client.

**C. Hybrid:** Rust übernimmt Download/Installation/Start (wie A), der KI-Chat läuft **serverseitig**
über `laravel/ai`/den Assistenten (Spieler-Werkzeuge später über dasselbe Registry), der Node-Agent
entfällt langfristig.

- Pro: C ist A plus eine klare Chat-Aussage; Schlüssel und Modellwahl bleiben auf dem Server
  (Ollama/Claude/OpenAI konfigurierbar, offline-fähig), Policies gelten direkt, kein Prompt-Injection-
  Pfad zur lokalen Ausführung: das Modell kann nur Server-Werkzeuge rufen, die lokale Installation
  startet ausschließlich ein Klick in der App.
- Contra: Spieler-Chat braucht ein eigenes Werkzeug-Set und Kostenkontrolle (Assistent v1 ist
  orga-only); bis dahin bleibt der deterministische Chat.

### 5.2 Empfehlung

**C, umgesetzt als "A in Phasen"**: `launcher-core` in den Voice-Workspace zusammenführen (Phasen 0
bis 3), Auth und Auslieferung vereinheitlichen und Web-Launcher/Agent ablösen (Phase 4), danach
Signierung, Auto-Update und macOS (Phase 5). Der Spieler-Chat bleibt bis zu einer eigenen Spezifikation
deterministisch.

Begründung entlang der Kriterien:

| Kriterium                     | A/C                                                          | B                                           |
| ----------------------------- | ------------------------------------------------------------ | ------------------------------------------- |
| Größe                         | kleiner Zuwachs (Rust-Code, keine Runtime)                   | +60–100 MB, zweite Runtime                  |
| Sicherheit lokaler Ausführung | deklarativ, kein Shell, Pfad- und Hash-Prüfung               | Rezept-Shell mit Nutzerrechten, LLM im Loop |
| Wartung eines Stacks          | Rust + statische UI                                          | Rust + Node + pi + Venice                   |
| Offline/LAN-first             | ja, alles vom Dateihost                                      | Agent braucht Modellzugang                  |
| Windows-Fokus                 | NSIS-Pipeline vorhanden                                      | Sidecar-Packaging je OS                     |
| Signierung                    | ein Binary-Satz                                              | Sidecar zusätzlich signieren                |
| Auto-Update                   | ein Updater                                                  | zwei                                        |
| Lizenz                        | beide Workspaces MIT; Abhängigkeiten per `cargo-deny` prüfen | zusätzlich npm-Lizenzbaum (pi, MCP-SDK)     |

Der Node-Agent bleibt **Entwickler- und Orga-Werkzeug** (MCP, Rezept-Beiträge, Vibe-Coding), nicht
Spieler-Weg. Seine Spieler-Rolle (`install`, `launch`, `register`) entfällt, sobald Phase 3 trägt.

### 5.3 Warum `runner.ts` nicht nach Rust portiert wird

Die Server-Entscheidung "Rezepte mit Befehlen sind kein Launcher-Fall" ist die wichtigste
Sicherheitsgrenze des Gesamtsystems. Sie würde durch einen Rust-Shell-Runner aufgeweicht. Der
Weg für Rezepte, die heute Befehle brauchen:

1. **Portable-Archiv bauen** (Spiel teilen, `PortableRecipeBuilder`): der Orga packt einmal, alle
   Spieler entpacken nur.
2. **Deklarative Schritt-Typen** (Allowlist, kein Shell): `extract`, `copy`, `write_file`
   (Konfiguration aus Vorlage), `registry_set` (nur HKCU, nur Allowlist-Schlüssel), `shortcut`.
   Server-Validator und Client bekommen dieselbe Liste.
3. **Manuell** bleibt eine erlaubte Form (`manual`-Datei/Schritt): die App zeigt die Anleitung.

Installer-EXEs mit UAC, Steam-/Epic-/EA-Abhängigkeiten und Anti-Cheat-Spiele bleiben ausgeschlossen.

## 6. Datenfluss

```text
Rezept (resources/recipes, DB: GameRecipe)
  -> GET plan (Server: BuildInstallPlan, Policies, Lineup, LauncherSupport)
  -> Download vom LAN-Dateihost (signierter Link, Range-Resume, .part)
  -> Prüfsumme (SHA-256 aus Rezept-Pin oder gespeichertem Dateihash; LAN-Quellen: Pflicht)
  -> Entpacken (ZIP, tar.bz2, ISO; Pfad-/Symlink-Regeln) bzw. deklarativer Schritt
  -> Plan-Verifikation (Datei-/Ordner-Checks aus dem Plan)
  -> Registrierung im lokalen Installationsverzeichnis (installs.json: slug, Pfad, Version/Hash, Startbefehl)
  -> Start oder Join-Link (lanomat://join/<slug>?address=host:port  oder  steam://connect/...)
  -> Server-Join (Spiel startet mit Adresse; Voice-Raum des Matches optional)
```

Die App meldet nie unverlangt, **welche** Spiele installiert sind (Abschnitt 9).

## 7. Auth und Token

Ist: drei Token-Arten. `lmcp_` (MCP/REST, Agent, Launcher, Arcade-Terminals) und `lvc_` (Voice).
Der Voice-Token ist absichtlich auf `/api/voice-client/*` begrenzt (`AuthenticateVoiceClient` setzt
nur den Request-User-Resolver, nie den Session-Guard).

Optionen:

1. **MCP-Token in der All-in-one-App zusätzlich koppeln.** Einfach, aber zweite Kopplung, zwei
   Speicherorte, und der breite MCP-Token (Rolle des Nutzers über alle Tools) auf dem Spieler-PC.
2. **Voice-Token auf Rezepte und Dateien erweitern (empfohlen).** Neue schmale Routen unter
   `/api/voice-client/v1/recipes…` rufen dieselben Actions/Policies; Plan-URLs werden für diesen
   Aufrufer als **signierte Dateihost-Links** erzeugt (wie `files/{sharedFile}/download` heute),
   statt `mcp.files.download`. Ein Pairing, ein Token, kleinerer Berechtigungsumfang.
3. **Token zusammenlegen** (ein Typ mit Scopes): sauberste Endzustand, aber ein Eingriff in zwei
   Auth-Middlewares und das Geräte-UI; `McpToken.abilities` ist reserviert, aber nirgends erzwungen.
   Frühestens nach Phase 4 sinnvoll.

Empfehlung: **Option 2 jetzt, Option 3 später prüfen.** Arcade-Terminals behalten zunächst den
MCP-Token (Heartbeat bleibt `/api/v1/terminals/heartbeat`), weil dort die Orga-Zuordnung zählt.

Folgen:

- Jede neue Route `api/voice-client/v1/...` braucht einen Eintrag in
  `tests/Feature/Security/RouteAuthorizationMatrixTest.php` (Grund oder IDOR-Szenario).
- `BuildInstallPlan` bekommt eine Download-URL-Strategie (Aufrufer-Typ), statt die Route
  fest zu verdrahten; der MCP-Pfad bleibt unverändert.
- Gerätekopplung: der bestehende Device-Flow der Voice-App wird die einzige Anmeldung der
  All-in-one-App. Die Verbundene-Geräte-Liste unterscheidet weiter "App" und "Terminal".

## 8. Versionierung und Kompatibilität

- **`api_version`** (heute 1): neue Rezept-Routen sind **additiv**. Präzedenzfall in der API-Doku:
  additive Felder lassen `api_version` auf 1. Der Client erkennt die Fähigkeit über `GET config`
  (neues Feld `features.launcher`, ganze Zahl) und blendet den Spiele-Tab aus, wenn es fehlt.
  `API_VERSION` wird erst bei einem **brechenden** Plan-Format angehoben; dann gilt wie heute
  "Download nur in der Server-Version" (`/app`, Manifest-Vergleich).
- **Rezept-Schema-Version:** das Rezept trägt `schema` (Konstante `RecipeValidator::SCHEMA`). Der
  **Plan** trägt heute keine eigene Version; Phase 1 fügt `plan_version` hinzu. Der Client lehnt
  einen höheren, unbekannten Plan-Major ab ("Bitte App aktualisieren") und ignoriert unbekannte
  Felder. Heutiges Verhalten bleibt: `installable`/`launcher_supported` `None` heißt "älterer
  Server", nicht "nicht unterstützt".
- **Mindestversion:** `min_client_version` (`VOICE_CLIENT_MIN_VERSION`) gilt weiter.
- **Zwei Manifeste werden eines:** `clients-dist/launcher/manifest.json` (Validator `LauncherBuilds`)
  und das Client-Manifest verschmelzen in Phase 4; bis dahin liefert der Server beide.

## 9. Lizenz und Datenschutz

- Beide Workspaces sind MIT. Vor dem Zusammenführen `cargo-deny` (Lizenz-Allowlist) laufen lassen;
  `libmkiso`, `bzip2`, `zip`, `tar`, `sha2` und die LiveKit-Crates auf Kompatibilität prüfen.
  Die Spiele-Dateien selbst bleiben Sache des Betreibers (Eigenkopien/Abandonware auf dem LAN-Host);
  die App bündelt keine Spielinhalte.
- **Lokale Installationsliste** bleibt auf dem Gerät. Der Server sieht heute nur `GameRecipeInstall`
  (wer hat welchen Plan angefordert, für den Orga-Support) und die Terminal-Heartbeats.
- **"Aktuelles Spiel"** (für "Wo ist wer" oder Freunde) nur als eigener Schalter, Standard aus,
  neben dem bestehenden `share_location`-Opt-out; nie in Beamer/Broadcast ohne Opt-in.
- Token nie in Logs, UI oder Fehlertexten (gilt für beide Apps, Tests vorhanden).
- Externe Downloads (kein LAN-Treffer) nur mit gepinntem SHA-256 und ohne den LANoMAT-Bearer.

## 10. Risiken

| Risiko                                                       | Gegenmaßnahme                                                                                                                                                       |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Heruntergeladene Installer/EXEs ausführen                    | Keine Installer-Ausführung mit UAC; nur Start der vom Plan genannten Spiel-EXE im Installationsordner; kein Shell. Optionaler Defender-/SmartScreen-Hinweis.        |
| Rechte                                                       | Immer Benutzerrechte (`C:\Games\<slug>` bzw. `~/Games/<slug>`), nie Admin, nie UAC-Prompt.                                                                          |
| Pfad-Traversal / Zip-Slip                                    | Zentrale Pfadprüfung (absolut, `..`, Laufwerk, Symlink, Hardlink), Tests je Format, Fuzz in Phase 2.                                                                |
| Prüfsummen                                                   | LAN-Quellen: SHA-256 Pflicht (Rezept-Pin sonst gespeicherter Hash); fehlt der Hash, kein Installieren ohne Orga-Freigabe.                                           |
| Signaturen                                                   | Windows heute unsigniert (SmartScreen). Auto-Update braucht trotzdem eine eigene Update-Signatur (minisign, Tauri-Updater), unabhängig vom Authenticode-Zertifikat. |
| UAC                                                          | Nie auslösen; Rezepte mit UAC-Bedarf gelten als nicht launcherfähig.                                                                                                |
| Steam/Epic/EA                                                | Nicht automatisieren. Erlaubt bleibt nur `steam://connect/…` (strikt validiert). Der Spiele-Tab zeigt "Besitzt du es?" und verlinkt den Store.                      |
| Anti-Cheat-Spiele                                            | Nicht ins Lineup (`launcher_supported` aus). Keine Dateimanipulation oder Injection (der geplante In-Game-Hug-Bridge-Gedanke bleibt außerhalb).                     |
| Deep-Link-Missbrauch (`lanomat://` aus beliebigen Webseiten) | Strenger Parser, nur `install                                                                                                                                       | launch | join`, Slug-Allowlist, Bestätigungsdialog vor Installation, Join nur mit validierter Adresse. |
| Token auf dem Spieler-PC                                     | Option 2 (schmaler Voice-Token), 0600, widerrufbar unter "Verbundene Geräte".                                                                                       |
| Größerer Angriffsradius der Voice-App                        | Capabilities je Fenster minimal halten (heute `core:default`, `deep-link:default`), CSP unverändert streng.                                                         |
| Zusammengeführtes Build bricht Windows/Linux-Release         | Gleicher Docker-Build und `windows-smoke.ps1` als Gate.                                                                                                             |

## 11. Migration

1. **Bis zur nächsten LAN nichts ausrollen:** beide Apps laufen unverändert; die Konsolidierung
   passiert in Feature-Branches.
2. **Phase 1 bis 3:** die All-in-one-App entsteht als Voice-App mit Spiele-Tab, parallel zum
   bestehenden Launcher. Der Web-Launcher verweist weiter auf `lanomat://`; die App
   registriert dasselbe Schema, daher gewinnt, was zuletzt installiert/registriert wurde. Das
   wird in Phase 3 mit einer klaren Regel (App-Vorrang, alter Launcher zeigt Hinweis) aufgelöst.
3. **Phase 4:** `/launcher` wird eine Weiterleitung auf `/app`. Der Launcher-Release-Workflow
   geht im Client-Workflow auf. Beim ersten Start importiert die App die Installationsliste des alten
   Launchers (`installs.json`), den Token **nicht** (anderer Typ, neu koppeln über den Device-Flow).
   Der alte Launcher-Token bleibt einige Releases gültig (Deprecation-Hinweis im UI).
4. **Node-Agent:** wird aus den Spieler-Dokumenten entfernt, bleibt als Orga-/Dev-Werkzeug in
   `clients/agent`; sein `install`/`launch`/`register` zeigt einen Hinweis auf die App. Löschung
   erst nach einer LAN ohne Agent-Nutzung.
5. **Arcade-Terminals:** `--arcade` und der Heartbeat ziehen in die App um (Phase 4); bis dahin
   bleiben die Terminals auf dem alten Launcher.

## 12. Offene Fragen an den Betreiber

Jede Frage mit Empfehlung. Standardwerte gelten, bis entschieden wird.

1. **Variante:** C auf Basis von A (Rust zusammenführen, Chat serverseitig). Empfehlung: ja.
2. **Name und Marke:** eine App "LANoMAT"; Oberfläche **im Signalpult-Designsystem**
   (`docs/design.md`), nicht in der UT2004/Epic-Welt (die darf als Spiele-Seitenstil weiterleben).
   Empfehlung: Signalpult, App-Name "LANoMAT".
3. **Token:** Voice-Token auf Rezepte erweitern; Terminals bleiben vorerst beim MCP-Token.
   Empfehlung: Option 2.
4. **Rezepte mit Befehlen:** kein Shell-Runner; Portable-Archiv, deklarative Schritt-Allowlist oder
   manuelle Anleitung. Empfehlung: ja, ohne `runner.ts`-Port.
5. **Chat für Spieler mit LLM:** nicht in v1 der App; eigene Spec nach dem Assistenten (Spieler-Tools,
   Kostenlimit). Empfehlung: deterministisch bleiben.
6. **Node-Agent:** als Dev-/Orga-Werkzeug behalten, aus Spielerdokumenten entfernen, Löschung nach
   einer LAN ohne Nutzung. Empfehlung: so.
7. **Arcade/Kiosk und Flotte:** erhalten, als Laufzeitschalter. Empfehlung: ja, Phase 4.
8. **macOS:** Zusammengeführter Build mit Cargo-Feature `voice` aus (nur Spiele/Server/Infos) auf
   `macos-14`, Voice für macOS später. Empfehlung: ja, Phase 5, kein Versprechen für Voice.
9. **Signierung:** Authenticode vor der nächsten großen LAN (SignPath-Open-Source-Programm oder
   Azure Trusted Signing prüfen), plus minisign für den Updater. Empfehlung: ja, Entscheidung zu
   Anbieter und Kosten durch den Betreiber.
10. **Externe Downloads:** nur mit gepinntem SHA-256 und Opt-in. Empfehlung: so.
11. **Steam/Epic:** keine Automatisierung; nur `steam://connect`. Empfehlung: so.
12. **"Aktuelles Spiel" teilen:** eigener Opt-in-Schalter, Standard aus. Empfehlung: so.
13. **Update-Verfahren:** Tauri-Updater mit Manifest vom eigenen Server (LAN-first), nur gleiche
    `api_version`. Empfehlung: ja, Phase 5.
14. **Zeitpunkt:** Phasen 0 und 1 nach der nächsten LAN; Phase 3 ist erst dann live, wenn eine
    Echtvenue-Abnahme (`docs/pre-lan-acceptance-checklist.md`) vorliegt. Empfehlung: so.

## 13. Betriebsregeln (aus CLAUDE.md, verbindlich für die Umsetzung)

- Rust nur im Docker bauen (`clients/voice/Dockerfile.dev`, Targets `core`, `app`, `windows`);
  nie Rust auf dem Host installieren oder verlangen.
- Jede Native-Client-Route (`api/voice-client/...`) in die Routen-Matrix
  (`RouteAuthorizationMatrixTest`) mit Grund oder IDOR-Szenario.
- `api_version` und Kompatibilität gemäß Abschnitt 8; Download nur in der Server-Version.
- Deploy baut alle vier Dienste mit `APP_VERSION`/`APP_COMMIT` (`scripts/deploy/deploy.sh`).
- Neue UI: `frontend-design`-Skill und `docs/design.md`; neue Seiten in `pageLayouts.ts`.
- Public-safe: keine echten Hosts, IPs oder Schlüssel in Dateien.
