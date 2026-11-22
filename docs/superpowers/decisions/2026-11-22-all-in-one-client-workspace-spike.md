# All-in-one-Client — Spike Workspace-Merge und Deep-Link (Phase 0, Tasks 0.2 und 0.5)

Stand: 2026-11-22. **Spike-Code, nicht für den Produktivbetrieb.** Branch `feat/all-in-one-phase0`.
Alle Rust-Läufe im Docker (Images aus `clients/voice/Dockerfile.dev`, getauft `p0-voice-*`,
eigene Cache-Volumes `p0-*`; Haupt-Repo und die Volumes der anderen Sitzung blieben unberührt).
Einordnung: [ADR](2026-11-22-all-in-one-client-adr.md).

## 1. Was im Spike gebaut wurde

| Änderung                               | Datei                                                                                                                                                              | Zweck                                                                                  |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| `launcher-core` als Workspace-Mitglied | `clients/voice/launcher-core/` (**Kopie** von `clients/launcher/launcher-core`, `Cargo.toml` unverändert)                                                          | Merge beweisen, ohne Renes Verzeichnis anzufassen. Task 1.4 macht daraus ein `git mv`. |
| Workspace                              | `clients/voice/Cargo.toml`: Mitglied `launcher-core`, `rust-version` 1.85 auf 1.96                                                                                 | siehe Abschnitt 3                                                                      |
| Toolchain                              | `clients/voice/Dockerfile.dev`: `rust:1.91-bookworm` auf `rust:1.96-bookworm`                                                                                      | siehe Abschnitt 3                                                                      |
| Lizenzrichtlinie                       | `clients/voice/deny.toml` (neu)                                                                                                                                    | `cargo deny check licenses`                                                            |
| Deep-Link, Einzelinstanz               | `clients/voice/app/{Cargo.toml,tauri.conf.json,src/lib.rs}`, `src/deeplink.rs` (Kopie des Launcher-Parsers), `src/deeplink_wiring.rs`, `tests/deeplink_hostile.rs` | Abschnitt 5                                                                            |
| Download-Prototyp                      | `clients/voice/launcher-core/tests/spike_download_renew.rs`                                                                                                        | [Download-Engine](2026-11-22-all-in-one-client-download-engine.md)                     |

`clients/launcher` wurde **nicht verändert**. Die Kopie driftet, sobald Rene dort weiterarbeitet;
vor Task 1.4 den Stand abgleichen (Abschnitt 8).

## 2. Befehle, Zeiten, Ergebnisse

Alle Zeiten sind Kaltläufe auf einer 16-Kern-Maschine mit leerem Cargo-Cache (frisches Volume),
einschließlich des Ladens von libwebrtc. `dc` = `docker run --rm --network host -v "$PWD:/work"
-v p0-cargo-registry:/usr/local/cargo/registry -v <target-volume>:/work/target -w /work <image>`,
Arbeitsverzeichnis `clients/voice`.

### Vorher-Stand (unverändert, `origin/main`)

| Lauf          | Image                                                                  | Befehl                                           | Ergebnis                                    | Zeit |
| ------------- | ---------------------------------------------------------------------- | ------------------------------------------------ | ------------------------------------------- | ---- |
| Voice Kern    | `lanomat-voice-dev` (Rust 1.91)                                        | `cargo test --locked -p voice-core -p voice-cli` | 201 grün, 4 ignoriert (echte Server), 0 rot | 3m24 |
| Voice App     | `lanomat-voice-app-dev` (1.91)                                         | `cargo test --locked -p lanomat-voice-app`       | 52 grün, 0 rot                              | 7m16 |
| Launcher Kern | `rust:1.96` + openssl (`clients/launcher/Dockerfile.dev`, Ziel `core`) | `cargo test --locked -p launcher-core`           | 216 grün, 0 rot                             | 1m45 |

### Nachher-Stand (gemeinsamer Workspace, ein Image mit Rust 1.96 und clang 21)

