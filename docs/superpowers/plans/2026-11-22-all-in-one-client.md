# All-in-one-Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine native Desktop-App "LANoMAT" für Teilnehmer: Voice, Chat, Spiele-Bibliothek mit Installation, Server-Beitritt (One-Click) und Event-Infos. Der Rust-Launcher (`clients/launcher`) geht in die Voice-App (`clients/voice`) auf; Web-Launcher und Node-Agent werden zu dünnen Einstiegen.

**Architecture:**

- Variante C auf Basis von A (siehe Spec): `launcher-core` zieht in den Voice-Workspace, Rezept-Routen kommen unter `/api/voice-client/v1`, der Spieler-Chat bleibt deterministisch.
- Keine Shell-Ausführung von Rezept-Befehlen auf dem Client (`runner.ts` wird **nicht** portiert).
- Ein Pairing (Voice-Token `lvc_`), signierte Dateihost-Links für Plan-Downloads, ein Manifest, ein Release-Workflow.

**Tech Stack:** Rust (Tauri v2, reqwest 0.13, tokio, LiveKit), statisches HTML/CSS/JS-UI, PHP 8.4 / Laravel 13 (Modul `GameRecipes`, `WebVoice/NativeClient`), Pest 4, Docker-Builds mit `cargo-xwin`.

**Spec:** `docs/superpowers/specs/2026-11-22-all-in-one-client-design.md`. Zuerst lesen; die offenen Betreiberfragen in Abschnitt 12 gelten mit den dort genannten Empfehlungen, bis sie anders entschieden werden.

**Wichtige Ausgangslage:** `clients/launcher` existiert bereits (Rust, `launcher-core` mit Download, Archiv, Install, Auth, Deep-Link, Arcade-Flotte). Phasen 0 bis 2 sind **Konsolidierung**, kein Neubau. Alles Neue am Server ist additiv.

## Global Constraints

- **Rust nur im Docker bauen** (`clients/voice/Dockerfile.dev`, Targets `core`, `app`, `windows`). Nie Rust auf dem Host installieren oder verlangen. Gate pro Rust-Task: `cargo fmt --check`, `cargo clippy --locked --all-targets -- -D warnings`, `cargo test --locked --workspace` im Image.
- **Jede neue Route `api/voice-client/...`** bekommt in derselben Änderung einen Eintrag in `tests/Feature/Security/RouteAuthorizationMatrixTest.php` (Grund oder IDOR-Szenario). Sonst schlägt der Test fehl.
- **`api_version`:** Rezept-Routen und neue Felder sind additiv, `NativeClientConfig::API_VERSION` bleibt 1. Fähigkeit über `GET config` → `features.launcher`. Nur ein brechender Plan-Wechsel hebt die Version an (dann gilt "Download nur in der Server-Version").
- **Plan-Format:** `plan_version` (ganze Zahl) im Plan-Objekt; Client lehnt höhere Major ab, ignoriert unbekannte Felder.
- **Autorisierung** immer über Policies/Actions, nie über Client-IDs. Der Voice-Token bleibt auf `/api/voice-client/*` beschränkt.
- **Kein Shell** auf dem Client für Rezept-Schritte. Pfadprüfung zentral, SHA-256 für LAN-Quellen Pflicht.
- **Optionale Module:** neue Routen und UI hinter `Modules::enabled(Module::GameRecipes)`; Architektur-Ratchet beachten.
- **Deploy** baut alle vier Dienste mit `APP_VERSION`/`APP_COMMIT` (`scripts/deploy/deploy.sh`). Nichts wird vor der nächsten LAN ausgerollt (Spec Abschnitt 11).
- **Neue UI:** `frontend-design`-Skill, `docs/design.md` (Signalpult), neue Seiten in `resources/js/pages`-Listen (`pageLayouts.ts`), vier Frontend-Gates (`types:check`, `lint:check`, `format:check`, `build`). Die statischen Client-UIs unter `clients/*/app/ui` haben eigene Prüfungen (`check-ui.mjs`).
- **Copy:** UI deutsch, Code/Kommentare/Docs/Commits englisch (Conventional Commits). Docs sind public-safe (`scripts/ops/check-public-safe.sh`), Platzhalter `<app>`, `<file-host>`.
- **Tests erreichen nie echte Server:** Fakes/`wiremock` (Rust), `Http::fake`/Fakes (PHP).

## Review Focus

