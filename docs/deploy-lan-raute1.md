# Deploy runbook: lan.raute1.org (core profile)

Short runbook for putting LANoMAT live at `https://lan.raute1.org` with
`MODULES_PROFILE=core` (registration, QR tickets, Discord and Steam/Twitch
account linking, notifications). Same app and same database later move to
`MODULES_PROFILE=full` without a data migration (see the last section).

Background: [`docs/traefik-setup.md`](traefik-setup.md) (routing and TLS),
[`docs/prod-test.md`](prod-test.md) (the longer local prod walkthrough).

## 1. DNS and firewall

- A records, both pointing at the server's public IPv4:
  - `lan.raute1.org`
  - `ws.lan.raute1.org` (Reverb websockets)
- Open TCP **80** and **443** on the host. Port 80 is needed for the Let's
  Encrypt HTTP-01 challenge. Do not open 8000 or 8081: they are bound to
  loopback only.
- Check DNS first: `dig +short lan.raute1.org ws.lan.raute1.org`.

## 2. External registrations

- **Discord Developer Portal** (application > OAuth2 > Redirects): add
  `https://lan.raute1.org/auth/discord/callback`. Copy the Client ID and
  Client Secret into `.env` (`DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`).
- **Steam Web API key**: <https://steamcommunity.com/dev/apikey>, domain
  `lan.raute1.org`. Put it in `STEAM_API_KEY`. The Steam entry appears on
  `/settings/connections` once the key is set.

## 3. `.env` on the server

Start from `.env.example` and set at least:

```bash
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # e.g. echo "base64:$(openssl rand -base64 32)"
APP_URL=https://lan.raute1.org
APP_DOMAIN=lan.raute1.org
ACME_EMAIL=<mailbox for Let's Encrypt notices>
MODULES_PROFILE=core
LOG_LEVEL=warning

# Inside the compose network, not 127.0.0.1
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=lanomat
DB_USERNAME=lanomat
DB_PASSWORD=<strong password>
POSTGRES_PASSWORD=<same strong password>   # read by compose's postgres service
REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis

DISCORD_CLIENT_ID=...
DISCORD_CLIENT_SECRET=...
DISCORD_REDIRECT_URI=https://lan.raute1.org/auth/discord/callback
DISCORD_LOGIN_GUILD_ID=<server ID, optional, see section 3a>

STEAM_API_KEY=...
STEAM_REDIRECT_URI=https://lan.raute1.org/settings/connections/steam/callback
STEAM_ALLOWED_HOSTS=lan.raute1.org

REVERB_APP_ID=<random>
REVERB_APP_KEY=<random>
REVERB_APP_SECRET=<random>
REVERB_HOST=ws.lan.raute1.org
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=https://lan.raute1.org
```

Notes:

- `DB_PASSWORD` and `POSTGRES_PASSWORD` must be identical. Postgres reads
  `POSTGRES_PASSWORD` only on the first start, when `pgdata` is empty. Set it
  before the first `up`. Changing it later means `ALTER USER` inside the
  container.
- Leave the Mumble, TeamSpeak, Pelican and Music Assistant keys empty.
  Their modules are off in `core`.
- Compose reads `.env` for interpolation (`APP_DOMAIN`, `ACME_EMAIL`,
  `POSTGRES_*`), and the containers read it through `env_file`. Recreate
  containers after any change (`up -d`). `restart` does not re-read it.

## 3a. Discord login gate (optional)

Set `DISCORD_LOGIN_GUILD_ID` to restrict login to members of one Discord server.
Empty (the default) means anyone with a Discord account can log in.

- **Get the server ID:** in Discord, open *User Settings > Advanced* and enable
  *Developer Mode*. Then right-click the server icon and choose *Copy Server ID*.
- **No bot is needed for this check.** The app asks Discord, on the user's own
  OAuth token, whether that user is a member of the server. This adds the
  `guilds.members.read` scope to the login request. Nothing else changes in the
  Discord application setup.
- **Behaviour:** the check runs on every login. Users who are not members (or
  whose Discord account is not in the server) get a message and no session. If
  Discord cannot answer (outage, rate limit, timeout), login is denied with a
  "try again" message. It never falls through to allowing the user in.
- **Admins are exempt** (an existing user with role `admin`, matched by Discord
  ID), so the operator cannot be locked out.
- Sessions that are already active are not revoked when someone leaves the
  server. They are blocked at their next login.

## 4. Start the stack

```bash
docker compose --profile prod up -d traefik app queue scheduler reverb-prod postgres redis
```

This starts only what `core` needs. Discord gateway, TeamSpeak, Music
Assistant and Mumble stay down. Do not run a bare `docker compose --profile
prod up -d` on this host: it also starts Music Assistant (host networking) and
the voice servers.