| Lauf               | Image                                                          | Befehl                                                                          | Ergebnis                                                                       | Zeit                           |
| ------------------ | -------------------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ | ------------------------------ |
| Kern, beide Crates | `p0-voice-core`                                                | `cargo test --locked -p voice-core -p voice-cli -p launcher-core`               | **420 grün** (201 + 216 + 3 neue Download-Tests), 5 ignoriert, 0 rot           | 7m52                           |
| App                | `p0-voice-app`                                                 | `cargo test --locked -p lanomat-voice-app`                                      | 63 grün (52 bisherige + 9 Launcher-Parser + 2 Wiring) + 3 Hostile-Tests, 0 rot | 10m21                          |
| Format             | `p0-voice-app`                                                 | `cargo fmt --check`                                                             | grün (nach `cargo fmt` auf den neuen Dateien)                                  | Sekunden                       |
| Clippy             | `p0-voice-app`                                                 | `cargo clippy --locked --workspace --all-targets --all-features -- -D warnings` | grün, keine Warnung                                                            | 3m17                           |
| Lizenzen           | `p0-voice-core` + `cargo install cargo-deny --locked` (0.22.0) | `cargo deny check licenses`                                                     | grün nach Aufnahme von `bzip2-1.0.6`                                           | Installation 6m, Lauf Sekunden |
| Windows            | `p0-voice-win`                                                 | `cargo xwin check` und NSIS-Build, Abschnitt 6                                  | grün                                                                           | 4m20 und 6m16                  |

Die Laufzeit ist kein Regressionsmaß: Voice-Image und Voice-App wurden gegen ein leeres
Target-Volume mit einem neuen Compiler gebaut, der Vorher-Lauf gegen ein 1.91-Volume ohne
launcher-core.

## 3. Toolchain: Rust 1.91 oder 1.96

**Gewählt: Rust 1.96 für den gemeinsamen Workspace.** Beleg: der erste Lauf im bisherigen
Voice-Image (1.91.1) bricht nach 11 Sekunden ab:

```
error: rustc 1.91.1 is not supported by the following package:
  libmkiso@0.1.5 requires rustc 1.96
```

`libmkiso` (ISO-Entpacker des Launchers, `launcher-core/Cargo.toml`) braucht 1.96. Der Launcher
hatte `rust-version = "1.96"` also nicht "auf Verdacht", sondern wegen dieser Abhängigkeit.
Der Rückweg wäre eine ältere `libmkiso`-Version oder ein eigener ISO-Leser; das lohnt nicht,
solange 1.96 die libwebrtc-Kette nicht stört. Es stört nicht: `voice-core` baut und testet unter
1.96 mit clang 21 unverändert (201 grün wie vorher). Folgen:

- `rust-version` im Workspace auf 1.96 (war 1.85 für Voice, 1.96 für Launcher). Auch die
  Pfad-Abhängigkeiten erben es (`version.workspace`/`rust-version.workspace`).
- Das Voice-Image zieht damit auf `rust:1.96-bookworm`. Die Windows-Stage des Voice-Images bleibt auf
  LLVM 21 (dort ist `clang-cl` per Symlink vorhanden); die LLVM-19-Umgehung mit `clang-cl`-Wrapper
  im Launcher-Image entfällt (Voice-Stufe `windows` hat clang 21 aus `apt.llvm.org`).
- `.github/workflows/release-client.yml` und `ci/build.sh` verwenden das Voice-Image; Rust 1.96
  schlägt dort ohne weitere Änderung durch. Ein Pin der Image-Digests ist hier nicht Teil des
  Spikes.
- macOS: der Launcher baut dort **ohne Docker** auf `macos-14` mit der Toolchain des Runners.
  Für eine gemeinsame App braucht es dort ebenfalls 1.96 (Runner-Toolchain prüfen) und, solange
  `voice-core` libwebrtc zieht, ein Cargo-Feature, das Voice abschaltet (Entscheidung 8): heute ist
  `livekit` in `voice-core` **nicht optional**; der macOS-Build des gemeinsamen Workspaces braucht
  also Arbeit in `voice-core` (Feature `livekit`) und in der App (`voice`-Feature). Im Spike nicht
  gebaut.

## 4. Abhängigkeits-Kollisionen

Der gemeinsame `Cargo.lock` entstand durch Auflösen der neuen Abhängigkeiten gegen den bestehenden
Voice-Lock. Verglichen mit dem Voice-Lock vorher:

- **Keine Version einer bestehenden Voice-Abhängigkeit hat sich geändert.** Neu kamen 24 Pakete
  hinzu (plus Tauri-Plugins); doppelt vorhanden sind danach nur drei:
  `zip` 6.0.0 (launcher-core) neben `zip` 9.2.0 (nur `webrtc-sys-build`, ein Build-Skript),
  `hashbrown` 0.14.5 zusätzlich, `core-foundation` 0.9.4 zusätzlich.
- `reqwest` 0.13.5 (launcher-core **und** voice-core, gleiche Feature-Basis: `rustls`, `stream`,
  `json`) wird geteilt. `reqwest` 0.12.28 steckt nur hinter `livekit-net` und `webrtc-sys-build`
  und war schon vorher da.
- `tokio` 1 (beide), `rustls` 0.23 (Voice setzt `aws-lc-rs` explizit; launcher-core zieht rustls
  über reqwest), `sha2` 0.10, `serde`, `thiserror` 2, `directories` 6, `tracing`: eine Version.
  Bemerkenswert: `launcher-core` nutzt `reqwest` mit Standard-Features, `voice-core` mit
  `default-features = false` und `rustls`. Mit `cargo tree -e features` geprüft: in reqwest 0.13
  ist `default-tls` rustls mit dem `aws-lc-rs`-Provider, beide Seiten landen auf derselben
  TLS-Kette (`__rustls-aws-lc-rs`). Der Provider-Aufruf `voice_core::install_crypto_provider()`
  gehört beim Zusammenführen der App-Schale vor jede erste Verbindung (die Voice-App tut das in
  `run()`).
- Tauri 2.12.5 (Voice-Lock) statt 2.12.7 (Launcher-Lock): Patch-Stufe, kein Konflikt;
  `tauri-cli` 2.12.4 im Image passt zu beiden.
- **libwebrtc braucht clang 21**, das Launcher-Image hatte keinen clang (nur Windows-Stage mit
  LLVM 19). Das gemeinsame Image folgt dem Voice-Image (clang 21 über `apt.llvm.org`); der Launcher
  verliert dadurch nichts, `libmkiso` und `bzip2`/`zip` brauchen kein clang.
- `aws-lc-sys` (rustls-Provider) und libwebrtc verlangen für Windows NASM bzw. `clang-cl`: die
  Voice-Windows-Stage hat beides.
- **Lizenzen:** beide Workspaces deklarieren MIT. `cargo deny check licenses` über den
  gemeinsamen Graphen (Linux, Windows, macOS-Ziel) ist mit einer kleinen Allowlist grün (MIT,
  Apache-2.0 mit und ohne LLVM-Ausnahme, BSD-2/3, ISC, Unicode-3.0, Zlib, MPL-2.0, CC0, 0BSD,
  BSL-1.0). Die **einzige Lizenz, die nur von der Launcher-Seite kommt**, ist `bzip2-1.0.6`
  (`libbz2-rs-sys` über `bzip2` und `zip`); sie ist permissiv (BSD-artig) und wurde in
  `deny.toml` aufgenommen. Lizenzprüfung von Hand ist damit ersetzt, nicht aber die Prüfung von
  `cargo deny check bans/advisories/sources` (nicht Teil des Spikes).

## 5. Deep-Link und Einzelinstanz in der Voice-App (Task 0.5)

Gebaut und im Linux-Test geprüft (Abschnitt 2). Nicht ausführbar im Docker: Registrierung im
Betriebssystem und Weitergabe zwischen zwei echten Prozessen.

### Konfiguration

- `app/Cargo.toml`: `tauri-plugin-deep-link = "2"`, `tauri-plugin-single-instance = "2"` mit
  Feature `deep-link` (leitet Argumente der zweiten Instanz an den `on_open_url`-Handler des
  Deep-Link-Plugins weiter, genau wie im Launcher).
- `app/tauri.conf.json`: `plugins.deep-link.desktop.schemes = ["lanomat"]`.
- `app/src/lib.rs`: `single_instance` **als erstes Plugin**, dann `deep_link`, dann die bisherigen;
  im `setup`-Hook `deeplink_wiring::wire(&handle)`.