1. **Auth-Grenze:** ein Voice-Token darf nie eine `/api/v1`-Route erreichen und umgekehrt; ein fremder Slug/Datei-ID liefert 404, nie Daten (IDOR-Szenarien je Route).
2. **Plan-URLs:** für den Voice-Aufrufer ausschließlich signierte Dateihost-Links mit Ablauf, nie `mcp.files.download`; der Bearer geht nie an externe Hosts oder Redirects.
3. **Zip-Slip/Symlinks** in ZIP, tar.bz2, ISO mit bösartigen Fixtures (absolut, `..`, Laufwerk, Symlink, Hardlink).
4. **Deep-Link-Missbrauch:** `lanomat://install|launch|join` mit hostilen Slugs/Adressen aus dem Browser; nichts installiert ohne Bestätigung.
5. **Merge-Regressionen:** Windows-Smoke grün, Linux-Tarball, macOS-Spielebuild, Kiosk/Arcade, PTT und LiveKit unverändert.
6. **Token-Migration:** alter Launcher-Token funktioniert weiter, der neue Token öffnet keine MCP-Routen.

## Phasenübersicht

| Phase | Inhalt                                  | Ergebnis                                                          |
| ----- | --------------------------------------- | ----------------------------------------------------------------- |
| 0     | Klärung und Spikes (1 bis 2 Wochen)     | Entscheidungen, grüner Workspace-Merge im Spike, Plan-API-Entwurf |
| 1     | Server-API und Tab "Spiele" (nur lesen) | Voice-App zeigt Katalog, Verfügbarkeit, lokalen Status            |
| 2     | Installation und Start                  | Installieren, Reparieren, Starten in der App, gehärtet            |
| 3     | Server-Join und One-Click               | Tab "Server", `lanomat://` in der App, Jetzt/Chat                 |
| 4     | Ablösung Web-Launcher und Agent         | ein Pairing, ein Manifest, ein Release, Arcade in der App         |
| 5     | Signierung, Auto-Update, macOS          | signierte, selbst aktualisierende App; macOS-Spielebuild          |

Abhängigkeiten: 0 → 1 → 2 → 3 → 4 → 5. Innerhalb einer Phase sind Server-Tasks (PHP) und
Client-Tasks (Rust/UI) größtenteils parallel; die Spalte "Parallel" nennt Ausnahmen.

---

## Phase 0: Klärung und Spikes

### Task 0.1: Entscheidungen festhalten (ADR)

- **Ziel:** Antworten des Betreibers auf Spec Abschnitt 12 (oder Bestätigung der Empfehlungen) als Entscheidungsnotiz.
- **Dateien:** `docs/superpowers/specs/2026-11-22-all-in-one-client-design.md` (Abschnitt "Entscheidungen" anhängen).
- **Tests:** keine.
- **Akzeptanz:** Fragen 1 bis 6 beantwortet; offene Fragen sind mit Standardwert markiert.
- **Aufwand:** S · **Parallel:** ja, blockiert aber das Zusammenführen ab Task 0.2 nicht, nur Phase 2 (Frage 4).

### Task 0.2: Spike Workspace-Merge (Toolchain, Identität)

- **Ziel:** Beweisen, dass `launcher-core` im Voice-Workspace baut und testet (Linux, Windows per `cargo-xwin`), ohne die Voice-Funktion zu ändern.
- **Dateien:** Spike-Branch: `clients/voice/Cargo.toml` (Member `launcher-core`), `clients/voice/Dockerfile.dev` (Rust-Version: die höhere der beiden, derzeit 1.96 gegen 1.91; prüfen, ob libwebrtc/clang 21 und `libmkiso` beide bauen), `rust-version` (1.85 gegen 1.96 vereinheitlichen), `deny.toml` (neu, Lizenz-Allowlist).
- **Tests:** `cargo test --workspace` beider Crates im selben Image; `cargo deny check licenses`.
- **Akzeptanz:** ein Image baut beide Test-Sets und den Windows-Cross-Build; Entscheidung über Tauri-`identifier` (Voice `app.lanomat.voice` bleibt, Launcher-Konfig wird importiert) und Konfigverzeichnis dokumentiert.
- **Aufwand:** M · **Parallel:** ja zu 0.3, 0.4, 0.6.

### Task 0.3: Spike Download-Engine vereinheitlichen

- **Ziel:** Entscheiden, welche Engine bleibt: `voice-core/transfer` (Manager-Queue, Fortschritt, signierte Links, tus) oder `launcher-core/download.rs` (SHA-256, Ursprungs-gebundener Bearer, `download_many`, Cancel). Empfehlung: Voice-Manager behalten, SHA-256-Prüfung, Ursprungsbindung und Cancel aus dem Launcher ergänzen.
- **Dateien:** Spike: `clients/voice/voice-core/src/transfer/{download,manager}.rs` gegen `clients/launcher/launcher-core/src/download.rs`; Entscheidungsnotiz in der Spec.
- **Tests:** Spike-Test gegen `wiremock`: Abbruch bei 60 Prozent, Wiederaufnahme per `Range`, falsche Prüfsumme wird abgelehnt, Weiterleitung auf Fremdhost sendet keinen Bearer.
- **Akzeptanz:** Vergleichstabelle der Eigenschaften, eine gewählte Engine, Liste der zu portierenden Tests (`download_resume`, `download_security`, `download_lifecycle`).
- **Aufwand:** M · **Parallel:** ja.

