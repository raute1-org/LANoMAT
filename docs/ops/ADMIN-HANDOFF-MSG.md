# Admin message — Spiele teilen, Serverbrowser, Rezept-Pull-Requests (copy/paste)

**Stand:** `main` = der Commit, der diese Datei enthält (`git log -1 --format=%h`) · 2026-11-22

Zwei Runden auf einmal: Spiele teilen, Serverbrowser und Rezept-Pull-Requests (21.11.) plus die
Runde, die sie fertig macht (22.11.: NFSU2-Adresse, Katalog-Vorschau, „Hier spielen“, fehlende
Dateien).

---

**Neu auf `main`: Spiele teilen, Beitreten aus dem Serverbrowser, Rezepte als Pull Request**

**Was neu ist**
- **Spiel teilen** (`/games/share`): Teilnehmer laden ein lauffähiges Spiel als `.zip` oder `.tar.bz2` hoch, geben Titel und Startdatei an, fertig ist ein Entwurf. Die Datei bekommt dabei denselben bereinigten Namen wie beim fortsetzbaren Upload (keine Steuerzeichen, unsichtbaren oder Richtungs-Zeichen, kein Pfad).
- **Ein Schritt für die Orga** (`/admin/recipes`): „Dateien freigeben und veröffentlichen“. Danach steht das Spiel unter `/games`, im Launcher und neben seinen Servern, ohne Config-Änderung.
- **Serverbrowser:** ein Server, dessen Spiel als Rezept in der Lineup veröffentlicht ist, bietet den Launcher-Übergang, „Spiel installieren“ und „Mitspieler suchen“. Er heißt **„Im Launcher beitreten“**, wenn der Link den Server nennt (UT2004 übergibt die Adresse, andere Spiele öffnen ihre Join-URL), sonst **„Im Launcher starten“** — dann installiert und startet der Launcher das Spiel, den Server wählt man im Spiel. Ohne passendes Rezept bleiben Adresse und Verbinden.
- **Rezept-Pull-Requests** (optional, aus): der Rezepttext eines geteilten Spiels geht automatisch als Pull Request ins Repo. Nie Spieldateien.
- **Fix:** „Installieren“ endet nicht mehr im Nichts. Öffnet sich kein Launcher, sagt die Seite das und bietet den Download an.
- **Neu in dieser Runde:** die NFSU2-Serveradresse ist Einstellung, kein Rezepttext (`NFS_SERVER_HOST`) — ein Plan nimmt sie nur von einem Server, dessen Spiel **genau** dieses Rezept ist; die Orga sieht **vor** dem Veröffentlichen, was einen Pull Request blockieren würde („Textprüfung für den Katalog“); ein per MCP beigesteuertes Rezept lässt sich mit `skill_markdown` fertigstellen (die Antwort nennt die offenen Punkte; ein zweiter Aufruf **ersetzt** den Entwurf und braucht das vollständige Rezept); „Hier spielen“ zeigt registrierten Teilnehmern und der Orga die LANoMAT-Konten auf einem Server (wer den Standort verbirgt, fehlt); eine ruhige Zeile sagt, wenn nicht alle Spieldateien auf der LAN liegen; der Agent verweigert Installationsordner, die er nicht zitieren kann, und löst `~/` auf.

**1. Deploy**
```bash
cd /opt/lanomat
./scripts/deploy/deploy.sh
docker compose --profile prod exec -T app php artisan lanomat:recipes:sync --json
docker compose --profile prod exec -T app php artisan lanomat:preflight
```
Diese Runde bringt **keine** neue Migration (nur, falls der letzte Deploy vor dem 21.11. lag: die Sharing-Spalten). `queue` muss laufen. Den Sync diesmal nicht überspringen: das NFSU2-Rezept hat neue Schritt-Texte (`{{server_host}}`) statt einer Adresse, und die Datenbank bekommt sie nur über `lanomat:recipes:sync`. Der Sync überschreibt die Rezeptdatei-Felder, behält aber Status, Datei-Pins, „In der Spieleliste zeigen“, Beitragenden und `pr_*`. Steht `RECIPES_SOURCE` auf einer NAS-Kopie, muss dort die neue `recipe.yaml` liegen — sonst schreibt der Sync den alten Schritt zurück.

