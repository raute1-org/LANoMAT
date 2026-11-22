# ADR: All-in-one-Client (LANoMAT-App)

Status: Phase 0 (Klärung), 2026-11-22. Betrifft
[`../specs/2026-11-22-all-in-one-client-design.md`](../specs/2026-11-22-all-in-one-client-design.md)
(Abschnitt 12) und den Plan
[`../plans/2026-11-22-all-in-one-client.md`](../plans/2026-11-22-all-in-one-client.md) (Task 0.1).

Die Entscheidungsnotizen des Repos liegen bisher in `docs/superpowers/specs` und `plans`; es gibt
keine ADR-Konvention. Entscheidungen zu diesem Vorhaben stehen deshalb in
`docs/superpowers/decisions/` (neu, dieses Verzeichnis). Begleitdokumente der Phase 0:

- [Spike Workspace-Merge und Deep-Link](2026-11-22-all-in-one-client-workspace-spike.md) (Tasks 0.2, 0.5)
- [Download-Engine](2026-11-22-all-in-one-client-download-engine.md) (Task 0.3)
- [Rezept-Inventur](2026-11-22-all-in-one-client-recipe-inventory.md) (Task 0.6)
- Plan-API-Entwurf (Task 0.4): macht der Server-Agent, nicht Teil dieser Notiz.

## Kontext

Heute gibt es vier Client-Bausteine mit getrennter Anmeldung (Web-Launcher, Node-Agent, Renes
Rust-Launcher `clients/launcher`, Voice-App `clients/voice`). Ziel ist eine App "LANoMAT" mit
Voice, Chat, Spielebibliothek mit Installation, Server-Beitritt und Event-Infos.

## Entschieden (vom Betreiber)

| Nr. | Frage                    | Entscheidung                                                                                                                                                                                                                                                              |
| --- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Variante                 | **C auf Basis von A.** Renes `launcher-core` wird in den Voice-Cargo-Workspace zusammengeführt; die Voice-App bekommt die Tabs "Spiele" und "Server". Kein Node-Sidecar, kein gebündelter Agent. Der Spieler-Chat bleibt deterministisch.                                 |
| 2   | Name und Marke           | **Eine App "LANoMAT"** im Signalpult-Designsystem (`docs/design.md`), nicht in der UT2004-/Epic-Optik des Launchers (die darf als Stil der Spieleseite weiterleben). Technische Kennungen werden im Spike **nicht** umbenannt (siehe Spike-Bericht, Abschnitt Identität). |
| 14  | Zeitpunkt                | **Sofort beginnen.** Phase 0 und folgende entstehen jetzt in Feature-Branches. Das ersetzt die Empfehlung "Phasen 0 und 1 nach der nächsten LAN". Offen bleibt nur der **Rollout** (Teil von Frage 14, siehe unten).                                                      |
| 3   | Token                    | Voice-Token `lvc_` auf Rezepte erweitern (Option 2); Terminals bleiben vorerst beim MCP-Token.                                                                                                                                                                            | Ein Pairing, kleinerer Berechtigungsumfang auf dem Spieler-PC als der breite MCP-Token. Der Voice-Token bleibt auf `/api/voice-client/*` begrenzt. Der Server-Agent baut genau das (Task 0.4/1.1). | vor Task 1.1 (bereits unterstellt) |
| 4   | Rezepte mit Befehlen     | Kein Shell-Runner. Portable-Archiv, deklarative Schritt-Allowlist (`mkdir`, `extract`, `copy`, `write_file`, optional `registry_set`) oder manuelle Anleitung.                                                                                                            | Die Inventur zeigt: 20 der 26 Befehls-Rezepte brauchen nur diese Handgriffe; sechs laufen über Fremdprogramme, Steam oder Kaufware und bleiben manuell oder ausgeschlossen.                        | vor Phase 2 (Task 2.5)             |
| 5   | Chat mit LLM für Spieler | Nicht in v1; eigene Spec nach dem Orga-Assistenten (Spieler-Tools, Kostenlimit).                                                                                                                                                                                          | Deterministisch ist offline-/LAN-tauglich, kostenfrei und prüfbar.                                                                                                                                 | vor einer Chat-Spec                |
| 6   | Node-Agent               | Als Dev-/Orga-Werkzeug behalten, aus Spielerdokumenten entfernen, Löschung erst nach einer LAN ohne Nutzung.                                                                                                                                                              | Der Agent bleibt für MCP/Rezept-Beiträge nützlich; als Spielerweg ist er durch die App überflüssig.                                                                                                | Phase 4                            |
| 7   | Arcade/Kiosk und Flotte  | Erhalten, als Laufzeitschalter der App.                                                                                                                                                                                                                                   | Heute nur im Launcher (`arcade.rs`, `heartbeat.rs`); darf beim Merge nicht verloren gehen. Terminals bleiben bis dahin auf dem alten Launcher.                                                     | Phase 4                            |
| 10  | Externe Downloads        | Nur mit gepinntem SHA-256 und Opt-in.                                                                                                                                                                                                                                     | Heute tragen nur die UT2004-Patches und ein NFSU2-Mod einen Hash; die Emulator-Releases der Entwürfe haben keinen (Inventur, Abschnitt 5). Pins nachziehen ist Orga-Arbeit.                        | Task 2.5                           |
| 11  | Steam/Epic               | Keine Automatisierung; nur `steam://connect`, strikt validiert.                                                                                                                                                                                                           | Vorhandene Regel (`joinurl.rs`); CS 1.6 bleibt deshalb manuell.                                                                                                                                    | Phase 2                            |
| 12  | "Aktuelles Spiel" teilen | Eigener Opt-in-Schalter, Standard aus.                                                                                                                                                                                                                                    | Datenschutz; passt zu `share_location`.                                                                                                                                                            | Phase 3                            |
| 13  | Update-Verfahren         | Tauri-Updater mit Manifest vom eigenen Server (LAN-first), nur gleiche `api_version`.                                                                                                                                                                                     | Ein Updater statt zwei; Download bleibt "nur in der Server-Version".                                                                                                                               | Phase 5                            |