### Task 0.4: Entwurf Plan-API unter dem Voice-Token

- **Ziel:** Routen, Policies und die Download-URL-Strategie festlegen.
- **Dateien:** Entwurf in der Spec (Abschnitt 7/8); zu lesen: `app/Modules/GameRecipes/Support/BuildInstallPlan.php`, `app/Modules/WebVoice/NativeClient/Http/*`, `app/Modules/Files/*` (signierte Links), `routes/voice-client*.php`.
- **Tests:** keine (Entwurf).
- **Akzeptanz:** Liste: `GET recipes`, `GET recipes/{slug}`, `POST recipes/{slug}/plan`, `GET recipes/{slug}/avatars` plus Plan-Datei-Download über signierte Links; je Route Policy, Throttle, Matrix-Eintrag; wie `BuildInstallPlan` die URL-Strategie je Aufrufer-Typ bekommt.
- **Aufwand:** S · **Parallel:** ja; Eingabe für 1.1 und 1.2.

### Task 0.5: Spike Deep-Link und Single-Instance in der Voice-App

- **Ziel:** `lanomat://` registrieren (Windows-Installer, Linux `.desktop`) und an die laufende Instanz weiterreichen (Tauri-Plugins `deep-link`, `single-instance`; die Voice-App hat bereits einen Steuer-Socket/Named Pipe).
- **Dateien:** `clients/voice/app/Cargo.toml`, `clients/voice/app/tauri.conf.json` (`plugins.deep-link`), `clients/voice/app/capabilities/*.json`, `clients/voice/app/src/lib.rs`; Referenz: `clients/launcher/app/src/deeplink.rs`.
- **Tests:** Unit-Tests des Parsers (hostile Eingaben); `ci/windows-smoke.ps1` erweitert: Aufruf `lanomat://launch/<slug>` gegen die laufende Instanz.
- **Akzeptanz:** Zweiter Start leitet die URL an die erste Instanz; Parser akzeptiert nur `install|launch|join`; keine neue WebView-Berechtigung außer `deep-link:default`.
- **Aufwand:** M · **Parallel:** ja, nach 0.2.

### Task 0.6: Inventur Rezepte und Befehls-Rezepte

- **Ziel:** Welche der veröffentlichten Rezepte sind `launcher_supported`, welche brauchen Befehle; Folgerung für Task 2.5.
- **Dateien:** `resources/recipes/*`, `app/Modules/GameRecipes/Support/LauncherSupport.php`; Auswertung als Tabelle in der Spec.
- **Tests:** keine (Auswertung; optional ein Pest-Test, der die Zahl der Befehls-Rezepte als Ratchet festhält).
- **Akzeptanz:** Tabelle je Slug: portable / deklarativ möglich / manuell / ausgeschlossen (Steam, Anti-Cheat, UAC).
- **Aufwand:** S · **Parallel:** ja.

---

## Phase 1: Server-API und Tab "Spiele" (nur lesen)

### Task 1.1: Server: Rezept-Routen unter `/api/voice-client/v1`

- **Ziel:** Katalog und Plan für den Voice-Token.
- **Dateien:**
    - Create: `app/Modules/WebVoice/NativeClient/Http/RecipesApiController.php` (dünn; nutzt `GameRecipes`-Actions/Policies über einen Vertrag, keine Tabellen eines anderen Moduls)
    - Modify: `routes/voice-client.php` (oder neue Datei `routes/voice-client-games.php`, wie `-play`, `-account`, `-presence`) mit `Modules::enabled(Module::GameRecipes)`, `app/Modules/WebVoice/NativeClient/Support/NativeClientConfig.php` (`features.launcher`), `tests/Feature/Security/RouteAuthorizationMatrixTest.php`
    - Test: `tests/Feature/NativeClient/RecipesApiTest.php`
- **Tests (zuerst, rot):** ohne Token 401 für alle Ids; fremder/unbekannter Slug 404; Lineup respektiert; `launcher_supported` korrekt; Plan zeichnet `GameRecipeInstall` auf; Throttle; `features.launcher` nur bei aktivem Modul; MCP-Token öffnet die Routen nicht; Matrix-Test grün.
- **Akzeptanz:** Alle Routen in der Matrix; `api_version` bleibt 1; Dokumentation in `docs/infra/native-voice-client-api.md` ergänzt.
- **Aufwand:** L · **Parallel:** ja zu 1.3 bis 1.6; blockiert 1.2-Verdrahtung.