- `app/capabilities/default.json`: **unverändert**. Die Rust-Seite braucht keine Berechtigung; das
  Event `deep-link` läuft über `core:default`. `deep-link:default` wird nur gebraucht, wenn die
  WebView die Plugin-API selbst aufruft (der Launcher tut das, die Voice-UI nicht). Damit erfüllt der
  Spike "keine neue WebView-Berechtigung außer höchstens `deep-link:default`" sogar ohne diese.
- `app/src/deeplink.rs`: **unveränderte Kopie** von `clients/launcher/app/src/deeplink.rs`. Er hängt
  nur von `launcher_core::types::is_allowed_join_url` ab (Pfad-Abhängigkeit in `app/Cargo.toml`),
  nicht von der Tauri-Schale. Empfehlung Task 1.4: Parser nach `launcher-core` verschieben, dort
  gehört er hin (keine Tauri-Abhängigkeit, die Tests laufen im `core`-Image).
- `app/src/deeplink_wiring.rs`: Spike-Klebcode. Kaltstart (`get_current`) und Warmstart
  (`on_open_url`) enden im selben Handler; ein gültiger Link wird mit Aktion und Slug geloggt
  (`tracing`, nie die rohe URL, sie kann eine Beitrittsadresse tragen) und als Event `deep-link`
  an die UI gesendet; das Fenster wird in den Vordergrund geholt. **Es wird nichts installiert,
  gestartet oder beigetreten.** Die Bestätigungsabfrage ist Phase 3 (Review-Fokus 4).

### Parser-Tests

- 9 übernommene Tests (gültige Slugs, ungültige Slugs, `install`/`launch`/`play`/`join`,
  harmlose Varianten, schlechte Links ohne Panik, stabiles UI-Payload, `address=`, erlaubte und
  gefährliche `url=`, Adressvalidierung).
- 2 neue im Wiring: der letzte gültige Startparameter gewinnt; kein gültiger Parameter heißt kein
  Link.
- 3 neue feindliche Fälle (`app/tests/deeplink_hostile.rs`): CR/LF-Einschleusung, NUL, doppelter
  Schrägstrich, `;`, `&`, Prozent-kodierter Slug, Laufwerksschreibweise, `@`-Userinfo,
  Fremdschema, Zero-Width-Zeichen im Schema, 100 000 Zeichen lang (kein Panic), feindliche
  `address=` und `url=` (`file:///…`) werden verworfen, der Slug bleibt.

Befund zum Plan: die Akzeptanz sagt "Parser akzeptiert nur `install|launch|join`". Der Parser
akzeptiert zusätzlich `play` als Alias für `launch` (der Web-Button "Starten" sendet `play`).
Entscheidung offen: Alias behalten (Empfehlung, sonst bricht der Web-Button) und im Plan so
vermerken.

### Windows-Besonderheiten

- **Installer (NSIS):** `plugins.deep-link.desktop.schemes` ist die Quelle, aus der der
  Tauri-Bundler den URL-Protokoll-Eintrag (HKCU, `installMode: currentUser`) in das NSIS-Skript
  schreibt. Ob das in unserer Pipeline (Bundler per `--config {"bundle":{"active":true}}`) wirklich
  entsteht, prüft Abschnitt 6 am erzeugten `installer.nsi`.
- **Portable EXE** (die zweite Auslieferung neben dem Installer): kein Registrierungseintrag.
  `register_all()` im `setup` schreibt unter Windows und Linux zur Laufzeit in den Benutzer-Zweig
  (`HKCU\Software\Classes\lanomat`) bzw. legt eine `.desktop`-Datei an. Im Spike wird der Fehler
  nur protokolliert.
- **Einzelinstanz unter Windows** nutzt ein benanntes Objekt/Fenster-Nachricht, nicht den
  Steuer-Socket der App (benannte Pipe für Push-to-Talk). Beide sind unabhängig.
