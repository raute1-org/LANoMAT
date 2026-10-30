# Notfall-Handbuch: Restore und Wiederherstellung

Für den Fall, dass etwas kaputtgeht. Alle Befehle laufen auf dem App-Server im
Projektverzeichnis (`/opt/lanomat`), Zugang per `ssh <ssh-user>@<APP_SERVER>`
(echte Werte stehen nicht in diesem öffentlichen Repo). Compose-Kürzel in diesem
Dokument: `dc` = `docker compose --profile prod`.

Stand der Übung: siehe [Abschnitt 3, Restore-Übung](#3-restore-übung-regelmäßig-wiederholen).

## 0. Erst lesen: was ist gesichert, was nicht

| Was | Gesichert? | Wo |
|-----|------------|----|
| Postgres (alle App-Daten) | ja, nächtlich 03:00, 14 Tage | `/var/backups/lanomat/db-<stamp>.sql.gz` |
| Uploads (Volume `lanomat_app_storage`) | ja, nächtlich | `/var/backups/lanomat/storage-<stamp>.tgz` |
| `.env` inkl. `APP_KEY` | **NEIN** | nur auf dem Server |
| Traefik-ACME-Volume (TLS-Zertifikate) | nein, nicht nötig | wird automatisch neu ausgestellt |
| Redis | nein, nicht nötig | nur Cache/Queue/Sessions-Hilfsdaten |
| Pelican-Panel (inkl. dessen `APP_KEY`) auf dem Spielserver-Host | nein, eigenes System | dort sichern, siehe `docs/infra/pelican-panel-wings.md` |
| WireGuard-Schlüssel | nein | auf beiden Hosts, nie im Repo |

**Die Backups liegen auf demselben Host wie die Produktion.** Fällt der Server
oder seine Platte aus, sind Daten und Backups zusammen weg. Owner-Entscheidung:
vorerst keine Off-Host-Kopie, Risiko bewusst akzeptiert. Günstige Option für
später (eine Zeile, z. B. per Cron nach dem Backup):
`rclone copy /var/backups/lanomat <remote>:lanomat-backups --max-age 48h`
(oder `rsync -a /var/backups/lanomat/ <backup-user>@<BACKUP_HOST>:lanomat/`).

**`APP_KEY` ist kritisch.** Die Datenbank enthält verschlüsselte Spalten
(verknüpfte Accounts: Access-/Refresh-Tokens, Remote-Host-SSH-Schlüssel). Ohne
den `APP_KEY` aus der `.env` sind sie unwiederbringlich verloren, auch mit
perfektem DB-Backup (die Restore-Übung entschlüsselt zum Beweis einen Wert mit dem
Produktions-Key). Den `APP_KEY` (am besten die ganze `.env`) einmalig und nach
jeder Änderung **separat und sicher** ablegen: Passwort-Manager oder Offline-Medium
des Owners. Nie ins Repo, nie ins Backup-Verzeichnis, nie in einen Chat.

## 1. Entscheidungsbaum

```
Seite nicht erreichbar / Fehler
|
+-- nach einem Deploy kaputt?                  -> A  Deploy zurückrollen
+-- Container down/crasht, Daten ok?           -> A0 Container neu starten
+-- Datenbank kaputt/Daten falsch/gelöscht?    -> B  DB aus Backup
+-- Uploads (Avatare/Fotos) weg?               -> C  Uploads-Volume
+-- Server/Platte komplett weg?                -> D  Neuaufbau
+-- Zertifikat/Traefik/DNS?                    -> E  Nicht gesichert, siehe dort
```

Vor jeder zerstörerischen Aktion zuerst den **aktuellen** Zustand sichern, auch
wenn er kaputt ist (Abschnitt B, Schritt 1).

### A0. Container down, Daten ok

```bash
cd /opt/lanomat
docker compose --profile prod ps
docker compose --profile prod up -d traefik app queue scheduler reverb-prod postgres redis
docker compose --profile prod logs --tail=100 app
```

Erwartung: `app` wird `healthy`. Kein bares `up -d` ohne Service-Liste (startet
sonst Music Assistant und Voice-Server).

### A. Schlechter Deploy: zurückrollen

`scripts/deploy/deploy.sh` zieht immer `origin/main` (fast-forward). Zum
Zurückrollen die vorherige Version auschecken und manuell bauen:

```bash
cd /opt/lanomat
git log --oneline -8                 # den letzten guten Merge-Commit <GOOD_SHA> wählen
git checkout <GOOD_SHA>              # detached HEAD, bewusst
docker compose --profile prod build traefik app queue scheduler reverb-prod postgres redis
docker compose --profile prod up -d traefik app queue scheduler reverb-prod postgres redis
docker compose --profile prod ps     # app: healthy
```

Migrationen werden dabei **nicht** zurückgerollt. Hat der schlechte Deploy eine
Migration ausgeführt, ist die DB dem alten Code voraus. Meist läuft alter Code
trotzdem (neue Spalten/Tabellen stören nicht). Bricht er, entweder
`docker compose --profile prod exec app php artisan migrate:rollback --step=1`
(nur wenn die Migration ein `down()` hat und keine Daten verliert) oder die DB aus
dem letzten Backup holen (B). Danach den Fix auf `main` mergen und zurück:

```bash
git switch main && git pull --ff-only && scripts/deploy/deploy.sh
```

(Bleibt der Server im detached HEAD, scheitert das nächste `deploy.sh` am
`git pull`.)

### B. Datenbank aus dem neuesten Backup wiederherstellen

Gilt bei defekter DB, falschen oder versehentlich gelöschten Daten. **Ein Backup
ist ein Stand von bis zu 24 h vorher: alles danach ist weg** (Anmeldungen,
Ergebnisse). Das vorher mit dem Owner abklären.

```bash
cd /opt/lanomat
export DC="docker compose --profile prod"

# 0. Bestand sichern, auch wenn kaputt (Notfall-Kopie)
$DC exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' | gzip > /root/pre-restore-$(date +%F_%H%M%S).sql.gz

# 1. Schreibende Dienste stoppen (postgres bleibt an)
$DC stop app queue scheduler reverb-prod

# 2. Backup wählen und prüfen
ls -lh /var/backups/lanomat/
DUMP=/var/backups/lanomat/db-<stamp>.sql.gz
gzip -t "$DUMP" && echo gzip-ok

# 3. Datenbank neu anlegen (Rolle bleibt, Dump setzt sie als Owner voraus)
$DC exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE \"$POSTGRES_DB\" WITH (FORCE)" -c "CREATE DATABASE \"$POSTGRES_DB\" OWNER \"$POSTGRES_USER\""'

# 4. Einspielen
gunzip -c "$DUMP" | $DC exec -T postgres sh -c 'psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null && echo restore-ok

# 5. Dienste starten, fehlende Migrationen nachziehen (Backup älter als der Code)
$DC up -d app queue scheduler reverb-prod
$DC exec -T app php artisan migrate --force
$DC exec -T app php artisan lanomat:preflight
```

Erwartung: `restore-ok`, `migrate` meldet entweder "Nothing to migrate" oder die
nachgezogenen Migrationen, Preflight-Zeile "Datenbank" ist OK. Der Dump enthält
`OWNER TO <db-user>`-Anweisungen: das Einspielen klappt, solange die DB-Rolle
(`POSTGRES_USER` aus der `.env`) existiert. Das tut sie auf demselben Server und
auf einem neuen Server mit derselben `.env` automatisch. Danach auf der Seite
einloggen und stichprobenartig prüfen (Event, Anmeldungen).

Hinweis: Seed-Daten, die nach dem Backup von Hand eingespielt wurden (z. B.
Spiele per Seeder), fehlen nach dem Restore und müssen erneut erzeugt werden.

### C. Uploads-Volume wiederherstellen

```bash
cd /opt/lanomat
docker compose --profile prod stop app queue scheduler
STORAGE=/var/backups/lanomat/storage-<stamp>.tgz
gzip -t "$STORAGE" && tar tzf "$STORAGE" | head -5      # Inhalt ansehen
docker run --rm -v lanomat_app_storage:/data -v /var/backups/lanomat:/backup:ro alpine \
  tar xzf /backup/$(basename "$STORAGE") -C /data
docker compose --profile prod up -d app queue scheduler
```

Das überschreibt gleichnamige Dateien, löscht aber keine neueren. Soll das Volume
vorher geleert werden, ist das eine bewusste Entscheidung (dann zuerst eine Kopie:
`docker run --rm -v lanomat_app_storage:/data alpine tar czf - -C /data . > /root/storage-now.tgz`).

### D. Server komplett verloren: Neuaufbau

Voraussetzung: **`.env` (mit `APP_KEY`)** aus der separaten Ablage (Abschnitt 0) und
ein Backup-Stand. Liegen die Backups nur auf dem verlorenen Server, existieren sie
nicht mehr: dann bleibt nur ein leerer Neustart (`lanomat:install`), alle
verschlüsselten Verknüpfungen müssen neu angelegt werden.

1. Neuen Server bereitstellen, Docker installieren, `ufw` und DNS wie in
   [`docs/deploy-lan-raute1.md`](../deploy-lan-raute1.md) Abschnitt 1 (Firewall: 22, 80, 443).
2. Repo nach `/opt/lanomat` klonen (`git clone <REPO_URL> /opt/lanomat`).
3. `.env` aus der sicheren Ablage nach `/opt/lanomat/.env` legen (`chmod 600`).
   Der `APP_KEY` muss **identisch** sein.
4. Nur die Datenhaltung starten und leer prüfen:
   ```bash
   cd /opt/lanomat
   docker compose --profile prod up -d postgres redis
   ```
5. Backups auf den neuen Server holen (von der Off-Host-Kopie bzw. der geretteten
   Platte) nach `/var/backups/lanomat/`, dann DB wie in **B** (Schritte 3 und 4, die
   DB ist leer, ein `DROP` ist ohne Folgen) und Uploads wie in **C** einspielen.
6. Stack starten: `docker compose --profile prod build` und
   `docker compose --profile prod up -d traefik app queue scheduler reverb-prod postgres redis`,
   danach `docker compose --profile prod exec -T app php artisan migrate --force`.
7. Backup-Cron neu installieren: `sudo scripts/ops/install-backup-cron.sh /opt/lanomat`.
8. **DNS umstellen** (A-Records der App- und Websocket-Domain auf die neue
   IP). Traefik stellt das TLS-Zertifikat erst aus, wenn DNS auf den neuen Server
   zeigt: danach 1 bis 5 Minuten rechnen. Bis dahin zeigt der Browser eine
   Zertifikatswarnung. Zu häufige Fehlversuche drosseln Let's Encrypt (Rate Limits),
   also erst DNS, dann Traefik starten.
9. Externe Integrationen prüfen: Discord-Redirect-URIs und Bot (zeigen auf die
   Domain, nicht auf die IP, meist unverändert), WireGuard-Tunnel zum Venue-Host neu
   aufbauen (`docs/infra/wireguard-tunnel.md`; der neue App-Server braucht ein neues
   Schlüsselpaar, der Venue-Host muss den neuen öffentlichen Schlüssel und die
   neue Endpoint-IP bekommen), Pelican-Panel-Token in der `.env` bleiben gültig.
10. Abnahme: Login, Event-Seite, Check-in-Test, `lanomat:preflight` ohne rote Zeilen.

### E. Was bewusst nicht gesichert wird

- **Traefik-ACME-Volume:** Zertifikate werden beim Start automatisch neu
  ausgestellt (Minuten, siehe D.8).
- **Redis:** nur Cache, Queue und flüchtige Daten. Nach einem Verlust gehen höchstens
  offene Queue-Jobs und Sessions verloren (Nutzer müssen sich neu einloggen).
- **Pelican-Panel (Spielserver-Host):** eigenes System mit eigenem `APP_KEY` im
  Panel-Volume. Dort separat sichern, siehe `docs/infra/pelican-panel-wings.md`.
- **WireGuard-Schlüssel:** liegen nur auf den Hosts. Bei Verlust neue Paare erzeugen.
- **Backups selbst:** liegen auf demselben Host (siehe Abschnitt 0).

## 2. Verifikation nach jedem Restore

```bash
docker compose --profile prod ps
docker compose --profile prod exec -T app php artisan migrate:status --no-ansi | grep -c Pending   # erwartet: 0
docker compose --profile prod exec -T app php artisan lanomat:preflight
```

## 3. Restore-Übung regelmäßig wiederholen

Ein Backup, das nie zurückgespielt wurde, ist nur eine Hoffnung. Die Übung
`scripts/ops/restore-drill.sh` spielt das neueste Backup in einen Wegwerf-Postgres
(Container, Netz und Volume `lanomat-drill-*`, ohne veröffentlichte Ports, Netz
`--internal`), vergleicht Tabellen, Zeilenzahlen, Migrationen, Sequenzen,
Constraints und Indizes mit der Produktion (nur lesend), startet das **aktuelle**
App-Image gegen die wiederhergestellte DB (alle Integrationen leer, Array-Treiber,
kein Redis), prüft `migrate:status`, Preflight, Nutzbarkeit und die Entschlüsselung
mit dem Produktions-`APP_KEY`, und vergleicht das Uploads-Archiv mit dem Live-Volume
(read-only). Es gibt nur Zähler, Größen und Statuszeilen aus, nie Inhalte. Räumt
selbst auf (Trap), `--keep` lässt alles zum Debuggen stehen.

```bash
# Skript liegt im Repo-Checkout auf dem Server
cd /opt/lanomat
nice -n 19 scripts/ops/restore-drill.sh            # neuestes Backup
nice -n 19 scripts/ops/restore-drill.sh --dump /var/backups/lanomat/db-<stamp>.sql.gz
# Exit 0 ok | 1 Restore fehlgeschlagen | 2 Vergleich weicht ab | 3 Setup-Fehler
```

Abweichungen werden eingeordnet: laufend wechselnde Tabellen (`sessions`, `cache`,
`jobs`, `notifications` ...) zählen als erwarteter Drift. Hat die Produktion in einer
anderen Tabelle mehr Zeilen, gilt das nur dann als erklärt, wenn die Zeilen mit
`created_at <= Dump-Zeitpunkt` genau der Zahl im Restore entsprechen. Ist das Backup
älter als das laufende Release, spielt die Übung die fehlenden Migrationen auf der
Scratch-DB nach (`migrate --force`) und vergleicht erneut: so ist bewiesen, dass der
Restore plus Migration zum Produktionsschema führt.

**Rhythmus:** vor der LAN, dann monatlich, und nach jeder Änderung an Backup-Skript,
Postgres-Version oder `.env`-Handling.

### Letzte Übung (2026-10-30, echte Produktions-Backups)

| Schritt | Ergebnis |
|---------|----------|
| Vorprüfung (Marker 2 h, gzip, Platte) | PASS |
| Dump | 18910 Bytes gzip (146 KiB roh), Restore in 1 s |
| Tabellen | 51 = 51, Zeilenzahlen von 46 (vor Migration) bzw. 47 (nach Migration) Tabellen identisch |
| Abweichungen | `sessions`, `cache`, `notifications` (erwarteter Drift); `games` (4 in Produktion, 0 im Restore: erst 30 min nach dem Dump per Seeder angelegt, 0 Produktionszeilen vor dem Dump), `migrations` 68 vs. 67 (Dump älter als das laufende Release) |
| Migrationen | 67 Ran, 1 Pending (Dump älter als Release) -> `migrate --force` -> 68 Ran, 0 Pending, Schema-Fakten (Constraints, Indizes, Sequenzen) identisch |
| Sequenzen | alle >= max(id) |
| Preflight "Datenbank" | OK |
| Nutzbarkeit | users 1, events 1, event_registrations 1, games 0 |
| Entschlüsselung (`linked_accounts.access_token`) | ok mit dem Produktions-`APP_KEY` (`remote_hosts`: keine Zeilen, übersprungen) |
| Uploads | 3 Dateien, 61 Bytes, identisch zum Live-Volume, 3/3 Stichproben-Prüfsummen gleich |
| Gesamtdauer | 12 s |

**Gemessene RTO:** die reine Datenwiederherstellung dauert bei dem heutigen
Datenbestand (146 KiB) wenige Sekunden. Realistische Gesamtzeit für B (DB-Restore im
laufenden Stack, ohne Entscheidungszeit): unter 5 Minuten. Für D (Neuaufbau) bestimmen
Server-Bereitstellung, Image-Build (einige Minuten), DNS und TLS: Größenordnung 1 bis
2 Stunden, wenn `.env` und Backups bereitliegen. Mit wachsenden Daten skaliert die
Restore-Zeit mit der Dump-Größe; die Übung gibt sie jedes Mal aus.

## 4. Nachts um 2 Uhr: Checkliste

1. Ruhig bleiben, **nichts löschen**, kein `down -v`, kein `docker volume rm`.
2. `ssh <ssh-user>@<APP_SERVER>`, `cd /opt/lanomat`, `docker compose --profile prod ps`.
3. Container down? -> A0. Direkt nach einem Deploy? -> A. Daten falsch/weg? -> B.
4. Vor jedem Eingriff Notfall-Kopie (B, Schritt 0).
5. Nach dem Eingriff Abschnitt 2 (Verifikation) ausführen.
6. Orga informieren (Discord/Infoscreen), was weg ist (Daten seit dem letzten
   Backup um 03:00).
7. Kontakte (nicht im Repo, beim Owner hinterlegt): Owner/Admin des Servers,
   Hosting-Support, DNS-Anbieter, Discord-Developer-Portal-Zugang.
8. Am nächsten Tag: Ursache klären, Restore-Übung wiederholen, dieses Handbuch anpassen.
