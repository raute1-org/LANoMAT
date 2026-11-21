# Admin message — Spiele teilen, Serverbrowser, Rezept-Pull-Requests (copy/paste)

**Stand:** `main` = der Commit, der diese Datei enthält (`git log -1 --format=%h`) · 2026-11-21

---

**Neu auf `main`: Spiele teilen, Beitreten aus dem Serverbrowser, Rezepte als Pull Request**

**Was neu ist**
- **Spiel teilen** (`/games/share`): Teilnehmer laden ein lauffähiges Spiel als `.zip` oder `.tar.bz2` hoch, geben Titel und Startdatei an, fertig ist ein Entwurf.
- **Ein Schritt für die Orga** (`/admin/recipes`): „Dateien freigeben und veröffentlichen“. Danach steht das Spiel unter `/games`, im Launcher und neben seinen Servern, ohne Config-Änderung.
- **Serverbrowser:** jeder Server bietet „Im Launcher beitreten“ (installiert bei Bedarf und tritt bei), „Spiel installieren“ und „Mitspieler suchen“.
- **Rezept-Pull-Requests** (optional, aus): der Rezepttext eines geteilten Spiels geht automatisch als Pull Request ins Repo. Nie Spieldateien.
- **Fix:** „Installieren“ endet nicht mehr im Nichts. Öffnet sich kein Launcher, sagt die Seite das und bietet den Download an.

**1. Deploy**
```bash
cd /opt/lanomat
./scripts/deploy/deploy.sh
docker compose --profile prod exec -T app php artisan lanomat:recipes:sync --json
docker compose --profile prod exec -T app php artisan lanomat:preflight
```
Eine neue Migration (nur neue Spalten). `queue` muss laufen.

**2. Launcher-Build veröffentlichen** (deshalb tat „Installieren“ auf Prod nichts)
```bash
scripts/ops/build-launcher.sh        # Linux; Windows-exe aus dem Workflow „Release launcher“
```
Ergebnis liegt in `clients-dist/launcher/` (nicht in git). Aus diesem Stand bauen: der Launcher hat neue Oberflächen. Danach zeigt Preflight „Launcher-Download“ grün und „Installieren“ führt nach `/launcher`.

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
`/admin/recipes` → Filter „Geteilte Spiele“ → Spiel öffnen → Abschnitt „Start und Launcher“ lesen (Startbefehle!) → „Dateien freigeben und veröffentlichen“. Ausblenden ohne Zurückziehen: Schalter „In der Spieleliste zeigen“.

**5. Kurz testen (zwei PCs)**
1. Als Teilnehmer ein kleines Spiel als `.zip` hochladen und unter `/games/share` teilen.
2. Als Orga freigeben und veröffentlichen.
3. Zweiter Teilnehmer: Spiel steht unter `/games`, im Launcher installieren und starten.
4. Server des Spiels: unter `/servers/all` „Im Launcher beitreten“.
5. PC ohne Launcher: Klick auf „Im Launcher beitreten“ → nach ein paar Sekunden „Kein Launcher-Fenster aufgegangen?“ mit Download.
6. Mit Pull Requests: nach Schritt 2 ist `recipes/<slug>` als Pull Request offen.

**Offen / nicht verifiziert**
- Nichts davon lief auf echter Hardware oder gegen die echte GitHub-API (nur automatische Tests mit Attrappen). Schritt 5 ist dieser Test.
- Geteilte Spiele: nur Windows und Linux, nur Archive, die ohne Installer laufen.
- Aus der ersten Lineup weiter offen: NFSU2-Server auf dem Zielhost, Uploads (`ut2004-base.zip`), Discord-Ankündigung, Zwei-Client-Rennen.

Details: `docs/ops/ADMIN-HANDOFF.md`, `docs/infra/recipe-pull-requests.md`.