### Task 1.2: Server: Plan mit `plan_version` und Download-URL-Strategie

- **Ziel:** `BuildInstallPlan` liefert für den Voice-Aufrufer signierte Dateihost-Links (Ablauf), für MCP unverändert `mcp.files.download`.
- **Dateien:** Modify `app/Modules/GameRecipes/Support/BuildInstallPlan.php` (Strategie-Parameter), `app/Modules/Files/*` (Signierer wiederverwenden, kein neuer Pfad), Test `tests/Feature/GameRecipes/BuildInstallPlanUrlStrategyTest.php`.
- **Tests:** MCP-Plan unverändert (Snapshot); Voice-Plan enthält nur signierte Links mit `expires`, `sha256` je LAN-Datei, `plan_version`; externe Dateien tragen `fallback_url` ohne Bearer-Hinweis.
- **Akzeptanz:** keine Regression der bestehenden Plan-/MCP-Tests; `plan_version: 1`.
- **Aufwand:** M · **Parallel:** vor 1.1-Abschluss, sonst ja.

### Task 1.3: voice-core: Rezept- und Plan-Client

- **Ziel:** Typen und API-Aufrufe für Katalog/Plan im Voice-Kern.
- **Dateien:** Create `clients/voice/voice-core/src/api/recipes.rs`; Modify `api/mod.rs`; Port aus `clients/launcher/launcher-core/src/{types,api}.rs` (Plan-Typen, `installable`/`launcher_supported`-Flags mit "älterer Server = unbekannt"). Tests `wiremock`.
- **Tests:** Serialisierung gegen Server-Fixture; höhere `plan_version` wird abgelehnt; unbekannte Felder ignoriert.
- **Akzeptanz:** `cargo test` grün im Image; kein Tokenwert in `Debug`/Fehlern.
- **Aufwand:** M · **Parallel:** ja.

### Task 1.4: Crate-Layout und Zustandsverzeichnis

- **Ziel:** `launcher-core` im Voice-Workspace dauerhaft (aus Spike 0.2), Konfig-/Zustandsverzeichnis der App, Import der Launcher-`installs.json`.
- **Dateien:** `clients/voice/Cargo.toml`, `clients/voice/launcher-core/**` (verschoben mit Verlauf), `clients/voice/voice-core/src/session.rs`/Credential-Store (Pfad), Import-Funktion in `launcher-core/src/state.rs`; `clients/launcher` bleibt bis Phase 4 bestehen, wird aber nicht weiterentwickelt.
- **Tests:** Zustandsdatei-Tests (`state_files`, `security_state`) im neuen Ort; Import einer alten Datei; Token wird nie importiert.
- **Akzeptanz:** ein Workspace, ein Image, beide Test-Sets grün; CI-Gate `ci/build.sh` deckt beides ab.
- **Aufwand:** M · **Parallel:** nach 0.2, 0.3.

### Task 1.5: Lokales Installationsregister

- **Ziel:** Ein Register installierter Spiele (Slug, Pfad, Plan-Hash, Startbefehl, zuletzt gespielt, Status).
- **Dateien:** `clients/voice/launcher-core/src/state.rs`, `clients/voice/app/src/installs.rs` (Port aus `launcher/app/src/installs.rs`).
- **Tests:** Round-trip, Rechte 0600/0700, Korruptions-Quarantäne (`quarantine_path`), Reparatur-Status.
- **Akzeptanz:** Status-Automat `not_installed → installing → ready`, Seitenzustände `repair_needed`, `broken`, `offline`.
- **Aufwand:** S · **Parallel:** ja.

### Task 1.6: UI: Tab "Spiele" (Bibliothek, nur lesen)

- **Ziel:** Karten je Rezept mit Verfügbarkeit (Dateien auf dem LAN-Host / fehlt), Plattform, lokalem Status; Leerzustand, Ladezustand, Fehler, Normal.
- **Dateien:** `clients/voice/app/ui/js/games.js` (neu), `app/ui/index.html`, `app/ui/app.css`/`screens.css`, Tauri-Commands in `clients/voice/app/src/features/*.rs`; UI-Texte deutsch; Design nach `docs/design.md` (Signalpult); Tab nur bei `features.launcher` und aktivem Modul.
- **Tests:** `clients/voice/app/src/features/tests.rs` (Command-Ebene), UI-Check-Skript, Screenshot-Pipeline-Eintrag.
- **Akzeptanz:** Katalog sichtbar gegen seeded Server; ein Recipe ohne Dateien zeigt den Fehlerzustand verständlich; AA-Kontrast, sichtbarer Fokus, `prefers-reduced-motion`.
- **Aufwand:** L · **Parallel:** ja, nach 1.3 und 1.5. Zuerst `frontend-design`-Skill.