Auch entschieden, weil von Frage 1 und der Anweisung abhängig: der Spieler-Chat bleibt in der App
deterministisch (das ist Frage 5 in der Empfehlung, hier vom Betreiber mit Variante C bestätigt:
"Spieler-Chat bleibt deterministisch").

Stand 2026-11-22: Die Fragen 3, 4, 5, 6, 7, 10, 11, 12 und 13 wurden vom Betreiber wie empfohlen übernommen (Rückmeldung an Rene durch den Betreiber).

## Entschieden mit Abweichung (vom Betreiber)

Stand 2026-11-22:

| Nr. | Thema      | Entscheidung                                                                                                                                                                                                                                                                                  |
| --- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 8   | macOS      | **Vorerst nicht.** Kein macOS-Build in dieser Phase; Phase 5.3 bleibt zurückgestellt. Die Cargo-Feature-Trennung `voice` bleibt als Vorbereitung im Plan.                                                                                                                                     |
| 9   | Signierung | **Vorerst unsigniert.** Spieler bestätigen die SmartScreen-Warnung. Die Signierung (Authenticode, minisign für den Updater) wird später entschieden; Phase 5.2 bleibt zurückgestellt. Die Updater-Signatur (minisign) ist davon getrennt zu prüfen, bevor der Auto-Update-Weg aktiviert wird. |

Offen bleibt nur der **Rollout-Zeitpunkt** (nichts ausrollen vor einer Echtvenue-Abnahme); die Umsetzung in Feature-Branches läuft sofort.

## Technische Entscheidungen aus Phase 0

Sie folgen aus den Spikes und gelten als Empfehlung der Umsetzung, bis ein Review sie ablehnt. Belege
stehen in den Begleitdokumenten.

1. **Crate-Aufteilung:** `launcher-core` zieht als eigenes Workspace-Mitglied nach
   `clients/voice/launcher-core` (Task 1.4, `git mv`, damit der Verlauf bleibt). `voice-core` und
   `launcher-core` hängen nicht voneinander ab; die App-Schale verbindet sie. Der `lanomat://`-Parser
   (heute `clients/launcher/app/src/deeplink.rs`) wandert in `launcher-core`, weil er nur dessen
   `types` braucht und so ohne Tauri testbar bleibt.
2. **Toolchain:** Rust **1.96** für den gemeinsamen Workspace. Das bisherige Voice-Image (1.91) kann
   `launcher-core` nicht bauen, weil `libmkiso` 0.1.5 rustc 1.96 verlangt; `voice-core` baut und
   testet unter 1.96 mit clang 21 unverändert. `rust-version` des Workspaces wird 1.96,
   `Dockerfile.dev` zieht auf `rust:1.96-bookworm`. Messung im Spike-Bericht.
3. **Identität:** `app.lanomat.voice` bleibt Kennung der zusammengeführten App
   (Konfigverzeichnis, Autostart, Deinstallation), `app.lanomat.launcher` wird in Phase 4
   stillgelegt. Eine Umbenennung auf `app.lanomat.app` wäre sauberer, bricht aber gespeicherte
   Voice-Zugangsdaten und die Windows-Deinstallationsverknüpfung; sie ist eine eigene
   Betreiberentscheidung und wird nicht im Spike vorweggenommen.
4. **Download-Engine:** die Voice-Engine (`voice-core/transfer`, Manager mit Warteschlange,
   Pause/Fortsetzen, Fortschritt, erneuter Link bei jedem Resume) bleibt der Rahmen; aus dem Launcher
   kommen SHA-256-Prüfung, Ursprungsbindung des Bearers, Größenobergrenze und Leerlauf-Timeout. Die
   Launcher-Engine bleibt bis Phase 4 für den Launcher bestehen. Begründung und Prototyp im Dokument
   zur Download-Engine.
5. **Deep-Link und Einzelinstanz** laufen in der Voice-App über `tauri-plugin-deep-link` und
   `tauri-plugin-single-instance` (Feature `deep-link`). Der Spike zeigt, dass der Launcher-Parser
   unverändert übernommen werden kann. Die Windows-Smoke-Prüfung "zweite Kopie läuft neben der
   ersten weiter" ist danach hinfällig und muss umgeschrieben werden (Spike-Bericht).

## Folgen

- Der Server-Teil (`/api/voice-client/v1/recipes`) wird getrennt gebaut; die Client-Typen in
  `voice-core` (Task 1.3) richten sich nach dem Plan-API-Entwurf von Task 0.4.
- Konflikte mit Rene: siehe Spike-Bericht, Abschnitt "Konflikt-Risiken".
- Nichts wird ausgerollt. Der Spike-Code im Branch ist ausdrücklich kein Produktivcode.
