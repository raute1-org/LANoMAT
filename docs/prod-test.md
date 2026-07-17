# Prod-Deployment einmal testen (Schritt für Schritt)

Diese Anleitung führt dich **einmal** komplett durch das Produktions-Deployment von LANoMAT (FrankenPHP-Image + Compose-`prod`-Profil) inklusive vollständiger **Discord-Einrichtung**. Ziel: bestätigen, dass der prod-Stack läuft, Login/Realtime/Queue/Scheduler funktionieren und Discord (OAuth-Login, Announcements, Slash-Commands, Match-Channels) sauber angebunden ist.

> **Wichtig vorab — HTTPS:** Discord ruft den **Interactions-Endpoint** (`/api/discord/interactions`) und den **OAuth-Callback** über das öffentliche Internet auf und verlangt **HTTPS**. TLS/Reverse-Proxy ist bewusst erst **M7 (Traefik)**. Für diesen einmaligen Test brauchst du also eine **öffentlich erreichbare HTTPS-URL** auf deinen lokalen `app`-Container. Am einfachsten per Tunnel:
> - **Cloudflare Tunnel:** `cloudflared tunnel --url http://localhost:8080` → gibt dir eine `https://<zufall>.trycloudflare.com`-URL.
> - oder **ngrok:** `ngrok http 8080`.
>
> Diese HTTPS-URL ist im Folgenden `APP_URL` (Platzhalter: `https://DEINE-PUBLIC-URL`). Der `app`-Container lauscht im prod-Profil auf Host-Port **8080** (siehe `compose.yml`), darauf zeigt der Tunnel.

---

## Teil 0 — Voraussetzungen

- Docker + Docker Compose v2 (`docker compose version`).
- Dieses Repo ausgecheckt, im Repo-Root.
- Ein Discord-Account mit einem **eigenen Test-Server (Guild)**, auf dem du Admin bist.
- Ein Tunnel-Tool (cloudflared oder ngrok), das dir eine öffentliche HTTPS-URL gibt.
- Deine **Discord-User-ID** (für den Admin): in Discord unter *Einstellungen → Erweitert → Entwicklermodus* aktivieren, dann Rechtsklick auf deinen Namen → *ID kopieren*.

---

## Teil A — Discord-Application einrichten

Alles im **Discord Developer Portal**: <https://discord.com/developers/applications>.

### A1. Application anlegen
1. **New Application** → Name z. B. „LANoMAT" → erstellen.
2. Auf der **General Information**-Seite:
   - **Application ID** kopieren → später `DISCORD_APPLICATION_ID`.
   - **Public Key** kopieren → später `DISCORD_PUBLIC_KEY` (verifiziert die Signatur der Interactions).

### A2. OAuth2 (Login der Teilnehmer)
1. Reiter **OAuth2**:
   - **Client ID** kopieren → `DISCORD_CLIENT_ID`.
   - **Reset Secret** → **Client Secret** kopieren → `DISCORD_CLIENT_SECRET`.
   - Unter **Redirects** hinzufügen: `https://DEINE-PUBLIC-URL/auth/discord/callback`
     (muss **exakt** dem späteren `DISCORD_REDIRECT_URI` entsprechen; das ist per Default `APP_URL` + `/auth/discord/callback`).
   - Speichern.

### A3. Bot anlegen (Announcements, Match-Channels, DMs)
1. Reiter **Bot** → falls nötig **Add Bot**.
2. **Reset Token** → **Bot-Token** kopieren → `DISCORD_BOT_TOKEN` (nur einmal sichtbar!).
3. Unter **Privileged Gateway Intents**: für unseren Zweck (REST, kein Gateway) sind keine Privileged Intents nötig — Standard lassen.

### A4. Bot in deine Guild einladen
1. Reiter **OAuth2 → URL Generator**:
   - **Scopes:** `bot` **und** `applications.commands`.
   - **Bot Permissions:** mindestens `Manage Channels`, `Send Messages`, `View Channels`, `Manage Roles` (für Match-Channel-Overwrites), `Read Message History`.
2. Generierte URL öffnen → Bot auf deinen **Test-Server** einladen.
3. Danach:
   - **Guild-ID** kopieren (Rechtsklick auf den Server → *ID kopieren*) → `DISCORD_GUILD_ID`.
   - Einen **Ankündigungs-Channel** anlegen/wählen → dessen ID kopieren → `DISCORD_ANNOUNCE_CHANNEL_ID`.
   - Optional eine **Kategorie** für Match-Channels anlegen → ID → `DISCORD_MATCH_CATEGORY_ID`.

> **Interactions-Endpoint** (Slash-Commands) setzt du in **Teil D2**, erst wenn der App-Container läuft und öffentlich erreichbar ist — Discord verifiziert die URL beim Speichern per PING und die App muss antworten.

