# All-in-one-Client — Download-Engine (Phase 0, Task 0.3)

Stand: 2026-11-22. Vergleich von `clients/voice/voice-core/src/transfer/{download,manager}.rs`
("Voice-Engine") mit `clients/launcher/launcher-core/src/download.rs` ("Launcher-Engine"). Der
Prototyp ist Spike-Code, kein Produktivcode:
`clients/voice/launcher-core/tests/spike_download_renew.rs`. Einordnung im
[ADR](2026-11-22-all-in-one-client-adr.md), Entscheidung 4 der technischen Liste.

## 1. Vergleich

| Eigenschaft      | Voice-Engine (`voice-core/transfer`)                                                                                                                                                                             | Launcher-Engine (`launcher-core/download.rs`)                                                                                                                                    |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Zweck            | Dateihost-Downloads und tus-Uploads der Dateien-Seite, Teil eines Managers                                                                                                                                       | Plan-Downloads für die Installation (ISO, Patches, Avatare)                                                                                                                      |
| Eingabe          | Datei-ID; der **signierte Link wird beim Start und bei jedem Resume neu vom Server geholt** (`api.file_download_link`)                                                                                           | fertige URL im Plan (`DownloadTask.url`); kein Mechanismus, sie zu erneuern                                                                                                      |
| Resume           | `.part` plus `Range: bytes=N-`; bei `206` anhängen (mit Prüfung von `Content-Range`-Start), `200` startet neu, `416` mit passender Gesamtgröße = fertig                                                          | `.part` plus `Range`, aber **nur wenn der Plan einen SHA-256 pinnt** (ein Präfix ohne Hash ist unprüfbar); `206` anhängen, `200` von vorn, `416` einmal ohne `Range` wiederholen |
| Integrität       | keine Prüfsumme, nur Größenvergleich (`size < total` = erneut, `> total` = Fehler)                                                                                                                               | SHA-256 der fertigen Datei muss passen, sonst bleibt nur `.part`; vorhandene Zieldatei wird nur bei passendem Hash wiederverwendet; Größenobergrenze (`Oversized`)               |
| Ziel-Datei       | `unique_path`: überschreibt nie, hängt ` (n)` an                                                                                                                                                                 | überschreibt eine Datei mit falschem Hash, sonst Wiederverwendung (Installationsziel ist deterministisch)                                                                        |
| Namensschutz     | `sanitize_filename` (eine Komponente, Steuerzeichen, Windows-Gerätenamen)                                                                                                                                        | `sanitize_filename` plus `is_hostile_windows_component` (Laufwerkspräfix, `CON`, Punkt am Ende), Fehler statt Bereinigung                                                        |
| Bearer           | optional im `DownloadSpec`, **keine Ursprungsprüfung** (Kommentar: nur Same-Origin, Legacy); Weiterleitungen übernimmt `reqwest` (entfernt den Header bei Host-Wechsel)                                          | `bearer_origin` ist Pflicht für das Senden; Weiterleitungen von Hand (`Policy::none()`, höchstens 1), Token nur an den deklarierten Ursprung                                     |
| Fehlerbehandlung | `retry_or_fail` mit Backoff-Liste (0, 1, 3, 5, 10, 20, 30 s), `Reconnecting`-Phase, 5xx und 429 werden wiederholt; 401/403/404/410 = `link_expired` ("Link neu anfordern"); URL nie in Meldungen (`without_url`) | **keine Wiederholungen**; Fehler sind typisiert (`DownloadError::{Http, IdleTimeout, Incomplete, Checksum, ...}`), der Aufrufer entscheidet                                      |
| Timeouts         | keine eigenen (reqwest-Standard des Managers)                                                                                                                                                                    | Verbindung 15 s, Antwort 60 s, Leerlauf 30 s je Chunk; bei Leerlauf bleibt `.part`                                                                                               |
| Abbruch          | `watch::Receiver<RunState>`: **Pause** (behält `.part`) und **Abbruch** (löscht `.part`), unterbricht auch Backoff und Request                                                                                   | `CancelToken` (`AtomicBool`), geprüft zwischen Chunks; Abbruch behält `.part` (ein Resume ist möglich)                                                                           |
| Fortschritt      | `Reporter { phase, progress(done, total) }`, Manager drosselt, berechnet Geschwindigkeit (`SpeedMeter`) und ETA                                                                                                  | `FnMut(DownloadProgress)` mit `received`, `total`, `bytes_per_second`, gedrosselt auf 200 ms                                                                                     |
| Parallelität     | Manager: Semaphore (3 Downloads, 2 Uploads), Warteschlange, `list`/`get`/`pause`/`resume`/`cancel`/`forget`                                                                                                      | `download_many` **sequentiell**; keine Warteschlange                                                                                                                             |
| I/O              | asynchron (`tokio::fs`)                                                                                                                                                                                          | synchron (`std::fs` und SHA-256 im Async-Kontext; blockiert den Worker bei großen ISOs)                                                                                          |
| Tests            | 11 Unit-Tests gegen einen kleinen Test-Host (Fortschritt, Abbruch der Verbindung, ignoriertes Range, 416, Pause/Abbruch, Namensschutz, kein Link in Meldungen)                                                   | 23 Integrationstests in drei Dateien (`download_resume` 8, `download_security` 11, `download_lifecycle` 4) gegen `wiremock`, dazu Unit-Tests im Modul                            |