**2. Launcher-Build veröffentlichen** (deshalb tat „Installieren“ auf Prod nichts)
```bash
scripts/ops/build-launcher.sh        # Linux; Windows-exe aus dem Workflow „Release launcher“
```
Ergebnis liegt in `clients-dist/launcher/` (nicht in git). Aus diesem Stand bauen: der Launcher hat neue Oberflächen. Ein Build dieses Stands kompiliert und paketiert auf Linux; gestartet und auf Windows gebaut wurde er nicht. Der Launcher geht später in die eine LANoMAT-App über (ADR vom 22.11.); deren „Spiele“-Tab ist bis dahin eine reine Bibliothek (Installieren/Starten ist ein erklärter Platzhalter), also ist der Launcher der Spielerweg.

**Die Spieler finden ihn auf `/app`.** Eine Seite listet alle Downloads der LANoMAT-App: „Sprache und Chat (Desktop-App)“ und „Spiele (Launcher)“, je mit Builds pro System, Version, SHA-256 und Installationsschritten. Jeder Einstieg im Programm heißt **„App herunterladen“** und führt nach `/app` (Kopfzeile, Seitenleiste, Admin-Kopfzeile, Schnellaktionen, der Hinweis unter einem Launcher-Link). `/launcher` und `/talk/client` leiten nur noch dorthin um; die Dateien bleiben unter `/launcher/download/<Datei>`, `/launcher/download-latest` und `/talk/client/download/<Datei>`. Ohne Build sagt die Launcher-Karte „Noch kein Launcher veröffentlicht …“; ohne beide Builds steht auf der Seite „Noch keine App veröffentlicht“. Preflight zeigt **„Launcher-Download“** (und für die Desktop-App **„Desktop-App-Download“**); „Installieren“ auf einer Spielseite führt nach `/app`, solange ein Launcher-Build veröffentlicht ist und das Rezept launcher-fähig ist, sonst feuert es den `lanomat://`-Link.

**3. Pull Requests einschalten** (optional)
Fine-grained GitHub-Token nur für `raute1-org/LANoMAT`, Rechte **Contents** und **Pull requests** je Read and write; am besten ein Bot-Konto. In `/opt/lanomat/.env`:
```dotenv
RECIPES_PR_ENABLED=true
RECIPES_PR_REPOSITORY=raute1-org/LANoMAT
RECIPES_PR_TOKEN=<token>
```
```bash
docker compose --profile prod up -d app queue scheduler reverb-prod
docker compose --profile prod exec -T app php artisan lanomat:preflight   # „Rezept-Katalog (Pull Requests)“
```
Standard: ein Pull Request entsteht, wenn die Orga veröffentlicht (`RECIPES_PR_TRIGGER=publish`). Vor dem Senden prüft LANoMAT den Text auf Adressen, Mails, Schlüssel und Tokens; was durchfällt, wird nicht gesendet und steht im Panel als „Nicht gesendet“ mit Grund. Vor dem Mergen den Diff lesen: Rezept-Schritte sind Befehle auf den PCs der Spieler.

**4. Während der LAN (Orga)**
`/admin/recipes` → Filter „Geteilte Spiele“ → Spiel öffnen → Abschnitt „Start und Launcher“ lesen (Startbefehle!) → darunter „Katalog (Pull Request)“ mit „Textprüfung für den Katalog“ prüfen → „Dateien freigeben und veröffentlichen“. Die Bestätigung nennt die offenen Katalogpunkte noch einmal (und zählt Schritt- und Startbefehle); veröffentlichen geht trotzdem. Ausblenden ohne Zurückziehen: Schalter „In der Spieleliste zeigen“. Nach dem Veröffentlichen nennt sich das geteilte Spiel in Beschreibung, Schritten und `SKILL.md` nicht mehr „Entwurf“.