---

## Teil B — `.env` für Prod vorbereiten

Im Repo-Root: `.env` aus der Vorlage ableiten und die Prod-Werte setzen.

```bash
cp .env.example .env
php artisan key:generate            # setzt APP_KEY
```

Dann in `.env` diese Werte setzen/anpassen (die DB-/Redis-Hosts zeigen im prod-Compose-Netz auf die Service-Namen `postgres`/`redis`):

```dotenv
APP_NAME=LANoMAT
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DEINE-PUBLIC-URL

# Datenbank / Cache / Queue (Service-Namen im Compose-Netz)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=lanomat
DB_USERNAME=lanomat
DB_PASSWORD=lanomat
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database
BROADCAST_CONNECTION=reverb

# Reverb (Realtime) — öffentliche Hostdaten für den Browser + Origin-Lockdown
REVERB_APP_ID=lanomat
REVERB_APP_KEY=<beliebiger-zufallsstring>
REVERB_APP_SECRET=<beliebiger-zufallsstring>
REVERB_HOST=DEINE-PUBLIC-URL          # ohne https://, nur der Host
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=DEINE-PUBLIC-URL   # der Prod-Host (statt '*')
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Discord (aus Teil A)
DISCORD_CLIENT_ID=...
DISCORD_CLIENT_SECRET=...
DISCORD_REDIRECT_URI=https://DEINE-PUBLIC-URL/auth/discord/callback
DISCORD_BOT_TOKEN=...
DISCORD_GUILD_ID=...
DISCORD_ANNOUNCE_CHANNEL_ID=...
DISCORD_MATCH_CATEGORY_ID=...          # optional
DISCORD_PUBLIC_KEY=...
DISCORD_APPLICATION_ID=...

# Mumble (optional — nur wenn du Voice mittesten willst)
MUMBLE_ICE_SECRET=<zufall>
MUMBLE_SERVER_PASSWORD=<zufall>
```

> Hinweis: Da die Assets **im Image** gebaut werden (`npm run build` im Dockerfile), müssen die `VITE_REVERB_*`-Werte **vor dem Image-Build** korrekt in `.env` stehen — sie werden zur Build-Zeit in das Frontend eingebacken. Änderst du sie später, neu bauen (`--build`).

---

## Teil C — Prod-Stack bauen & hochfahren

Der prod-Stack läuft über das Compose-**`prod`-Profil** (Services `app`, `queue`, `scheduler`, `reverb-prod`); die Dev-Services (`postgres`, `redis`) sind Teil des Default-Netzes.

```bash
# 1. DB/Redis + das prod-Profil bauen und starten
docker compose --profile prod up -d --build

# 2. warten bis 'app' healthy ist (Healthcheck auf /up)
docker compose ps

# 3. Migrationen + Admin anlegen (deine Discord-User-ID aus Teil 0)
docker compose --profile prod exec app php artisan lanomat:install --admin-discord-id=DEINE_DISCORD_USER_ID

# 4. App-Health prüfen (über den Tunnel)
curl -sf https://DEINE-PUBLIC-URL/up && echo OK
```

Parallel den **Tunnel** laufen lassen (zeigt auf `http://localhost:8080`):
```bash
cloudflared tunnel --url http://localhost:8080     # oder: ngrok http 8080
```

---

## Teil D — Funktionstests (Smoke)

### D1. App & Login
1. `https://DEINE-PUBLIC-URL/` öffnen → Startseite lädt (Signalpult-Design, Graphit + Amber).
2. `/login` → **„Mit Discord anmelden"** → OAuth-Flow → zurück auf die App, eingeloggt.
3. `/admin` → als der in C3 angelegte Admin erreichbar (200); als frischer Teilnehmer 403.

### D2. Slash-Commands / Interactions-Endpoint
1. Im Developer Portal → **General Information** → **Interactions Endpoint URL** setzen auf:
   `https://DEINE-PUBLIC-URL/api/discord/interactions`
   → **Save**. Discord schickt sofort ein signiertes PING; die App verifiziert es (Ed25519, `DISCORD_PUBLIC_KEY`) und antwortet PONG. Speichern muss **grün** durchgehen — schlägt es fehl, stimmt `DISCORD_PUBLIC_KEY` nicht oder die URL ist nicht erreichbar.
2. Slash-Commands in der Guild registrieren:
   ```bash
   docker compose --profile prod exec app php artisan discord:register-commands
   ```
3. In Discord in der Guild `/help`, `/tournament list`, `/schedule`, `/lfg list` testen → die App antwortet.