Check health: `docker compose --profile prod ps`. `app` should be `healthy`.
`queue` and `scheduler` have no healthcheck by design.

### Updating later

On the host: `/opt/lanomat/scripts/deploy/deploy.sh` (or via
`ssh root@lan.raute1.org /opt/lanomat/scripts/deploy/deploy.sh`). It pulls
`origin/main` fast-forward only, rebuilds with `build --pull` (fresh FrankenPHP/Node base images, so PHP and Node patch releases are picked up on every deploy), recreates the core services,
waits for `app` to be healthy and runs `migrate --force`. It never touches
volumes or `.env`.

## 5. Migrate and create the admin

```bash
docker compose --profile prod exec app php artisan lanomat:install --admin-discord-id=<your Discord user id>
```

This runs `migrate --force` and promotes or creates that account as admin.

## 6. Create the event

1. Open <https://lan.raute1.org>, log in with Discord.
2. Open <https://lan.raute1.org/admin>, go to Events, and create the event:
   name, date, `max_participants`, and the ticket settings (`settings.tickets`).
3. Advance the status with the header actions: **Draft → Announced →
   Registration**. The public form `/events/{slug}/register` only opens in
   Registration.

## 7. Test checklist

- [ ] `https://lan.raute1.org` shows a valid certificate (not Traefik's
      self-signed default).
- [ ] Discord login completes end to end, back to `lan.raute1.org`.
- [ ] A second account (not admin) registers for the event and gets a QR
      ticket.
- [ ] `/settings/connections`: the Steam entry links, and the link is stored.
- [ ] Avatar upload works. Its URL starts with `https://`. (Mixed content or
      `http://` URLs mean the proxy headers are not reaching the app.)
- [ ] Browser devtools show a `wss://ws.lan.raute1.org` connection established.
- [ ] Notifications arrive. `docker compose --profile prod logs -f queue`
      shows the job being processed.
- [ ] Persistence: `docker compose --profile prod down` then `up -d` (no `-v`).
      The avatar still loads and the registration is still there.
- [ ] `docker compose --profile prod exec app php artisan lanomat:preflight`
      shows no red rows for the modules that are enabled.

## 8. Backups

No backup mechanism is built into the repo yet (backlog 1.3). Use cron on the
host. Adjust the paths to where the compose project lives:

```cron
# Database dump, daily at 03:00 (gzip, keep 14 days)
0 3 * * * cd /opt/lanomat && docker compose --profile prod exec -T postgres sh -c 'pg_dump -U lanomat -d "$POSTGRES_DB"' | gzip > /var/backups/lanomat/db-$(date +\%F).sql.gz && find /var/backups/lanomat -name 'db-*.sql.gz' -mtime +14 -delete

# Uploads (volume lanomat_app_storage), daily at 03:10
10 3 * * * docker run --rm -v lanomat_app_storage:/data:ro -v /var/backups/lanomat:/backup alpine tar czf /backup/storage-$(date +\%F).tgz -C /data .
```

Create `/var/backups/lanomat` first. Copy the backups off the host regularly.
A backup that stays on the same disk does not count.

## 9. Later: switch to the full profile

When the full LANoMAT features are needed (Discord bot ingress and
interactions, Jukebox, Gallery, and so on):

1. Set `MODULES_PROFILE=full` in `.env`, or enable single modules with
   `MODULE_<KEY>=true`.
2. Run `docker compose --profile prod up -d <the same service list as in section 4>`.
   Add `discord-gateway` if the Discord bot is needed. The containers must be
   recreated to pick up the new env.
3. Run `docker compose --profile prod exec app php artisan migrate --force`
   for any new migrations.

Users, registrations and linked accounts stay in the same database.

## Troubleshooting

- **Hairpin NAT:** the `app` and `queue` containers reach Reverb through its
  public name `ws.lan.raute1.org`, via Traefik. The host must therefore resolve
  and reach its own public IP, which many home and LAN routers do not allow. If
  broadcasts fail from the server side, check that first. The fix is a
  split-horizon DNS entry on the host or router, or an internal `REVERB_HOST`
  for server-side use (browsers keep the public name).
- **`LOG_LEVEL=warning`** keeps production logs quiet. `debug` writes request
  payload details and is only for short debugging sessions.

## Operational cautions

- `docker compose --profile prod down -v` deletes the database **and** the
  uploads volume. Do not run it on this host unless you want a clean slate.
- Plain `restart` keeps the old environment. Use `up -d` after changing `.env`.
- All prod services use `restart: unless-stopped`, so they come back after a
  host reboot.

## On-site self check-in

Participants can check in with their phone via one printed QR code per event. How orga prints, rotates and what the rules are: [self-checkin.md](self-checkin.md).