- **Smoke-Auswirkung:** `ci/windows-smoke.ps1` startet absichtlich eine **zweite Kopie** (die
  portable EXE) neben der installierten und erwartet, dass die erste weiter antwortet (Zeilen
  153 bis 161). Mit Einzelinstanz beendet sich die zweite Kopie sofort und reicht ihre Argumente
  weiter. Die Prüfung "erste Instanz antwortet weiter" bleibt wahr, der Text "second copy still
  running" wird falsch und `Stop-App $second` auf einen beendeten Prozess muss abgefangen werden.
  Die Smoke-Datei ist im Spike **nicht** geändert; Plan-Task 0.5 verlangt eine Erweiterung
  (`lanomat://launch/<slug>` gegen die laufende Instanz). Das erfordert eine echte
  Windows-Umgebung.
- **Kein Windows-Host:** Cross-Build mit `cargo-xwin` kompiliert, führt aber nichts aus. Die
  Registry- und Argument-Weitergabe-Prüfung bleibt **manuell** (Checkliste unten).
- **Linux:** der Tarball-Eintrag (`scripts/ops/build-client.sh`) schreibt eine `.desktop`-Datei
  ohne `MimeType`. Für `lanomat://` braucht sie `Exec=lanomat-voice-app %u` und
  `MimeType=x-scheme-handler/lanomat;` plus `update-desktop-database`. **Im Spike nicht
  geändert**, weil das Template sonst bei einem späteren Release stillschweigend die
  `lanomat://`-Zuständigkeit von einem installierten Launcher übernähme (siehe Konflikt-Risiken).
  Unter Wayland/GNOME reicht die `.desktop`-Datei im Benutzerverzeichnis; Flatpak/Snap nicht
  betrachtet.

### Manuelle Smoke-Checkliste (Windows 10/11, einmalig vor Phase 3)

1. Installer ausführen (`installMode: currentUser`, keine UAC-Abfrage). In `regedit` prüfen:
   `HKCU\Software\Classes\lanomat` mit `URL Protocol` und `shell\open\command` auf die installierte
   EXE (`"%1"`-Argument).
2. App starten, im Browser `lanomat://launch/ut2004` aufrufen: Rückfrage des Browsers zulassen;
   **die schon laufende** App kommt nach vorn, kein zweites Fenster, im Log (`RUST_LOG=info`) steht
   `deep link accepted` mit Aktion und Slug, **nicht** die rohe URL.
3. App beenden, denselben Link aufrufen: die App startet kalt, Log zeigt `start-up deep link`.
4. Feindliche Links aus dem Browser (`lanomat://install/../x`, `lanomat://exec/ut2004`,
   `lanomat://install/ut2004%0aX`): kein Log-Eintrag "accepted", nichts passiert.
5. Portable EXE zusätzlich starten: zweiter Prozess endet, erste Instanz antwortet weiter auf
   `lanomat-voice ptt status` (Steuer-Socket unverändert).
6. Ist der alte Launcher (`app.lanomat.launcher`) installiert: welche App öffnet den Link?
   (Erwartung heute: die zuletzt installierte oder registrierte; Phase 3 definiert den Vorrang.)
7. Deinstallieren: der Registry-Zweig verschwindet.

## 6. Windows-Cross-Build (cargo-xwin)

Image `p0-voice-win` (Windows-Stage aus `Dockerfile.dev` mit Rust 1.96, LLVM 21, cargo-xwin 0.23.1,
tauri-cli 2.12.4, NSIS), eigener Cache `p0-xwin` für das Windows-SDK. Skript:
`cargo xwin check --locked --workspace --all-targets --target x86_64-pc-windows-msvc`, danach
`cd app && cargo tauri build --runner cargo-xwin --target x86_64-pc-windows-msvc --bundles nsis --ci
--config '{"version":"0.1.0","bundle":{"active":true}}'` (wie `ci/build.sh`).

