# Test suite on the Proxmox VM (the app with every module, reached like production)

A second, complete LANoMAT instance on the test VM. It runs `MODULES_PROFILE=full`, is wired to the services on
the same VM (Pelican and Wings, LanCache, Music Assistant, Mumble, TeamSpeak) and to a Discord test server, and it
is reached the way production will be at the venue: public DNS points at the cloud server, the cloud Traefik passes
TLS through over WireGuard, the VM's Traefik terminates it. The live instance shares nothing with it except the
Discord application (see "What is shared"). All hostnames, addresses and keys are placeholders.

Use it to work through [pre-lan-acceptance-checklist.md](../pre-lan-acceptance-checklist.md) on real services, and to
rehearse the move described in [app-at-the-venue.md](app-at-the-venue.md).

## Placeholders

| Placeholder       | Meaning                                                                           |
| ----------------- | --------------------------------------------------------------------------------- |
| `<TEST_DOMAIN>`   | the public name of the suite, with a `ws.` sibling for Reverb                     |
| `<VM_IP>`         | the VM's address (LanCache, Pelican, Music Assistant, Mumble and TeamSpeak ports) |
| `<VM_APP_IP>`     | a second address on the VM, only for the suite's Traefik (see "Ports")            |
| `<CLOUD_SERVER>`  | the live app server, the public entry point                                       |
| `<WG_APP_ADDR>`   | tunnel address of `<CLOUD_SERVER>`; `<WG_VM_ADDR>` the VM's tunnel address        |
| `<TEST_GUILD_ID>` | the Discord test server                                                           |

## Layout

```text
client ─► DNS <TEST_DOMAIN> = <CLOUD_SERVER> ─► cloud Traefik ─(WireGuard, PROXY v2)─► VM Traefik ─► app
LAN client with local DNS <TEST_DOMAIN> = <VM_APP_IP> ──────────────────────────────► VM Traefik ─► app
```

On the VM: `traefik app queue scheduler reverb-prod postgres redis mumble mumble-admin teamspeak teamspeak-admin
discord-gateway` from the compose project, next to the standalone containers that were there before (Pelican panel,
LanCache, Music Assistant) and Wings as a systemd unit. The compose project name is `lanomat`, the project directory
`/opt/lanomat-demo`, a plain clone of this repository.

## Ports: LanCache owns 80 and 443

LanCache binds `<VM_IP>:80` and `<VM_IP>:443`, so the suite's Traefik cannot listen there. Give the VM a second
address and bind only that one (plus the tunnel address, for the cloud entry). A separate netplan file keeps cloud-init
from overwriting it:

```yaml
# /etc/netplan/60-demo-ip.yaml (mode 600)
network:
    version: 2
    ethernets:
        eth0:
            addresses:
                - <VM_APP_IP>/24
```

```yaml
# compose.override.yml (untracked, in the project directory)
services:
    traefik:
        ports: !override
            - '<VM_APP_IP>:443:443'
            - '<VM_APP_IP>:80:80'
            - '<WG_VM_ADDR>:443:443'
            - '127.0.0.1:8090:8080'
        environment:
            CF_DNS_API_TOKEN_FILE: /run/secrets/dns_api_token
        volumes:
            - /var/run/docker.sock:/var/run/docker.sock:ro
            - ${TRAEFIK_STATIC_FILE}:/etc/traefik/traefik.yml:ro
            - ./docker/traefik/dynamic.yml:/etc/traefik/dynamic.yml:ro
            - traefik_acme:/letsencrypt
            - ./secrets/dns_api_token:/run/secrets/dns_api_token:ro
```

`TRAEFIK_STATIC_FILE` points at a copy of `docker/traefik/traefik.venue.example.yml` with the PROXY protocol
trusted address set to `<WG_APP_ADDR>/32` ([app-at-the-venue.md](app-at-the-venue.md)). Mount the dynamic files into
`/etc/traefik/dynamic/` (the venue static file reads that directory), together with the routes for Music Assistant
and the panel ([local-service-tls.md](local-service-tls.md)). Certificates come from the
DNS-01 challenge ([lan-tls-certificates.md](lan-tls-certificates.md)), so no inbound port is needed for issuing.

## `.env` of the suite

Start from the runbook ([deploy-lan-raute1.md](../deploy-lan-raute1.md)) and add what differs. Note the ones that
are easy to miss: without `APP_LOCALE` the pages render without texts, and without `BROADCAST_CONNECTION=reverb`
the instance accepts events and silently does not broadcast them.

