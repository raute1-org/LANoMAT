# Three-server real-infra setup (provisioning checklist)

**Purpose.** The prerequisites and ordered steps to stand up a real
environment so the [pre-LAN acceptance checklist](pre-lan-acceptance-checklist.md)
can be run against real systems instead of fakes. Each phase ends with a check;
do not start the next phase before the previous one is green.

## Topology

| Host | Role | Runs |
|---|---|---|
| **public** (VPS, public IPv4) | Frontend + control plane | Traefik, `app`, `queue`, `scheduler`, `reverb-prod`, Postgres, Redis, `discord-gateway`, Pelican **Panel** |
| **games** (LAN) | Game servers | Pelican **Wings**, custom Docker servers (managed by the Hosts module over SSH), optionally Mumble / TeamSpeak + their sidecars |
| **cache** (LAN) | Downloads | LanCache (separate host, as the Lancache module expects), optional image registry |

**Network:** Tailscale between all three (ACLs: `public` -> `games`/`cache` on
SSH + the panel/Wings ports only). Pangolin/WireGuard are only needed if a LAN
service must be reachable from the internet without open ports — not planned.
Each host gets a wildcard DNS record under its own subdomain, e.g.
`*.app.example.org` -> public, `*.games.example.org`, `*.cache.example.org`.

## 0. What the operator provides

- [ ] SSH key (dedicated user, **root** or sudo) for all three hosts; a snapshot of each host before work starts.
- [ ] Domain + DNS provider name; API token for DNS-01 if a wildcard certificate is wanted (otherwise HTTP-01 per hostname).
- [ ] Discord: application id, public key, bot token, guild id, OAuth client id/secret, announce channel id, match category id, admin Discord user id.
- [ ] Decisions: where Mumble/TeamSpeak run (recommended: `games`, LAN latency); optional Steam API key, Twitch client id/secret, Music Assistant URL/token.
- [ ] Whether the LAN router can override DNS for LanCache (needed for the client-side LanCache check).

## 1. Base (all hosts)

- [ ] OS updates, `ufw`/firewall default-deny, `fail2ban`, unattended security updates.
- [ ] Docker Engine + compose plugin installed; Tailscale joined; hostnames resolve over MagicDNS.
- [ ] `public`: ports 80/443 open (plus 8081 only if Reverb is not proxied via `ws.`); `games`: Mumble 64738 tcp/udp, TeamSpeak 9987/udp + 10011/30033 internal only; `cache`: 53/udp+tcp, 80/443 LAN-only.

## 2. Public host

- [ ] Pick the module profile in the prod `.env` (`MODULES_PROFILE=core|full`, `MODULE_<KEY>` overrides; see README "Project state"). Compose services you can skip when the module is off: `discord-gateway` (no Discord), the `mumble-admin` / `teamspeak-admin` sidecars (no Voice), the Pelican panel/Wings (no GameServers), the registry and LanCache host (no Hosts/Lancache/CustomServers), the Music Assistant integration (no Jukebox). Preflight only probes systems of enabled modules.

- [ ] DNS: `A app.example.org`, `A ws.app.example.org` (+ wildcard if used) -> public IP.
- [ ] Clone repo, create prod `.env`: `APP_ENV=production`, `APP_URL`, `APP_DOMAIN`, `ACME_EMAIL`, `DB_HOST=postgres`, `REDIS_HOST=redis`, `REVERB_*`, `DISCORD_*`, `DISCORD_GATEWAY_BRIDGE_SECRET` (random), `DISCORD_GATEWAY_INGRESS_URL`.
- [ ] `docker compose --profile prod up -d --build`; `php artisan lanomat:install --admin-discord-id=<id>`.
- [ ] **Check:** `https://APP_DOMAIN` serves with a real Let's Encrypt cert; `/up` is 200; `queue`/`scheduler` show no `unhealthy`; Discord login works.
- [ ] `php artisan lanomat:preflight` is green or lists only known not-yet-wired systems.
- [ ] Discord portal: enable the GUILD_MEMBERS intent, **empty** the Interactions Endpoint URL (slash commands then arrive via the Gateway); verify `/help` and `/tournament list`.

## 3. Games host

- [ ] Register as a `RemoteHost` in the admin panel (IP over Tailscale + SSH key); **pin the host key first** (TOFU) before any command.
- [ ] Pelican: Panel on `public` (own subdomain), Wings on `games`, node created, `PELICAN_*` set; start/stop a server from a tournament match and see live stats.
- [ ] Custom Docker server: provision + start through `RemoteExecutor`.
- [ ] Voice (both providers must work): Mumble + `mumble-admin` sidecar and TeamSpeak + `teamspeak-admin` sidecar, `MUMBLE_*`/`TEAMSPEAK_*` set; a tournament start creates channels in **both**, `mumble://` / TeamSpeak join links work from a real client, occupancy shows on the match page.

## 4. Cache host

- [ ] Register as a separate `RemoteHost`; pin host key.
- [ ] LanCache bootstrap via the Lancache module (see [`lancache-setup.md`](lancache-setup.md)); from a **client machine** verify game domains resolve to the cache and a second Steam download hits LAN speed.
- [ ] Optional registry ([`registry-setup.md`](registry-setup.md)): CI pushes a `v*` image, `public` pulls it.

## 5. Acceptance

- [ ] Work through every section of [`pre-lan-acceptance-checklist.md`](pre-lan-acceptance-checklist.md) against these hosts and tick boxes only after observing real behaviour.
- [ ] Record deviations/findings in the roadmap's insights section; fix real bugs on branches with review + PR as usual.
- [ ] Take a fresh snapshot of every host once green; document restore steps (feeds backlog item 1.3 Backup/Restore).