| Lauf                                                            | Ergebnis                                                                                                                                                                                                                                                                                   | Zeit                          |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------- |
| `cargo xwin check` (Workspace, alle Ziele, mit `launcher-core`) | grün; `launcher-core`, `libmkiso`, `zip`, `bzip2` und beide Tauri-Plugins übersetzen für Windows                                                                                                                                                                                           | 4m20 (kalt, mit SDK-Download) |
| `cargo tauri build ... --bundles nsis`                          | grün: `lanomat-voice-app.exe` (50,8 MB) und NSIS-Installer `LANoMAT Voice_0.1.0_x64-setup.exe` (14,3 MiB, unsigniert)                                                                                                                                                                      | 6m16 (Release-Build)          |
| `cargo xwin clippy ... -D warnings`                             | **nicht gelaufen**; `cargo xwin check` meldet vier Warnungen (tote Funktionen `fake_open`/`wait_for` in `launcher-core/tests/install_macos.rs` und eine weitere in `install_engine.rs`, nur von `cfg(unix)`-Tests benutzt). Mit `-D warnings` bräche das Windows-Clippy aus `ci/build.sh`. | n. a.                         |

Befunde:

- **Windows-Clippy-Schritt braucht Vorarbeit (Task 1.4):** die Test-Hilfsfunktionen
  in `launcher-core/tests/install_macos.rs` und `install_engine.rs` werden nur von
  `#[cfg(unix)]`-Tests benutzt; unter Windows sind sie tot. Mit `#[cfg(unix)]` (oder
  `#[allow(dead_code)]`) versehen. Linux-Clippy ist grün (Abschnitt 2).
- **Installer und Registry:** der erzeugte Installer ist komprimiert, der Eintrag lässt sich daraus
  nicht ablesen. Belegt ist es auf Quellebene: `tauri-cli` 2.12.4 liest
  `plugins.deep-link.desktop.schemes` (`interface/rust.rs`) und gibt es als `deep_link_protocols`
  an den Bundler weiter; die NSIS-Vorlage des Bundlers 2.10.4 schreibt dafür
  `WriteRegStr SHCTX "Software\Classes\<schema>" "URL Protocol" ""` (bei `installMode:
currentUser` ist `SHCTX` gleich `HKCU`) und entfernt den Zweig beim Deinstallieren. Ob der
  Eintrag im echten Installer steht, prüft Punkt 1 der Checkliste (Abschnitt 5) auf einem
  Windows-Rechner.
- **Version/Pinning:** die Windows-Stage baute unverändert mit Rust 1.96; kein Eingriff in
  `ci/win-version.sh` oder die `windows_subsystem`-Zeile nötig. Der Spike-Build wurde mit
  Version `0.1.0` erzeugt, nicht über `ci/build.sh` (kein `LANOMAT_BUILD_VERSION`).
- Nichts davon wurde ausgeführt (kein Windows-Host, kein Wine): der Smoke bleibt manuell.

## 7. Identität und Konfigverzeichnis (Vorschlag, keine Umbenennung)

| Aspekt                       | Voice                                | Launcher                                                                | Vorschlag                                                                                                                                                                                                                                                       |
| ---------------------------- | ------------------------------------ | ----------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tauri-`identifier`           | `app.lanomat.voice`                  | `app.lanomat.launcher`                                                  | `app.lanomat.voice` bleibt für die gemeinsame App. Der Identifier bestimmt auf Windows und macOS App-Datenordner, Deinstallationseintrag, Autostart und das Einzelinstanz-Objekt; ein neuer Wert würde installierte Voice-Clients "verwaisen".                  |
| `productName`                | `LANoMAT Voice`                      | `LANoMAT Launcher`                                                      | Marke ist entschieden: "LANoMAT". Umbenennung des `productName`/Fenstertitels in Phase 1 mit dem Tab-Umbau, Dateinamen der Artefakte (`lanomat-voice-app-*`) sind Teil des Manifests und brauchen eine eigene Absprache mit Server und Download-Seite (`/app`). |
| Konfigordner (`directories`) | `lanomat-voice` (`credentials.json`) | `("app","LANoMAT","lanomat-launcher")` (`config.json`, `installs.json`) | Beide bleiben bestehen und kollidieren nicht. Die App liest zusätzlich einmalig die alte `installs.json` des Launchers (Task 1.4), den Token nicht.                                                                                                             |
| Binärname                    | `lanomat-voice-app`                  | `lanomat-launcher`                                                      | Binär der App bleibt `lanomat-voice-app`; `lanomat-launcher` bleibt bis Phase 4 parallel.                                                                                                                                                                       |
| URL-Schema                   | keines                               | `lanomat://`                                                            | App registriert `lanomat://` ebenfalls (Konfliktregel Phase 3, siehe Risiken).                                                                                                                                                                                  |