**5. Kurz testen (zwei PCs)**
1. Als Teilnehmer ein kleines Spiel als `.zip` hochladen und unter `/games/share` teilen (das gerade hochgeladene Archiv ist danach ausgewählt, auch beim einfachen Formular-Upload).
2. Als Orga freigeben und veröffentlichen.
3. Zweiter Teilnehmer: Spiel steht unter `/games`, im Launcher installieren und starten.
4. Server des Spiels: unter `/servers/all` den Launcher-Übergang — „Im Launcher beitreten“, wenn der Link den Server nennt, sonst „Im Launcher starten“.
5. PC ohne Launcher: Klick darauf → nach 2,5 Sekunden „Kein Launcher-Fenster aufgegangen?“ mit **„App herunterladen“**, „Nochmal versuchen“ und Schließen (X); der Klick selbst navigiert nicht.
6. Mit Pull Requests: nach Schritt 2 ist `recipes/<slug>` als Pull Request offen.
7. **NFSU2:** `NFS_SERVER_HOST` in `.env`, App neu erstellen, `db:seed --class=LauncherLineupSeeder --force`; dann einen Installationsplan für `need-for-speed-underground-2` bauen und prüfen, dass die Schritte `host = <dein Host>` zeigen — einmal Windows, einmal Linux. Ohne Serverzeile (oder nur mit dem Browser-Server) bleiben die `host`-Zeilen der `net_u2.ini`, wie sie in der Datei stehen. **Upgrade:** eine Serverzeile aus einem früheren Lauf lässt der Seeder ohne `NFS_SERVER_HOST` unverändert — sie trägt weiter die alte Testadresse, bis du den Host setzt und neu seedest oder die Zeile im Panel bearbeitest.
8. **„Hier spielen“:** als registrierter Teilnehmer: eine Person verbirgt ihren Standort → sie fehlt in der Zeile und in der Zahl, man selbst bleibt sichtbar. Ein Konto ohne Anmeldung zum Event sieht die Zeile gar nicht; ohne `MODULE_PRESENCE=true` niemand.
9. **Agent:** `--target` mit einem Apostroph im Pfad (z. B. `…/O'Brien/Games`) → der Agent verweigert vor dem Download und nennt Zeichen und Ordner; `--target '~/Games/test'` landet im Home-Verzeichnis.

**6. Der ganze Ablauf im Browser (optional, nur Testinstanz)**
```bash
php artisan migrate:fresh --force
php artisan storage:link
php artisan db:seed --class=GameSharingSmokeSeeder
APP_URL=http://127.0.0.1:8000 node scripts/smoke/game-sharing-smoke.mjs
```
**Nie gegen Produktion** — `migrate:fresh` löscht alle Tabellen, und der Seeder verweigert die Production-Umgebung. Der Lauf teilt, prüft die Fehlermeldungen, gibt frei und veröffentlicht, findet das Spiel, prüft die Serverzeile und die Zeile für fehlende Dateien und beobachtet den Launcher-Link in einem Browser ohne Handler. Details: `scripts/smoke/README.md`.

**Verifiziert / nicht verifiziert**
- Im echten Browser gelaufen (headless Chromium, Testdaten, 22.11.): Teilen per Formular-Upload, die Fehlermeldungen, Prüfung und Ein-Klick-Veröffentlichen, das Spiel finden, die Aktionen der Serverzeile, die Zeile für fehlende Dateien, und der Launcher-Link ohne Handler (keine Navigation, kein Dialog, „Öffne den Launcher …“, nach 2,5 s der Hinweis mit „App herunterladen“/„Nochmal versuchen“/Schließen, ein modifizierter Klick startet nichts, ein simulierter Fokusverlust zeigt „Launcher geöffnet. …“ für etwa sechs Sekunden).
- **Nicht verifiziert:** ein echter Launcher, der den Klick übernimmt (simuliert), Firefox und WebKit (nicht installiert), der fortsetzbare Upload, „Hier spielen“ auf der Serverzeile, echte Hardware, Windows, der NFSU2-Schritt, die echte GitHub-API.
- Der NFSU2-Schritt lief nie: weder PowerShell noch bash, weder auf Windows noch unter Wine/Proton, auch nicht der Fall „keine Serverzeile“.
- Die Pfadregeln des Agenten für PowerShell und cmd stammen aus deren Dokumentation; `; & | < >` (überall verweigert) und die `~/`-Auflösung liefen mit einer echten bash, die Windows-Anführungszeichen nicht.
- Geteilte Spiele: nur Windows und Linux, nur Archive, die ohne Installer laufen; Namen ohne Backtick, `*`, `?`, `: " < > |`, ohne führenden Punkt und ohne zwei Punkte hintereinander, mit mindestens drei Buchstaben/Ziffern fürs Dateimuster.
- Das portable NFSU2-Archiv trägt weiterhin einen Host in seiner `net_u2.ini` (mit `make_package.sh --server` gebaut); der Launcher führt keine Schritte aus, deshalb bekommt ein Launcher-Install genau diesen Host. Archiv mit dem endgültigen Host bauen.
- Aus der ersten Lineup weiter offen: NFSU2-Server auf dem Zielhost, Uploads (`ut2004-base.zip`), Discord-Ankündigung, Zwei-Client-Rennen.

Details: `docs/ops/ADMIN-HANDOFF.md`, `docs/infra/recipe-pull-requests.md`.