---

## Phase 2: Installation und Start

### Task 2.1: Installation in der App (Engine + Fortschritt)

- **Ziel:** "Installieren" führt Plan → Download (gewählte Engine) → Prüfsumme → Entpacken → Verifikation aus; Fortschritt, Tempo, ETA, Pause, Abbruch, Wiederaufnahme.
- **Dateien:** `clients/voice/launcher-core/src/{install,archive}.rs` (Port), `clients/voice/app/src/engine.rs`/`features/*`, UI `app/ui/js/install.js`; Ereignis-Vertrag (Fortschritt) sprachneutral dokumentiert.
- **Tests:** `install_e2e`, `install_engine`, `install_acceptance`, `e2e_pilot` aus dem Launcher gegen `wiremock`; Unterbrechung und Wiederaufnahme; Abbruch räumt `.part` auf.
- **Akzeptanz:** UT2004 und ein generisches portables Rezept installieren auf Linux im Image-Test vollständig; Prozentanzeige stimmt mit Bytes überein.
- **Aufwand:** L · **Parallel:** nach 1.1, 1.2, 1.3, 1.4, 1.5.

### Task 2.2: Start, Reparieren, "Zuletzt gespielt"

- **Ziel:** Starten (nur das vom Plan genannte Startziel im Installationsordner), Reparieren (Verifikation + fehlende Dateien), Zuletzt-gespielt-Liste.
- **Dateien:** `launcher-core/src/install.rs` (`launch*`), `app/src/installs.rs`, UI `ready.js`.
- **Tests:** `tar_symlink_launch`, Start verweigert Pfad außerhalb des Installationsordners, macOS-Bundle-Start.
- **Akzeptanz:** nie Shell-Interpretation des Startbefehls; Argumente werden einzeln übergeben.
- **Aufwand:** M · **Parallel:** nach 2.1.

### Task 2.3: Avatare im Plan

- **Ziel:** Auswahl und Installation der im Plan genannten VRM/Avatar-Dateien (heute `PlanAvatar` nur in den Typen).
- **Dateien:** `launcher-core/src/{types,install}.rs`, UI-Auswahl in `install.js`; Server: `GET recipes/{slug}/avatars` aus 1.1.
- **Tests:** Auswahl bleibt lokal; nur ausgewählte Avatare werden geladen; Hash-Prüfung.
- **Akzeptanz:** Verhalten entspricht `avatars.ts`/`install.ts` (Multi-Select, LAN-Quelle bevorzugt).
- **Aufwand:** M · **Parallel:** ja zu 2.2.

### Task 2.4: Sicherheitshärtung und Fuzz

- **Ziel:** Pfad-/Symlink-/Hash-Regeln zentral, Fuzz-Fixtures, Installationsordner je OS.
- **Dateien:** `launcher-core/src/archive.rs`, `launcher-core/tests/security_*.rs`, `archive_symlink.rs`, neue Fixtures (ZIP/tar.bz2/ISO mit Traversal, Bomb-Größen).
- **Tests:** Zip-Slip, absolute Pfade, Laufwerk-Präfixe, Symlink-/Hardlink-Ketten, Dekompressionsbombe (Größenlimit), Dateinamen mit reservierten Windows-Namen.
- **Akzeptanz:** Matrix aller Formate × Angriffe grün; Installation ohne SHA-256 für LAN-Dateien wird abgelehnt.
- **Aufwand:** M · **Parallel:** ja zu 2.2, 2.3.

### Task 2.5: Nicht-portable Rezepte: deklarative Schritt-Allowlist

- **Ziel:** Befehls-Rezepte aus Task 0.6 entweder in Portable-Archive überführen oder in eine kleine, geprüfte Schritt-Allowlist ohne Shell (`extract`, `copy`, `write_file`, `shortcut`; `registry_set` nur HKCU und Allowlist) oder `manual`.
- **Dateien:** `app/Modules/GameRecipes/Support/RecipeValidator.php`, `LauncherSupport.php`, `resources/recipes/schema/*`, Plan-Schritt-Typen in `launcher-core/src/types.rs`, Ausführung in `install.rs`.
- **Tests:** Validator lehnt jeden Befehl außerhalb der Allowlist ab; Server und Client teilen eine Fixture-Liste; Schritt-Ausführung ist idempotent.
- **Akzeptanz:** mindestens ein bisher befehlsbasiertes Rezept läuft ohne Shell; `runner.ts` bleibt ungenutzt für Spieler.
- **Aufwand:** L · **Parallel:** nach 2.1; **Betreiber-Entscheidung Frage 4 nötig**.