## 8. Empfohlene Crate-Aufteilung (für Task 1.4)

```
clients/voice/
  Cargo.toml                workspace: voice-core, voice-cli, launcher-core, app
  voice-core/               Auth (Device-Flow lvc_), API-Client, Realtime, LiveKit, transfer/ (Manager)
  launcher-core/            Plan-Typen, Plan-Prüfung, Archive (ZIP, tar.bz2, ISO), Install-Engine, Start,
                            deeplink.rs (aus der App hierher), state.rs (installs.json)
  voice-cli/
  app/                      Tauri-Schale: Tabs, Tray, Deep-Link/Einzelinstanz, Kiosk, UI
```

- `launcher-core` hängt **nicht** von `voice-core` ab und umgekehrt; so bleibt `launcher-core` im
  `core`-Image ohne clang/libwebrtc testbar (1m45 kalt statt 7m52). Die App ist der einzige
  Ort, der beides kennt. Das ist die kleinste Aufteilung, die den Merge trägt.
- **Dopplungen, die in Phase 1/2 verschwinden:** `launcher-core::{auth, api, state}` (Device-Flow,
  HTTP, Token) gegen `voice-core::api`. Der Launcher spricht `/api/v1` mit dem MCP-Token, die App
  künftig `/api/voice-client/v1` mit `lvc_`: `launcher-core::api` wird auf den Voice-Client
  umgestellt (oder `launcher-core` bekommt einen schmalen Trait für "Plan holen", den `voice-core`
  implementiert). `launcher-core::download` wird nach Entscheidung 4 (Download-Engine) in den
  Manager überführt.
- Wenn `launcher-core::api`/`auth` entfallen, schrumpft die Kollisionsfläche weiter
  (keine eigene `reqwest`-Konfiguration mehr).
- **Cargo-Features:** `voice` (LiveKit, cpal, PTT) in `voice-core` und App optional machen, damit
  der macOS-Spielebuild (Entscheidung 8) ohne libwebrtc baut. Im Spike nicht umgesetzt.

## 9. Konflikt-Risiken mit Rene (`clients/launcher`)

1. **Kopie driftet.** `clients/voice/launcher-core` und `app/src/deeplink.rs` sind Kopien. Jede
   Änderung an `clients/launcher/launcher-core` oder `app/src/deeplink.rs` nach dem Spike ist beim
   `git mv` (Task 1.4) manuell nachzuziehen. Abgleich per
   `diff -r clients/launcher/launcher-core clients/voice/launcher-core` vor dem Verschieben.
   Empfehlung: Rene meldet Änderungen am Core, oder Task 1.4 geschieht in einem Zug mit seinem
   Einverständnis (einmal `git mv`, danach arbeitet er im neuen Pfad).
2. **`lanomat://`-Zuständigkeit:** App und Launcher registrieren dasselbe Schema. Gewinnt, wer
   zuletzt installiert/registriert hat (Spec Abschnitt 11). Bis zur Phase-3-Regel nichts ausrollen.
3. **Toolchain:** der Merge hebt das Voice-Image auf Rust 1.96. Renes `Dockerfile.dev` bleibt
   unverändert; baut er weiter dort, ist alles wie vorher. Zieht er `launcher-core` später auf neue
   Rust- oder Abhängigkeitsversionen, muss der gemeinsame `Cargo.lock` mitziehen.
4. **Zwei Workflows:** `release-launcher.yml` und `release-client.yml` bleiben getrennt und
   unberührt. Spiele-/Plan-Code des Launchers (macOS-Build, Kiosk) liegt nur dort, bis Phase 4.
5. **Arcade/Kiosk/Heartbeat** (`app/src/{arcade,heartbeat}.rs`) und `app/ui` wurden nicht
   angefasst und sind im Spike nicht Teil der Voice-App.
6. **`libmkiso`/`zip`-Stand:** der Core hängt an `libmkiso` 0.1.5 (Rust 1.96). Ein Downgrade des
   Compilers (z. B. aus Stabilitätsgründen) bricht beide Clients gleichzeitig.