## 2. Bewertung

- **Die Voice-Engine ist der bessere Rahmen**, weil sie das hat, was eine App mit Bibliothek und
  mehreren Downloads braucht: Manager mit Warteschlange und Parallelität, Pause/Fortsetzen,
  Phasen (`Reconnecting`), Wiederholung mit Backoff, asynchrones I/O, Geschwindigkeit und ETA und den
  erneuerten signierten Link bei jedem Resume.
- **Die Launcher-Engine hat die Sicherheitseigenschaften, die Plan-Downloads brauchen**: SHA-256
  vor dem Umbenennen, Größenobergrenze, Leerlauf-Timeout, Ursprungsbindung des Bearers mit
  handgeführten Weiterleitungen, "kein Resume ohne Hash". Review-Fokus 2 und 3 des Plans (Bearer geht
  nie an externe Hosts) verlangt genau das.
- Beide Engines sind in ihrem Kern ähnlich (Range, `.part`, 206/200/416). Ein Zusammenführen ist
  deshalb Ergänzen, nicht Neuschreiben.

## 3. Empfehlung

**Voice-Engine bleibt als Rahmen; aus der Launcher-Engine werden fünf Eigenschaften portiert.**
Umsetzung in Phase 2 (Task 2.x, Download im Plan-Pfad), nicht im Spike:

1. **SHA-256** als optionales Feld im `DownloadSpec` (`sha256: Option<String>`); mit Hash: Prüfung
   vor dem Umbenennen (asynchron/in `spawn_blocking`), Wiederverwendung nur bei Treffer, Resume
   nur mit Hash; ohne Hash (z. B. Dateien-Seite) bleibt das heutige Verhalten. Für Plan-Downloads
   von LAN-Dateien ist der Hash laut Spec Pflicht.
2. **Ursprungsbindung des Bearers** (`bearer_origin`) und Redirect-Politik wie im Launcher;
   für signierte Dateihost-Links bleibt `bearer: None` die Regel.
3. **Größenobergrenze** gegen den Plan (`Oversized`) und **Leerlauf-Timeout** je Chunk.
4. **Abbruch/Pause** bleiben `RunState` des Managers (das `CancelToken` des Launchers entfällt).
5. **Signierter Link abgelaufen:** das Manager-Verhalten "Link bei jedem Start/Resume neu holen"
   wird für Plan-Dateien um **automatische Erneuerung innerhalb eines Laufs** erweitert: auf
   401/403/404/410 fordert der Aufrufer einen frischen Link an und setzt am selben `.part` fort
   (höchstens zwei Erneuerungen, danach `link_expired`). Der Prototyp unten belegt, dass die
   Launcher-Engine das tragen kann, weil `.part` am **Dateinamen**, nicht an der URL hängt.

Tests, die mitwandern (Portierung auf den Manager, gleiche Szenarien):
`download_resume` (alle acht), `download_security` (Bearer nur an den deklarierten Ursprung,
Redirect ohne Bearer, zweiter Redirect verweigert, Übergröße, Namensschutz), `download_lifecycle`
(Abbruch lässt Resume zu, kein Start nach Abbruch, Leerlauf-Timeout, Fortschrittsdrosselung). Der
Plan nennt dieselben drei Dateien. Zusätzlich neu: erneuerter Link (Prototyp), Hash-Fehler
löscht die Zieldatei nie still, Parallelität (3) mit Warteschlange.

Verworfen: die Launcher-Engine als Rahmen zu behalten. Ihr fehlen Warteschlange, Wiederholung und
async I/O; das wäre der größere Umbau. Ebenfalls verworfen: beide Engines dauerhaft parallel, das
ist die Doppelung, die der Zusammenschluss beseitigen soll.

## 4. Prototyp: Erneuerung abgelaufener signierter Links

`clients/voice/launcher-core/tests/spike_download_renew.rs`, lokaler Test-Host mit `wiremock`
(Range wird bedient, kein echter Server):

| Test                                                         | Zeigt                                                                                                                                                                                    |
| ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `an_expired_link_is_renewed_and_the_part_file_continues`     | `.part` mit `hello`, alter Link antwortet 403, `renew()` liefert einen frischen Link, dieser bekommt `Range: bytes=5-`, Ergebnis `hello\|world` mit passendem SHA-256, kein `.part` mehr |
| `renewal_gives_up_after_the_limit_and_keeps_the_part`        | bei dauerhaft totem Link genau drei Anfragen (erste plus zwei Erneuerungen), Fehler `Http 410`, `.part` bleibt                                                                           |
| `a_part_without_a_pinned_hash_is_not_resumed_by_this_engine` | dokumentiert den Unterschied: ohne Hash startet die Launcher-Engine von vorn (kein `Range`), die Voice-Engine würde fortsetzen                                                           |

Der Wrapper `download_with_renewal` steht bewusst im Test, nicht in der Bibliothek. Ergebnis der
Läufe steht im [Spike-Bericht](2026-11-22-all-in-one-client-workspace-spike.md).

Nicht geprüft: Abbruch bei 60 Prozent mitten im Strom (der Test-Host bricht keine Verbindung
ab; die Voice-Engine hat dafür bereits `a_dropped_connection_resumes_with_a_range_request`) und
ein Lasttest mit mehreren parallelen Downloads.