### D3. Announcements & Match-Channels
1. Im Panel (`/admin`) ein Event auf **Registrierung offen** schalten → im `DISCORD_ANNOUNCE_CHANNEL_ID`-Channel erscheint die Ankündigung (und in der Glocke).
2. Ein kleines Turnier starten (oder ein Match auf „ready") → der Bot legt den Match-Text-Channel an (Kategorie `DISCORD_MATCH_CATEGORY_ID`), räumt ihn nach Abschluss verzögert wieder auf.

### D4. Realtime / Queue / Scheduler
1. **Reverb:** eine Live-Abstimmung (`/events/{slug}/polls`) in zwei Browser-Tabs öffnen, in einem abstimmen → der andere aktualisiert sich live (WebSocket über `wss://DEINE-PUBLIC-URL`). Klappt das nicht: `REVERB_ALLOWED_ORIGINS`/`REVERB_HOST`/`REVERB_SCHEME` prüfen.
2. **Infoscreen:** `/screen/{event-slug}` → rotiert die Szenen; „Sofort einblenden" aus dem Panel erscheint < 2 s.
3. **Queue:** Logs des Queue-Workers `docker compose --profile prod logs -f queue` → verarbeitet Jobs (z. B. Discord-Sends).
4. **Scheduler:** `docker compose --profile prod logs -f scheduler` → `schedule:work` tickt (Reminder, Outbox-Sweep, Tournament-Tick, LFG-Prune, Schedule-Reminders).

### D5. Eigene Docker-Registry (M7 Task 9, roadmap 7.2)

Details/Hintergrund: [`docs/registry-setup.md`](registry-setup.md).

1. `.env` um die Registry-Werte ergänzen (falls noch nicht gesetzt):
   ```dotenv
   REGISTRY_HOST=registry.lan.example
   REGISTRY_USERNAME=...
   REGISTRY_PASSWORD=...
   ```
2. Lokal einmal gegen die Registry einloggen und das `app`-Image manuell pushen (bestätigt Auth, bevor CI es automatisch macht):
   ```bash
   docker login "$REGISTRY_HOST" -u "$REGISTRY_USERNAME" -p "$REGISTRY_PASSWORD"
   docker build -f docker/Dockerfile -t "$REGISTRY_HOST/lanomat/app:test" .
   docker push "$REGISTRY_HOST/lanomat/app:test"
   ```
3. **CI-Push testen:** in GitHub unter *Settings → Secrets and variables → Actions* die Variable `REGISTRY_HOST` und die Secrets `REGISTRY_USERNAME`/`REGISTRY_PASSWORD` setzen, dann einen `v*`-Tag pushen (z. B. `git tag v0.1.0-test && git push origin v0.1.0-test`) → der Workflow `.github/workflows/publish-images.yml` baut und pusht automatisch. Ohne gesetzte `REGISTRY_HOST`-Variable überspringt der Job sich selbst (grün, kein Fehler) — das ist die gewollte Guard-Logik, nicht ein Fehlschlag.
4. **Prod zieht aus der Registry statt lokal zu bauen:** auf dem Deploy-Host `docker login` wie in Schritt 2, dann in `compose.yml` für `app`/`queue`/`scheduler`/`reverb-prod` statt `build:` ein `image: ${REGISTRY_HOST}/lanomat/app:<tag>` verwenden und `docker compose --profile prod pull && docker compose --profile prod up -d` — kein lokaler Node/Composer-Build-Toolchain-Bedarf auf dem eigentlichen LAN-Tag-Host mehr nötig.

### D6. LanCache — separater Host (M7 Task 9, roadmap 7.5)

Details/Hintergrund: [`docs/lancache-setup.md`](lancache-setup.md). **Wichtig:** LanCache läuft NICHT im `compose.yml`-`prod`-Profil — es ist ein separater Host, den LANoMAT nur über SSH fernsteuert.

1. Einen (separaten) Host mit Docker vorbereiten, SSH-Zugriff sicherstellen.
2. Im Panel (`/admin` → **Remote Hosts**) diesen Host registrieren: Name, Hostname/IP, SSH-Port/-User, den privaten SSH-Key einfügen, **Rolle = `lancache`**.
3. **Probe** ausführen → prüft SSH-Erreichbarkeit, pinnt den Host-Key-Fingerprint.
4. **Setup anwenden** (`ApplyLancacheSetup`) → startet auf dem Host per SSH einen `docker run`-Aufruf für `lancachenet/monolithic` (siehe `docs/lancache-setup.md` für den exakten Befehl und die `LANCACHE_*`-`.env`-Werte, die ihn parametrisieren).
5. DNS für Steam/Epic/Battle.net auf den LanCache-Host zeigen lassen (siehe `docs/lancache-setup.md`, Abschnitt 4) und mit einem zweiten Download desselben Spiels bestätigen, dass er aus dem Cache (`HIT` im `docker logs lancache` auf dem LanCache-Host) statt aus dem Internet kommt.

### D7. Filesharing (M7 Task 5/6, roadmap 7.3)

1. Als Teilnehmer `/events/{slug}/files` öffnen → Datei hochladen (Formular postet auf `files.store`). Solange sie nicht freigegeben ist, siehst nur du selbst sie in der Liste (Status „ausstehend").
2. Als Helfer/Orga im Panel die ausstehende Datei freigeben (Moderations-Gate) → sie erscheint jetzt für alle Teilnehmer der Veranstaltung in `/events/{slug}/files` und ist über `files.download` herunterladbar.
3. **Quota/Größe testen:** eine Datei über `FILES_MAX_UPLOAD_MB` (Default 200 MB, siehe `config/files.php`) hochladen → wird abgelehnt; wiederholt hochladen bis `FILES_PER_USER_QUOTA_MB` (Default 500 MB) je Event überschritten ist → weitere Uploads werden abgelehnt, bestehende bleiben bestehen.

### D8. Custom-Docker-Server (M7 Task 3/4, roadmap 7.4)

1. Einen Host registrieren wie in D6 Schritt 1–3, diesmal **Rolle = `gameserver`** (oder `generic`).
2. Im Panel einen `CustomServer`-Eintrag anlegen (Image, Ports, Env, optionaler Befehl) und dem Host zuordnen.
3. **Start** auslösen → `StartCustomServer` baut einen `escapeshellarg`-abgesicherten `docker run`-Befehl und führt ihn per SSH auf dem Host aus; Status wechselt auf „läuft" (oder „fehlgeschlagen" mit `stderr` in `last_output`, falls der Start scheitert).
4. **Stop** auslösen → `docker rm -f` auf demselben Host, Status zurück auf „gestoppt".

---

## Teil E — Teardown

```bash
docker compose --profile prod down          # Container stoppen/entfernen
docker compose --profile prod down -v       # zusätzlich Volumes (DB!) löschen — nur wenn du wirklich alles wegwerfen willst
```
Tunnel beenden (Ctrl-C). Im Developer Portal ggf. die Interactions-Endpoint-URL wieder entfernen, wenn der Tunnel nicht mehr existiert.

---

## Troubleshooting

- **Interactions-URL wird nicht akzeptiert:** App nicht öffentlich erreichbar (Tunnel prüfen), oder `DISCORD_PUBLIC_KEY` falsch. `docker compose --profile prod logs app` ansehen.
- **OAuth-Redirect-Fehler:** die Redirect-URI im Portal muss **exakt** `DISCORD_REDIRECT_URI` entsprechen (inkl. `https://` und `/auth/discord/callback`).
- **WebSocket verbindet nicht:** `REVERB_ALLOWED_ORIGINS` muss den öffentlichen Host enthalten; `REVERB_SCHEME=https`, `REVERB_PORT=443`; die `VITE_REVERB_*` müssen zur Build-Zeit gestimmt haben (`--build` neu bauen).
- **Slash-Commands fehlen:** `discord:register-commands` erneut ausführen; `DISCORD_GUILD_ID`/`DISCORD_APPLICATION_ID`/`DISCORD_BOT_TOKEN` prüfen (Guild-scoped erscheinen sofort, global bis zu 1 h).
- **Bot postet nicht:** Bot ist in der Guild? Hat er `Manage Channels`/`Send Messages`? `DISCORD_ANNOUNCE_CHANNEL_ID` korrekt und der Bot sieht den Channel?

---

## Was für den echten Betrieb noch fehlt (bewusst nach M7)

Dieser Test nutzt (Teil 0 oben) einen Tunnel für HTTPS. **Dieser Tunnel-Workaround kann jetzt durch das M7-Task-8-Traefik-Setup ersetzt werden:** statt `cloudflared`/`ngrok` gegen `localhost:8000` einen echten Traefik-Reverse-Proxy mit TLS (ACME oder selbstsigniert für reines LAN, siehe [`docs/traefik-setup.md`](traefik-setup.md)) vor `app`/`reverb-prod` schalten — `APP_URL`/`APP_DOMAIN` zeigen dann auf eine echte Domain statt eine zufällige Tunnel-URL, und Discords HTTPS-Anforderung ist ohne externes Tunnel-Tool erfüllt. Die eigene Docker-Registry (M7.2, Abschnitt D5 oben) und der separate LanCache-Host (M7.5, Abschnitt D6) sind seit M7 Task 9 ebenfalls Teil dieses Walkthroughs und nicht mehr offen.