### Task 2.6: Windows-Smoke für Installation

- **Ziel:** `ci/windows-smoke.ps1` installiert ein kleines Test-Archiv von einem lokalen Fake-Server, startet es und deinstalliert.
- **Dateien:** `clients/voice/ci/windows-smoke.ps1`, Test-Fixture, `ci/build.sh`.
- **Tests:** Windows-Runner-Lauf (`windows-smoke` Job).
- **Akzeptanz:** Portable- und installierte EXE schreiben nur in den Installationsordner; Deinstallation lässt installierte Spiele unberührt (Hinweis im Dialog).
- **Aufwand:** M · **Parallel:** nach 2.1.

---

## Phase 3: Server-Join und One-Click

### Task 3.1: Tab "Server" vereinheitlichen

- **Ziel:** Eine Serverliste mit One-Click: Spiel installiert → direkt beitreten; fehlt es → Installations-Assistent, danach Beitritt. Direkte Wege (`steam://connect`, Join-URL, kopieren) bleiben.
- **Dateien:** `clients/voice/app/src/joinurl.rs` (Validierung, erweitert um `lanomat://join`), `features.rs`, UI `app/ui/js/servers.js`; Server liefert `launcher_join_link` je Zeile (bereits vorhanden, `LauncherJoinLink`).
- **Tests:** Join-Validierung (hostile Adressen, Optionen, Passwörter), Zustandsmaschine "Spiel fehlt → installieren → beitreten".
- **Akzeptanz:** ein Klick vom Serverlisten-Eintrag bis ins Spiel auf dem Pilot-Rezept.
- **Aufwand:** M · **Parallel:** nach 2.1, 2.2.

### Task 3.2: `lanomat://` in der App scharf schalten

- **Ziel:** `install|launch|join` aus dem Browser; Bestätigungsdialog vor Installation; Koexistenz mit dem alten Launcher (App-Vorrang, Hinweis im alten Launcher).
- **Dateien:** `clients/voice/app/src/{lib.rs,deeplink.rs}`, `tauri.conf.json`, NSIS-Installer (`bundle.windows.nsis`), `.desktop`-Eintrag, Web: `app/Modules/GameRecipes/Support/LauncherJoinLink.php` bleibt.
- **Tests:** Parser, Slug-Allowlist, Windows-Smoke (Task 0.5-Erweiterung).
- **Akzeptanz:** Klick auf "Beitreten" auf `/servers` öffnet die App; ohne App zeigt die Seite den bestehenden Download-Hinweis.
- **Aufwand:** M · **Parallel:** nach 0.5, 3.1.

### Task 3.3: Tab "Jetzt" mit deterministischem Chat

- **Ziel:** Event-Feed und Chat-Eingang (gleiche Antworten wie der Web-Start-Chat: "Welche Spiele laufen?", "Installiere UT2004").
- **Dateien:** Server: Endpunkt `GET /api/voice-client/v1/chat` (Antwortsatz aus `HomeChatData`-nahem Read-Model, Matrix-Eintrag), Client: `clients/voice/app/ui/js/home.js`, Port von `launcher/app/ui/js/chat.js`.
- **Tests:** Server: gleiche Antworten wie Web, keine PII; Client: Karten rendern aus API-Daten, nie erfunden.
- **Akzeptanz:** jede Absicht (`install_game`, `launch_game`, `join_server`, `browse_games`, `status`, `help`) hat eine deterministische Antwort.
- **Aufwand:** M · **Parallel:** ja zu 3.1.

### Task 3.4: Web-Seiten auf die App ausrichten

- **Ziel:** `/games`, `/servers`, Start-Chat, Header-Button: Texte und Ziele "LANoMAT-App" statt "Launcher/Voice"; weiterhin `lanomat://`.
- **Dateien:** `resources/js/pages/Games/*`, `Servers/*`, `Launcher/Index.vue`, `lang/de/*`, `app/Modules/GameRecipes/Http/GamePageController.php`; `resources/js/lib/pageLayouts.ts` falls Seiten wegfallen.
- **Tests:** Pest Feature-Tests der Seiten, `PageLayoutsTest`, Shell-Parity (`tests/Feature/ShellParity`), vier Frontend-Gates.
- **Akzeptanz:** Download-Einstieg verweist auf eine App; alte Launcher-Texte nur noch im Übergangshinweis.
- **Aufwand:** M · **Parallel:** ja, nach 3.2.

---

## Phase 4: Ablösung Web-Launcher und Agent

### Task 4.1: Token-Migration

