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

Dieser Test nutzt einen Tunnel für HTTPS. Für den echten Prod-Betrieb kommt in **M7.1** ein **Traefik-Reverse-Proxy mit TLS** vor `app`/`reverb`/`admin` (dann keine Tunnel-URL mehr, sondern eine echte Domain), plus eigene Registry (M7.2). Bis dahin ist der Tunnel der pragmatische Weg für einen einmaligen End-to-End-Test.
