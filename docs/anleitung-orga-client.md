# Desktop-App bereitstellen: Hinweise für die Orga

Kurze Notiz für alle, die LANoMAT deployen: wie die Desktop-App auf den Server kommt, wie die Versionen
zusammenpassen müssen und woran du erkennst, dass alles stimmt. Die technischen Details stehen in
[`infra/native-client-release.md`](infra/native-client-release.md), die Teilnehmer-Anleitung in
[`anleitung-teilnehmer-desktop-app.md`](anleitung-teilnehmer-desktop-app.md).

## Grundregel: eine Version, Server und App gleich

LANoMAT bietet die App nur an, wenn der Build **genau zur Server-Version** gehört. Die Version ist der
Text von `git describe --tags --always --dirty` (zum Beispiel `m12-463-g0a3b6ff`), erzeugt von
`scripts/ops/lib/version.sh`. Sowohl das Deploy-Skript (Server-Image) als auch der Client-Build benutzen
dieselbe Funktion. In `clients-dist/manifest.json` steht die Version des Builds als `app_version`; der Server
vergleicht sie mit seiner eigenen (und, wenn beide eine kennen, mit dem Commit). Passt es nicht, steht auf
der Seite `/talk/client` „Version passt nicht zu diesem Server", und es gibt keinen Download.

Folge daraus: **Erst den Stand festlegen (committen), dann Server deployen und Client bauen, beides vom selben
Commit.** Ein Build aus einem Verzeichnis mit uncommitteten Änderungen trägt `-dirty` und passt nie zu einem
Server.

## Den Client bauen und bereitstellen

Der Client ist in Rust geschrieben. Auf dem Server und auf deinem Rechner brauchst du **keine Rust-Toolchain**,
alles läuft in Docker (`clients/voice/Dockerfile.dev`).

### Variante 1: Linux direkt auf dem Server

Im Repository-Verzeichnis, auf dem Commit, der gerade deployt ist:

```bash
scripts/ops/build-client.sh
```

Das Skript baut die App und die CLI für Linux x86_64 in Docker und legt sie samt `manifest.json` in
`clients-dist/` ab (Compose bindet dieses Verzeichnis nur lesend in den Container ein). Das Manifest wird
zuletzt geschrieben, ein Besucher sieht also nie einen halben Stand. Es verweigert einen Baum mit
uncommitteten Änderungen; zum Testen gibt es `--allow-dirty`. Der erste Lauf dauert mehrere Minuten
(libwebrtc wird geladen), spätere Läufe sind schneller.

### Variante 2: Linux und Windows über den Release-Workflow

Windows-Builds entstehen nur im GitHub-Workflow `release-client.yml` (Cross-Build, kein Windows-Rechner
nötig). Er startet bei einem Tag `client-v<name>` oder von Hand mit der Eingabe `version`, die genau der
Version des Servers entsprechen muss (zu sehen auf der Seite `/talk/client` oder in `config('app.version')`).
Das Ergebnis ist ein **Entwurf** (Draft) eines GitHub-Releases, den du prüfst und selbst veröffentlichst.
Die Dateien samt `manifest.json` kopierst du anschließend nach `clients-dist/`, **erst die Dateien, die
`manifest.json` zuletzt**. Ein automatischer Abruf beim Deploy ist noch nicht gebaut. Die Windows-Dateien
sind unsigniert; das Manifest sagt `signed: false`. Für Windows entstehen pro Version drei Dateien: der
Installer (`...-windows-x86_64-setup.exe`, NSIS, pro Benutzer ohne Administratorrechte), die portable App
(`...-windows-x86_64-portable.exe`, eine einzelne Datei) und die Kommandozeile (`lanomat-voice-cli-...exe`).
Im Manifest steht dafür das Feld `variant` (`installer`, `portable`, `archive`; ohne Feld gilt `archive`, so
bleiben ältere Manifeste mit der ZIP-Datei gültig). Auf einem Rechner ohne WebView2-Laufzeit braucht der
Installer einmalig Internet; im LAN ohne Internet muss die Laufzeit vorher installiert sein.

Achtung: Ein Tag `client-v...` wird auch für `git describe` zum nächsten Tag. Wer nach dem Taggen das
Server-Image baut, bekommt diesen Tag als Version. Details und die Alternative mit Handstart stehen in
`infra/native-client-release.md`.

## Beim Deploy

`scripts/deploy/deploy.sh` baut alle vier Dienste (`app queue scheduler reverb-prod`) und gibt
`APP_VERSION` und `APP_COMMIT` mit. Liegt in `clients-dist/` ein Build für eine andere Version, schreibt es
den Hinweis „clients-dist holds a client build for another version" und verweist auf `build-client.sh`.
Es baut den Client nicht selbst.

## Prüfen: Preflight-Zeile „Desktop-App-Download"

In der Preflight-Ampel (Admin-Kachel und `php artisan lanomat:preflight`) gibt es die Zeile
**Desktop-App-Download** (nur wenn das Modul WebVoice an ist):

- **grün:** `manifest.json` ist da und passt zur Server-Version.
- **Warnung „Kein Desktop-App-Build veröffentlicht":** noch kein Build in `clients-dist/`.
- **Warnung „… passt nicht zum Server":** der Build ist von einer anderen Version; neu bauen.
- **übersprungen:** `clients-dist` existiert nicht auf dem Server.

Die Zeile steht nie auf „Ausfall": ohne App funktioniert LANoMAT trotzdem, nur der Download fehlt. Sie
warnt aber sicher, bevor sich ein Teilnehmer wundert.

## Mindestversion für Clients (`VOICE_CLIENT_MIN_VERSION`)

Mit der Umgebungsvariable `VOICE_CLIENT_MIN_VERSION` legst du eine Mindestversion fest (leer = keine). Ältere
Clients bekommen vom Server über `GET /api/voice-client/v1/config` (`client.min_client_version`) gesagt, dass
sie aktualisieren müssen. Setze sie nur, wenn eine API-Änderung alte Clients kaputt machen würde, und
nenne den Teilnehmern danach die Adresse `/app`. Die Variable steht in `config/webvoice.php`
(`native.min_client_version`); wie jede `.env`-Änderung braucht sie ein Neuerstellen der Container.

## Typische Stolperfallen

- Nur `app` neu gebaut: `queue`, `scheduler` und `reverb-prod` laufen mit altem Image und ohne Version.
- `REVERB_ALLOWED_ORIGINS` mit Schema geschrieben: es muss der reine Host-Name sein (`lan.example`).
- Client auf dem falschen Commit gebaut: Preflight warnt, die Download-Seite sperrt.
- Nach einem Deploy kein neuer Client gebaut: Preflight warnt, bis `build-client.sh` gelaufen ist.