- **Ziel:** Neue App koppelt ausschließlich per Voice-Device-Flow; alter Launcher-Token (`lmcp_`) bleibt N Releases gültig; "Verbundene Geräte" zeigt Typ und Deprecation-Hinweis.
- **Dateien:** `app/Modules/Mcp/Models/McpToken.php` (Kennzeichnung Launcher-Token), `/mcp/connect`-Seite, `WebVoice/NativeClient/*`, Docs.
- **Tests:** Voice-Token erreicht keine `/api/v1`-Route; alter Token funktioniert unverändert; Widerruf einzeln.
- **Akzeptanz:** Matrix-Test grün; Migrationsanleitung in `docs-site/pages/guides/launcher.mdx`.
- **Aufwand:** M · **Parallel:** ja.

### Task 4.2: Ein Manifest, eine Download-Seite

- **Ziel:** `LauncherBuilds` und Client-Manifest zusammenführen; `/launcher` leitet auf `/app` um; Preflight-Prüfungen `launcher_build` und Client-Build verschmelzen.
- **Dateien:** `app/Modules/GameRecipes/Support/LauncherBuilds.php`, `LauncherPageController.php`, die Client-Download-Controller unter `app/Modules/WebVoice`, `app/Modules/Preflight/*`, `scripts/ops/build-client.sh`, `scripts/ops/build-launcher.sh`.
- **Tests:** Manifest-Validator (Fremdnamen, falsche Hashes), Redirect, Preflight-Probe, "Download nur in der Server-Version".
- **Akzeptanz:** nur noch `clients-dist/` mit einem Manifest; alte `/launcher/download/*`-Links leiten mit 301 um.
- **Aufwand:** M · **Parallel:** nach 3.4.

### Task 4.3: Release-Workflow zusammenführen

- **Ziel:** `release-launcher.yml` geht in `release-client.yml` auf (Linux-Tarball, Windows NSIS + portable, macOS-Job); ein Tag-Schema `client-v*`.
- **Dateien:** `.github/workflows/release-client.yml`, `release-launcher.yml` (entfernen), `clients/voice/ci/{build.sh,manifest.sh}`, `docs/infra/native-client-release.md`.
- **Tests:** Trockenlauf per `workflow_dispatch` ohne Publish; `windows-smoke`.
- **Akzeptanz:** ein Artefaktsatz, `SHA256SUMS`, Manifest mit allen Varianten (`installer|portable|archive`).
- **Aufwand:** M · **Parallel:** nach 4.2-Format, vor 5.x.

### Task 4.4: Arcade/Kiosk und Flotte in die App

- **Ziel:** `--arcade`/`--kiosk`, QR-Anmeldung, Heartbeat (`POST /api/v1/terminals/heartbeat`), Terminal-Installer-Skripte.
- **Dateien:** Port `clients/launcher/app/src/{arcade,heartbeat}.rs` und `scripts/install-terminal.*`, `docs/infra/arcade-terminals.md`.
- **Tests:** `smoke-shell.sh`, `test-install-terminal.*`, Heartbeat gegen `wiremock`; Terminal behält MCP-Token.
- **Akzeptanz:** ein Terminal läuft mit der neuen App wie mit dem alten Launcher; Fleet-Liste im Panel unverändert.
- **Aufwand:** M · **Parallel:** ja.

### Task 4.5: Node-Agent zurückstufen

- **Ziel:** Agent bleibt Orga-/Dev-Werkzeug (MCP, `recipe_contribute`, Skills), `install`/`launch`/`register` verweisen auf die App; aus Spieler-Dokumenten entfernt.
- **Dateien:** `clients/agent/README.md`, `clients/agent/src/cli.ts`, `docs-site/pages/guides/install-the-agent.mdx`, `docs-site/pages/mcp/*`, `docs-site/pages/guides/launcher.mdx`.
- **Tests:** `clients/agent` `npm test`; `docs-site` `npm run build` und `check:links`.
- **Akzeptanz:** keine Spieler-Anleitung nennt den Agenten als Weg; Agent druckt Hinweis bei Spieler-Befehlen.
- **Aufwand:** S · **Parallel:** ja.

### Task 4.6: Doku, Screenshots, Handbuch

- **Ziel:** `docs/design.md` (Navigation, Spiele-Tab), `docs/architecture.md`, `docs/infra/native-voice-client-api.md`, `docs-site` Guides, Screenshot-Pipeline, `CHANGELOG.md`, `README.md`.
- **Dateien:** die genannten; `docs/screenshots/*`.
- **Tests:** `check-public-safe.sh`, `prettier --check`, `docs-site` Build.
- **Akzeptanz:** keine tote Verweise auf `/launcher` ohne Umleitungshinweis.
- **Aufwand:** M · **Parallel:** ja, fortlaufend.

### Task 4.7 (optional, eigene Spec): Spieler-Chat mit LLM