```bash
COMPOSE_PROJECT_NAME=lanomat
TRAEFIK_STATIC_FILE=./docker/traefik/traefik.venue.yml
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<TEST_DOMAIN>
APP_DOMAIN=<TEST_DOMAIN>
APP_LOCALE=de
MODULES_PROFILE=full
VOICE_PROVIDERS=mumble,teamspeak
BROADCAST_CONNECTION=reverb
CACHE_STORE=redis
SESSION_DRIVER=database
REVERB_HOST=ws.<TEST_DOMAIN>
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=https://<TEST_DOMAIN>

# Discord: same application as live, but the test server and its own gate
DISCORD_REDIRECT_URI=https://<TEST_DOMAIN>/auth/discord/callback
DISCORD_GUILD_ID=<TEST_GUILD_ID>
DISCORD_LOGIN_GUILD_ID=<TEST_GUILD_ID>
DISCORD_GATEWAY_BRIDGE_SECRET=<random>

# Services on the VM
PELICAN_PANEL_URL=http://<VM_IP>:8080
PELICAN_OWNER_USER_ID=1
PELICAN_DEFAULT_NODE_ID=1
MUSIC_ASSISTANT_URL=http://<VM_IP>:8095
MUMBLE_HOST=<VM_IP>
MUMBLE_ADMIN_REST_URL=http://mumble-admin:8000
TEAMSPEAK_HOST=<VM_IP>
TEAMSPEAK_PORT=9989
TEAMSPEAK_ADMIN_REST_URL=http://teamspeak-admin:8000
```

Keys, tokens and passwords (`DISCORD_CLIENT_*`, `DISCORD_BOT_TOKEN`, `PELICAN_*_TOKEN`, `MUSIC_ASSISTANT_*`,
`MUMBLE_*`, `TEAMSPEAK_*`, `STEAM_*`, `TWITCH_*`) go only into this file (mode 600) on the VM.

Two host firewall rules matter. The app container reaches Music Assistant on the host's port 8095 from the compose
network, which a LAN-only `ufw` rule does not cover: allow the compose network's subnet to that port. The Pelican
panel is published on a Docker port and needs nothing.

Production mode means no demo login: log in through Discord. The first admin comes from
`php artisan lanomat:install --admin-discord-id=<ID>`.

## What is shared with live, and what is not

| Shared                                                        | Separate                                          |
| ------------------------------------------------------------- | ------------------------------------------------- |
| The Discord application (client id and secret, bot account)   | Database, Redis, uploads, `APP_KEY`, sessions     |
| The cloud server's Traefik, one extra router for the SNI name | Discord server and login gate (test server)       |
| The WireGuard tunnel                                          | Voice servers, Pelican, LanCache, Music Assistant |

Because the application is shared, the portal needs `https://<TEST_DOMAIN>/auth/discord/callback` as a second
redirect URI, and only one Gateway session per bot account can exist: the live instance must keep its Gateway sidecar
off while the suite's runs.

## The cloud side

One label-only container on the cloud server's compose network, next to production, is all that routes the test name:

```bash
docker run -d --name venue-entry-test --network <COMPOSE_NETWORK> \
  --label traefik.enable=true \
  --label 'traefik.tcp.routers.venuetest.rule=HostSNI(`<TEST_DOMAIN>`) || HostSNI(`ws.<TEST_DOMAIN>`)' \
  --label traefik.tcp.routers.venuetest.entrypoints=websecure \
  --label traefik.tcp.routers.venuetest.tls.passthrough=true \
  --label traefik.tcp.routers.venuetest.service=venuetest \
  --label traefik.tcp.services.venuetest.loadbalancer.server.port=443 \
  --label traefik.tcp.services.venuetest.loadbalancer.proxyprotocol.version=2 \
  alpine/socat TCP-LISTEN:443,fork,reuseaddr TCP:<WG_VM_ADDR>:443
```

It is the same thing as the `venue-entry` compose profile. Remove it with `docker rm -f venue-entry-test`.

## Updating, resetting, backups

- Update: `git pull --ff-only`, then `docker compose --profile prod up -d --build <services>`. Migrations:
  `docker compose --profile prod exec app php artisan migrate --force`.
- Demo data: `php artisan db:seed --class=ScreenshotSeeder` from a checkout with the dev dependencies, through an SSH
  tunnel to the database port (the production image has no Faker).
- Backups: `scripts/ops/install-backup-cron.sh <project-dir>`, then run `scripts/ops/backup.sh` once so the Preflight
  backup check has its marker.
- The host has little memory to spare. A swap file on the VM is a safety net, not a substitute for watching it.

## Checks

`php artisan lanomat:preflight` covers the database, Redis, storage, queue, scheduler, Reverb, backup and every
configured integration (Discord API and Gateway sidecar, Mumble, TeamSpeak, Pelican, Music Assistant). The Gateway
sidecar has no health endpoint to point `DISCORD_GATEWAY_HEALTH_URL` at, so that line stays "not configured"; look
at the container's log for the login line instead.

## Pitfalls found while building it

- No `APP_LOCALE`: the UI copy exists only in German, so the pages render empty texts (the code default is now `de`).
- No `BROADCAST_CONNECTION`: the default driver is `log`, so Reverb runs and nothing is sent to it.
- A mounted `traefik.yml` makes Traefik ignore every command-line flag and environment variable of the static
  configuration; everything per deployment belongs in the file.
- A local DNS cache can keep the old address for a name after the record changed; a probe inside the stack then
  still resolves the old address until the cache expires.
- The Docker provider ignores a TCP service's `server.address` label and dials the container: use a forwarder.

## Testing against the venue

The suite is where the checklist gets run: Discord login and the gate, registration and check-in, tournaments with
the Discord match channels, voice rooms in both providers, game servers on Pelican, the jukebox, the beamer scenes,
uploads, and the backup and restore drill. Work through it system by system and write what you saw into the
checklist, not into this runbook.
