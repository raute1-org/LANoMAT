# Setting up LANoMAT for your own LAN, with a coding agent

This guide is written for a **coding agent** that sets LANoMAT up for a human who runs a LAN party.
The human decides, the agent does the typing. Humans can follow it as well; it is the same order as the
[production runbook](../deploy-lan-raute1.md), with the decisions and the pitfalls up front.

The human can start with one sentence:

> Set up LANoMAT for my LAN. Follow `docs/setup/agent-setup.md` in this repository, ask me before every
> decision, and never print or commit a secret.

## Rules for the agent

1. **Ask, do not invent.** Domain names, accounts, server addresses and which features to switch on are the
   human's decisions. Collect them in section 1 before you change anything. Ask with options and give a
   recommendation.
2. **Secrets stay on the target machine.** Generate the ones you can (`APP_KEY`, database password) straight
   into the `.env` on that machine, without printing them. For the ones only the human has (Discord
   client secret, bot token, API keys) the human writes them into the file or pipes them in; they do not
   go into chat, a commit or a log. If you must show a value, show a hash or its length.
3. **This repository is public.** Real hosts, IPs, ids and keys never go into files you commit. Keep your
   notes about the human's setup outside the repository.
4. **Check before you move on.** Every step below ends with a check. Do not continue on a red check.
5. **Stop and ask** before anything destructive or hard to undo: `down -v`, dropping a database, changing DNS
   records that already point at a live site, opening firewall ports on a machine you do not own.
6. Prefer the repository's scripts and documents over improvising: `scripts/deploy/deploy.sh`,
   `lanomat:install`, `lanomat:preflight`, `scripts/ops/backup.sh`. Open the document a step names; do not
   work from memory of an older version.

## 1. Decisions to collect first

| Question | Options | Default |
| --- | --- | --- |
| Just try it, or run it for real? | **Try locally** (section 2, ten minutes, demo login), **run on a server** (section 3) | try locally first |
| Where does it run on the day? | one public server (VPS), a server at the venue, or both (a public entry in the cloud, the app at the venue; see [`docs/infra/app-at-the-venue.md`](../infra/app-at-the-venue.md)) | one public server |
| Domain | the public name, for example `lan.example`; also `ws.lan.example` for websockets | none, needed |
| Discord | a Discord application for login (needed for a real login); a server (guild) id; optionally a bot | login only |
| Who is the first admin? | their Discord user id (Discord: Settings, Advanced, Developer Mode, then right-click the profile, Copy User ID) | none, needed |
| Which features? | start with `MODULES_PROFILE=core`; add what you need from section 5 | `core` |
| Voice | none, TeamSpeak, web voice (LiveKit), or both | none |
| Game servers | none, a Pelican panel, permanent servers in Docker on a remote host | none |
| Backups | where to copy the nightly backup (a second machine); the guide installs the cron job | second machine |

Write the answers down (outside the repository) and read them back to the human once before you start.

## 2. Try it locally (ten minutes, no Discord needed)

Follow [`docs-site/pages/start/quickstart.md`](../../docs-site/pages/start/quickstart.md). In short:

```bash
docker compose up -d
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed --class=ScreenshotSeeder
composer run dev
```

Needs PHP 8.4 (`pgsql`, `redis`, `sodium`, `intl`, `gd`), Composer 2, Node 22 and Docker. Open
`http://localhost:8000/login` and use the demo buttons (they exist only under `APP_ENV=local`).

Check: you see a dashboard with a next match, a ticket and a seat, and `composer check` is green.

## 3. Run it on a server (core profile)

The authoritative runbook is [`docs/deploy-lan-raute1.md`](../deploy-lan-raute1.md); its example names
(`lan.raute1.org`) are the original deployment, replace them with the human's domain. Order:

1. **Server.** Any Linux host with Docker and the Compose plugin, ports **80** and **443** reachable from the
   internet (80 is needed for the Let's Encrypt challenge), 4 GB RAM or more. Keep 22 open for SSH. Do not open
   8000 or 8081; they are bound to loopback. Clone the repository to `/opt/lanomat`.
2. **DNS.** Two A records to the server's public IPv4: the domain and `ws.<domain>`. Check with
   `dig +short <domain> ws.<domain>`. Traefik gets the certificates only after DNS resolves, so create DNS
   first, then start the stack.
3. **Discord application** (Developer Portal): OAuth2 redirect `https://<domain>/auth/discord/callback`.
   The human copies the client id and secret into `.env`. To let only members of one Discord server log in, set
   `DISCORD_LOGIN_GUILD_ID` (section 3a of the runbook).
4. **`.env`.** Start from `.env.example`; the runbook's section 3 lists the values for production. Generate
   `APP_KEY` (`base64:` plus 32 random bytes) and the database password on the server. Set
   `MODULES_PROFILE=core`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_DOMAIN`, `ACME_EMAIL`,
   the database and Redis hosts (`postgres`, `redis`, inside the compose network) and the Discord login values.
   **`REVERB_ALLOWED_ORIGINS` is the host name only, with no scheme** (`lan.example`, not
   `https://lan.example`).
5. **Start the stack.**

   ```bash
   docker compose --profile prod up -d traefik app queue scheduler reverb-prod postgres redis
   ```

   Never run a bare `docker compose --profile prod up -d`: it also starts Music Assistant (host networking)
   and the voice servers. Check: `docker compose --profile prod ps` shows `app` as `healthy`.
6. **First admin.**

   ```bash
   docker compose --profile prod exec app php artisan lanomat:install --admin-discord-id=<the id>
   ```

   It runs the migrations and creates or promotes that account. It can run again safely.
7. **Create the event** in `https://<domain>/admin` (Events): name, date, `max_participants`, ticket
   settings. Advance the status Draft, Announced, Registration with the header actions; the public
   registration form opens in Registration.
8. **Checks** (the runbook's section 7 is the full list): a valid certificate, Discord login end to end, a
   second non-admin account registers and gets a QR ticket, a `wss://ws.<domain>` connection in the browser,
   and `docker compose --profile prod exec app php artisan lanomat:preflight` without red rows for the
   modules that are on.
9. **Backups.** Install the nightly job with `sudo scripts/ops/install-backup-cron.sh /opt/lanomat`, run
   `scripts/ops/backup.sh` once, and copy `/var/backups/lanomat` to another machine (the backups sit on the
   same host as production otherwise). Prove the restore with `scripts/ops/restore-drill.sh`. Details:
   [`docs/ops/restore-runbook.md`](../ops/restore-runbook.md). **Store the `.env` (it holds `APP_KEY`)
   outside the server too:** without the key, encrypted columns in a backup cannot be read.

## 4. Updating later

Run `scripts/deploy/deploy.sh` on the server. It pulls fast-forward only, builds the services, starts them and
migrates. It builds **all of** `app queue scheduler reverb-prod`; building only `app` leaves the other three on an
old image. After you change `.env`, use `up -d` (a plain `restart` keeps the old environment). While the app restarts,
the deploy script keeps a static maintenance page up.

## 5. Add features later

Switch single modules with `MODULE_<KEY>=true` (list in `app/Support/Modules/Module.php`), or move the whole
install to `MODULES_PROFILE=full`. The data stays; recreate the containers and run `migrate`. Each optional
piece has its own document; open it before you set it up.

| Feature | Needs | Document |
| --- | --- | --- |
| Discord bot (announcements, match channels, slash commands) | bot token, guild id, the gateway sidecar `discord-gateway`; the Server Members intent; leave the Interactions URL empty | runbook section 3b |
| TeamSpeak voice | the `teamspeak` and `teamspeak-admin` services; host ports 9989/udp and 30033/tcp open | [`docs/teamspeak-setup.md`](../teamspeak-setup.md) |
| Web voice (LiveKit) | a DNS name `livekit.<domain>` (A record, **not proxied**), ports 7881/tcp and 7882/udp, the config rendered with `scripts/ops/render-livekit-config.sh` and `LIVEKIT_NODE_IP` set to the **public** address | [`docs/infra/livekit-web-voice.md`](../infra/livekit-web-voice.md) |
| Game servers through Pelican | a Pelican panel and Wings node, API tokens | [`docs/gameservers-pelican.md`](../gameservers-pelican.md), [`docs/infra/pelican-panel-wings.md`](../infra/pelican-panel-wings.md) |
| Permanent servers in Docker on a remote host | a registered remote host (SSH key, pinned host key), `docker` group | [`docs/infra/remote-docker-control.md`](../infra/remote-docker-control.md) |
| LanCache and the file host | a venue server with disks; a separate host | [`docs/lancache-setup.md`](../lancache-setup.md), [`docs/infra/file-host.md`](../infra/file-host.md) |
| Steam and Twitch linking | an API key and an OAuth app | runbook section 2 |
| Running at the venue with a public entry | a tunnel and a second Traefik | [`docs/infra/app-at-the-venue.md`](../infra/app-at-the-venue.md) |
| Music, lighting, room audio | hardware at the venue | the matching `docs/*-setup.md` and `docs/infra/` notes |

Switching a feature on that depends on another one fails the module checks: `requires()` in the `Module`
enum lists the dependencies. If a feature depends on something that is not running (for example Pelican
without a panel), switch the module off instead of living with a red Preflight row.

## 6. Moving the app to another server

1. Stop the writers on the old server (`app queue scheduler reverb-prod`, and `discord-gateway` if it
   runs; only one gateway may be connected).
2. `scripts/ops/backup.sh`, then copy the database dump and the uploads archive to the new server.
3. Put the **same `APP_KEY`** in the new `.env`. Compare the two by a hash, never by printing them.
4. Restore the database and the uploads as in the restore runbook (sections B and C), start the stack, run
   `migrate`, run `lanomat:preflight`.
5. Point DNS at the new server last, then stop the old stack but keep it for a while. If a LAN resolver
   overrides the name, remove that override or the LAN keeps reaching the old machine.

## 7. Pitfalls that cost time before

- `APP_KEY` must be identical after a move; encrypted columns (linked accounts, host SSH keys) depend on it.
- `REVERB_ALLOWED_ORIGINS` takes the host only. Reverb also refuses a handshake without an `Origin` header.
- The app and queue reach Reverb through its public name; a host that cannot reach its own public IP
  (hairpin NAT) needs a split-horizon entry or an internal `REVERB_HOST`.
- `docker compose down -v` deletes the database and the uploads. Never run it on a live host.
- `render-livekit-config.sh` writes `docker/livekit/livekit.yaml` itself; do not redirect its output into that
  file, or the status line overwrites the config and LiveKit restarts in a loop.
- A Discord bot needs *Manage Roles* and *Manage Channels* for match channels; the Message Content intent is
  not used.
- Preflight (`lanomat:preflight`, also a tile in the admin panel) is the first place to look: a red row names
  the failing part.
- On a small VPS (4 vCPU, 8 GB) the image build is fine, but run it before the event, not during.
- UI copy exists in German only (`APP_LOCALE=de`). Times are stored in UTC and shown in
  `APP_DISPLAY_TIMEZONE` (default `Europe/Berlin`); set it for your region.
- Unsigned Windows client builds trigger a SmartScreen warning; that is expected.

## 8. Done means

- [ ] The site is reachable by name with a valid certificate; login with Discord works.
- [ ] A non-admin account registered and got a QR ticket; the admin can check people in.
- [ ] `lanomat:preflight` has no red rows for the enabled modules.
- [ ] A backup ran, a copy is on another machine, and a restore was tested.
- [ ] The human has the `.env` stored safely outside the server, and a short note of what was set up.
- [ ] Nothing real was committed; `scripts/ops/check-public-safe.sh` passes if the repository was touched.