- **Ziel:** Serverseitiger Spieler-Chat über `laravel/ai` mit Spieler-Werkzeugen (nur lesen und "installiere" als Karte, Ausführung nur per Klick in der App).
- **Voraussetzung:** Assistent-Modul (`docs/superpowers/plans/2026-11-21-ai-assistant.md`) ist live und verifiziert; Kostenlimit und Datenschutz geklärt (Frage 5).
- **Aufwand:** L · **Parallel:** unabhängig; **kein Teil des Kernplans**.

---

## Phase 5: Signierung, Auto-Update, macOS

### Task 5.1: Tauri-Updater mit eigenem Manifest

- **Ziel:** App aktualisiert sich selbst aus dem Server (`clients-dist/`), nur innerhalb gleicher `api_version`; Updater-Signatur (minisign), Schlüssel im Secret-Store, nie im Repo.
- **Dateien:** `clients/voice/app/Cargo.toml` (`tauri-plugin-updater`), `tauri.conf.json`, `voice-core/src/update.rs` (heute nur Hinweis), Release-Workflow (Signaturdateien), Server-Manifest-Feld.
- **Tests:** Updater gegen Fake-Manifest (Rollback bei falscher Signatur, Downgrade verboten, andere `api_version` wird nicht angeboten).
- **Akzeptanz:** Update von N auf N+1 auf Windows im Smoke; falsche Signatur wird abgelehnt.
- **Aufwand:** L · **Parallel:** nach 4.3.

### Task 5.2: Windows-Code-Signierung

- **Ziel:** Authenticode für die EXEs und den Installer (inklusive der EXE **im** Installer, `bundle.windows.signCommand` auf Windows-Host); Anbieter laut Betreiberentscheidung (Frage 9).
- **Dateien:** `release-client.yml` (`WINDOWS_SIGNING`-Hook besteht), `docs/infra/native-client-release.md`.
- **Tests:** Signaturprüfung im `windows-smoke`.
- **Akzeptanz:** `signed: true` im Manifest; SmartScreen-Hinweis entfällt oder ist dokumentiert.
- **Aufwand:** M (plus externe Abhängigkeit) · **Parallel:** ja.

### Task 5.3: macOS-Spielebuild

- **Ziel:** App für macOS mit Cargo-Feature `voice` aus (Spiele, Server, Jetzt, Mehr); LiveKit/libwebrtc und PTT-Rechte erst später.
- **Dateien:** `clients/voice/*/Cargo.toml` (Feature `voice`, `cfg(feature = "voice")` um Audio/LiveKit), `release-client.yml` (`macos-14`), Doku.
- **Tests:** `cargo test --no-default-features` im Image; macOS-Job baut `.app/.dmg`, Mach-O-Architekturprüfung.
- **Akzeptanz:** Spielebuild startet auf macOS, installiert ein portables Rezept; Voice-Tab fehlt ehrlich.
- **Aufwand:** L · **Parallel:** ja zu 5.1/5.2.

### Task 5.4: Abnahme am echten Ort

- **Ziel:** Real-Venue-Test der App auf frischem Windows- und Linux-Rechner, Arcade-Terminal und Update; Abnahmeliste ergänzen.
- **Dateien:** `docs/pre-lan-acceptance-checklist.md`, `docs/infra/*`.
- **Tests:** manuell, Protokoll im Repo public-safe.
- **Akzeptanz:** alle Zeilen der Abnahmeliste "All-in-one-App" bestanden; danach Löschung von `clients/launcher` und `release-launcher.yml` freigeben.
- **Aufwand:** M · **Parallel:** nein.

---

## Abhängigkeitsgraph (Kurzform)

```text
0.1 ─┬─> 2.5
0.2 ─┬─> 0.5 ─> 3.2
     ├─> 1.4 ─┬─> 1.5 ─> 1.6
0.3 ─┘        └─> 2.1 ─┬─> 2.2 ─> 3.1 ─> 3.2 ─> 3.4 ─> 4.2 ─> 4.3 ─> 5.1
0.4 ─> 1.1/1.2 ─> 1.3 ─> 2.1  ├─> 2.3     4.1 4.4 4.5 4.6 (parallel)
0.6 ─> 2.5                    ├─> 2.4
                              └─> 2.6
5.2, 5.3 parallel zu 5.1; 5.4 zuletzt
```

## Gesamtaufwand (grob)

Phase 0: 3 bis 4 Personentage verteilt auf parallele Spikes. Phase 1: rund 2 Wochen. Phase 2: rund
2 bis 3 Wochen (2.5 ist der Unsicherheitsfaktor). Phase 3: rund 1 bis 2 Wochen. Phase 4: rund
2 Wochen. Phase 5: rund 2 bis 3 Wochen plus externe Wartezeit für das Signierzertifikat.
