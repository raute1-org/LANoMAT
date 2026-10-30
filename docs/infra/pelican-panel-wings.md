# Pelican Panel and Wings on the venue host, LanCache DNS for game containers

A runbook for running the Pelican Panel (Docker) and Wings (systemd) on the
venue host from [proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md),
reachable only from the LAN and from the WireGuard tunnel
([wireguard-tunnel.md](wireguard-tunnel.md)), with the game containers using the
LanCache as resolver. It also records the contract check of LANoMAT's
`PelicanClient` against a real Pelican. How LANoMAT uses Pelican (tokens,
environment, presets) is described in
[../gameservers-pelican.md](../gameservers-pelican.md). Every command was run
for real; addresses and secrets are placeholders. The next runbook,
[custom-game-servers-gmod-ttt.md](custom-game-servers-gmod-ttt.md), builds on
this one.

Decisions taken by the owner for the test setup: the panel is reachable only
over LAN and tunnel and **without TLS** (plain HTTP inside the encrypted
tunnel); Wings runs on the host, not in a container.

## Placeholders

| Placeholder                                  | Meaning                                                                          |
| -------------------------------------------- | -------------------------------------------------------------------------------- |
| `<VM_IP>`                                    | LAN address of the venue host (also the LanCache DNS address)                    |
| `<LAN_CIDR>`                                 | subnet of the venue host and the event clients                                   |
| `<ADMIN_WORKSTATION_CIDR>`                   | subnet the administrator's workstation appears from, as seen by the host         |
| `<WG_CIDR>`, `<WG_VM_ADDR>`, `<WG_APP_ADDR>` | tunnel subnet, venue-host and app-server tunnel address (see the tunnel runbook) |
| `<PANEL_URL>`                                | base URL of the panel as LANoMAT uses it, e.g. `http://<WG_VM_ADDR>:8080`        |
| `<PANEL_DIR>`                                | compose project directory on the host, e.g. `/opt/pelican`                       |
| `<PANEL_NET_CIDR>`                           | fixed subnet of the panel's Docker network (any private /16 not used elsewhere)  |
| `<PANEL_GATEWAY>`                            | first address of `<PANEL_NET_CIDR>` (the Docker gateway, seen as trusted proxy)  |
| `<NODE_ID>`                                  | numeric id of the node in the panel (`1` on a fresh panel)                       |
| `<NODE_NAME>`                                | name of the node, e.g. `lan-vm-test`                                             |
| `<ADMIN_USER>`                               | panel administrator username                                                     |
| `<ADMIN_EMAIL>`                              | panel administrator e-mail (use a non-routable address)                          |
| `<ADMIN_PASSWORD>`                           | generated administrator password, root-only file                                 |
| `<APPLICATION_TOKEN>`                        | application API key (prefix `papp_`), root-only file, never committed            |
| `<CLIENT_TOKEN>`                             | client API key (prefix `pacc_`), root-only file, never committed                 |
| `<PANEL_DIGEST>`                             | image digest of the panel image you tested, pinned in the compose file           |
| `<WINGS_VERSION>`                            | Wings release you installed                                                      |
| `<WINGS_URL>`, `<WINGS_CHECKSUM>`            | release asset URL and its published sha256                                       |
| `<SERVER_ID>`                                | server identifier (8 hex characters, Client API)                                 |
| `<SERVER_NUM>`                               | numeric server id (Application API)                                              |
| `<CONTAINER>`                                | Docker container name of a game server (its UUID)                                |
| `<CACHE_ROOT>`                               | cache data root, as in the base runbook                                          |
| `<TZ>`                                       | IANA timezone                                                                    |
| `<DEFAULT_DNS_1>`, `<DEFAULT_DNS_2>`         | the two resolvers Wings ships as defaults (read them from your `config.yml`)     |

## Step 1: Panel in Docker

Facts checked against the Pelican documentation (panel in Docker, Wings
install) at the time of the run:

- The image is `ghcr.io/pelican/panel:latest` (the `pelican-dev` namespace
  answers "denied"). It bundles Caddy, php-fpm, a queue worker and a scheduler.
  Pin the digest for reproducibility. The docs call panel-in-Docker a work in
  progress; accepted for a test, use the standard install or a managed database
  for production.
- The default database of the image is SQLite (fine for the test). Use
  PostgreSQL or MariaDB in a container for production.
- API keys are prefixed `papp_` (application) and `pacc_` (account/client), not
  the Pterodactyl prefixes.
- The official Wings install is **one binary plus a systemd unit**, not a
  container: Wings and the game containers must share host paths. So Wings runs
  on the host (deliberate deviation from "Wings in Docker").

Compose project, bound **only** to the LAN address and the tunnel address:
`<PANEL_DIR>/compose.yml`

```yaml
services:
    panel:
        image: ghcr.io/pelican/panel@sha256:<PANEL_DIGEST>
        restart: unless-stopped
        ports:
            - '<VM_IP>:8080:80'
            - '<WG_VM_ADDR>:8080:80'
        extra_hosts: ['host.docker.internal:host-gateway']
        volumes:
            - pelican-data:/pelican-data
            - pelican-logs:/var/www/html/storage/logs
        environment:
            XDG_DATA_HOME: /pelican-data
            APP_URL: 'http://<VM_IP>:8080'
            BEHIND_PROXY: 'true'
            TRUSTED_PROXIES: '<PANEL_GATEWAY>'
            ADMIN_EMAIL: '<ADMIN_EMAIL>'
            TZ: <TZ>
volumes: { pelican-data: {}, pelican-logs: {} }
networks:
    default:
        ipam:
            config:
                - subnet: <PANEL_NET_CIDR>
```

`BEHIND_PROXY=true` makes Caddy listen on port 80 inside the container
regardless of `APP_URL` and skips Let's Encrypt, which gives plain HTTP for the
test. The fixed subnet is needed for the panel-to-Wings firewall rule below.

```bash
cd <PANEL_DIR> && sudo docker compose config -q && sudo docker compose up -d
```

The first start generates `APP_KEY` into the volume (`/pelican-data/.env`,
`APP_INSTALLED=false`). **Back this key up root-only** (for example
`docker compose exec panel grep ^APP_KEY /pelican-data/.env`): the encrypted
columns (API tokens, node tokens) cannot be read without it.

## Step 2: scripted install (no web installer)

The web installer (`/installer`) is the only official bootstrap; there is no CLI
or environment path. What it does is: migrate and seed, create the admin, import
the chosen eggs, write the session environment, set `APP_INSTALLED=true`. The
scripted equivalent, from `<PANEL_DIR>`:

```bash
# admin password into a root-only file (never on a command line you keep)
sudo sh -c 'umask 077; openssl rand -base64 24 | tr -d "=+/" | cut -c1-24 > /root/<credentials-file>'

sudo docker compose exec -T panel php artisan migrate --force --seed
sudo docker compose exec -T -e PW="<ADMIN_PASSWORD read from the root-only file>" panel sh -c \
  'php artisan p:user:make --email=<ADMIN_EMAIL> --username=<ADMIN_USER> --password="$PW" --admin=1 --no-interaction'
sudo docker compose exec -T panel php artisan p:egg:update-index     # fills cache key eggs.index
sudo docker compose exec -T panel php artisan tinker --execute='foreach(cache()->get("eggs.index") as $n=>$e){foreach($e as $u=>$name){ if(in_array($name,["Paper","Vanilla Minecraft","Counter-Strike 2","Counter-Strike: Source"])) \App\Jobs\InstallEgg::dispatch($u);} }'
sudo docker compose exec -T panel sh -c 'sed -i s/^APP_INSTALLED=.*/APP_INSTALLED=true/ /pelican-data/.env; printf "DB_CONNECTION=sqlite\nCACHE_STORE=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=database\n" >> /pelican-data/.env'
sudo docker compose restart panel
```

**Critical gotcha.** `DB_CONNECTION` must be present in `/pelican-data/.env`.
Without it the **next** container start loops on "Waiting for database
connection... nc: bad port ''": the entrypoint reads `DB_CONNECTION`,
`DB_HOST` and `DB_PORT` from that file and, with `APP_INSTALLED=true` and no
`DB_CONNECTION`, treats the database as external. It worked on the first start
only because `APP_INSTALLED` was still false; the reboot test found it.

Egg import runs through the panel's database queue (about 20 seconds). Passing
the password through `docker compose exec -e` exposes it briefly in the process
list (root only); an automation should use stdin or an env file instead.

Verify from the administrator's workstation: `curl -sI <PANEL_URL>/` returns
`302` (to `/installer` before, `/admin` after) and `curl -sI <PANEL_URL>/admin/login`
returns `200`.

## Step 3: node and Wings

Current Pelican has no "locations": `p:node:make` works without a location id.
Wings API port `8081` and SFTP port `2022` are chosen so they do not collide
with the panel on `8080`.

```bash
sudo docker compose exec -T panel php artisan p:node:make --name=<NODE_NAME> --description="venue host" \
  --fqdn=<VM_IP> --public=1 --scheme=http --proxy=0 --maintenance=0 --maxMemory=<MiB> --overallocateMemory=0 \
  --maxDisk=<MiB> --overallocateDisk=0 --maxCpu=0 --overallocateCpu=0 --uploadSize=256 \
  --daemonListeningPort=8081 --daemonConnectingPort=8081 --daemonSFTPPort=2022 --daemonSFTPAlias= \
  --daemonBase=/var/lib/pelican/volumes --no-interaction           # prints the node id = <NODE_ID>

sudo mkdir -p /etc/pelican /var/run/wings /var/lib/pelican
sudo sh -c 'umask 077; docker compose exec -T panel php artisan p:node:configuration <NODE_ID> > /etc/pelican/config.yml; chmod 600 /etc/pelican/config.yml'
# config.yml holds the node uuid and token: never print it
sudo sed -i 's/^  host: 0.0.0.0/  host: <VM_IP>/' /etc/pelican/config.yml     # Wings API only on the LAN address
```

Install the binary with a checksum check, then the unit from the Pelican
documentation (`User=root`, `WorkingDirectory=/etc/pelican`,
`After`/`Requires`/`PartOf=docker.service`, `Restart=on-failure`):

```bash
sudo curl -fsSL -o /tmp/wings <WINGS_URL>
sha256sum /tmp/wings              # compare with <WINGS_CHECKSUM> from the release checksums file
sudo install -m 0755 /tmp/wings /usr/local/bin/wings
sudo systemctl daemon-reload && sudo systemctl enable --now wings
```

Facts and behaviour:

- The `remote:` value in `config.yml` is rendered from `APP_URL`, so `APP_URL`
  must be an address Wings can reach.
- Wings created its own Docker network (`pelican_nw`, with an IPv6 ULA subnet,
  "configured subnet conflicts, letting Docker auto-assign"), a system user
  `pelican`, and SFTP on `0.0.0.0:2022` (config key `system.sftp.bind_address`;
  set it to `<VM_IP>` for defence in depth).
- After a reboot Wings starts before the panel is healthy and restarts until it
  is (about a minute).

Firewall (ufw) with only what Wings needs. The panel container reaches Wings
through the `INPUT` path from `<PANEL_NET_CIDR>` to `<VM_IP>:8081`; Wings reaches
the panel as host to its own address:

```bash
sudo ufw allow from <LAN_CIDR>               to any port 8081,2022 proto tcp comment "wings from LAN"
sudo ufw allow from <ADMIN_WORKSTATION_CIDR> to any port 8081,2022 proto tcp comment "wings from admin workstations"
sudo ufw allow from <WG_CIDR>                to any port 8081,2022 proto tcp comment "wings from tunnel"
sudo ufw allow from <PANEL_NET_CIDR>         to any port 8081      proto tcp comment "panel container to wings"
```

The last rule was first the whole RFC 1918 `/12` block that Docker networks come from and was narrowed
to the panel network so that **game containers cannot reach the Wings API**.
Panel and game ports are Docker-published and therefore covered by the
`DOCKER-USER` rules (LAN and workstations via the NIC, tunnel subnet via
`wg0`); LAN players need nothing else.

Node health without a browser:

```bash
sudo docker compose exec -T panel php artisan tinker --execute='echo json_encode(App\Models\Node::find(<NODE_ID>)->systemInformation());'
```

returns architecture, CPU count, kernel, OS and the Wings version, meaning the
panel reached Wings with its token (node online). An unauthenticated
`curl http://<VM_IP>:8081/api/system` returns `401`.

## Step 4: allocations and a first test server

Allocations (ip and port the panel may hand to servers) cannot be created with a
minimally scoped application key (`403`); create them once through the panel
(UI or tinker):

```bash
php artisan tinker --execute='foreach([25565,27015] as $p){App\Models\Allocation::create(["node_id"=><NODE_ID>,"ip"=>"<VM_IP>","port"=>$p]);}'
```

A 1 GB Paper test server through the Application API (JSON in a file, `-d @file`,
file removed afterwards):

```json
{
    "name": "mc-test",
    "user": 1,
    "egg": 3,
    "docker_image": "ghcr.io/pelican-eggs/yolks:java_25",
    "startup": "java -Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true -jar {{SERVER_JARFILE}}",
    "environment": {
        "BUILD_NUMBER": "latest",
        "MINECRAFT_VERSION": "latest",
        "SERVER_JARFILE": "server.jar",
        "USER_AGENT": "lanomat-test",
        "PROJECT": "paper"
    },
    "limits": { "memory": 1024, "swap": 0, "disk": 2048, "io": 500, "cpu": 0 },
    "feature_limits": { "databases": 0, "allocations": 1, "backups": 0 },
    "allocation": { "default": 1 },
    "start_on_completion": true
}
```

`201` with `status: installing`; after about a minute `status` is `null` (see
finding 4: that means "installed", not "running"). The first boot stopped on the
Minecraft EULA; fix with the Client API file write and a power signal:

```text
POST /api/client/servers/<SERVER_ID>/files/write?file=%2Feula.txt     body: eula=true   -> 204
POST /api/client/servers/<SERVER_ID>/power    {"signal":"start"}                         -> 204
```

Variable names and defaults come from
`GET /api/application/eggs/<egg>?include=variables`. Evidence: client
`/resources` reported `current_state: running` at about 800 MB, Docker published
`<VM_IP>:25565`, and a Minecraft status ping from the workstation returned the
Paper version. Cleanup: `DELETE /api/application/servers/<SERVER_NUM>` returns
`204`, a `GET` afterwards `404`; container and volume are gone; allocations
remain.

## Step 5: LANoMAT to Pelican, contract check

Done read-only against the repository as it was **before** the `PelicanClient`
rebuild (see [../gameservers-pelican.md](../gameservers-pelican.md) for the
current state). Checked: the contract, `HttpPelicanClient`, the server value
object, the provisioning job and the `services.pelican.*` config.

Authentication: `Authorization: Bearer <token>` with `Accept: application/json`;
the application token for create, get and delete, the **client** token for
power.

Keys are created in the panel UI. There is no artisan command for keys; the test
used the panel's own service in tinker and wrote the token straight into a
root-only file:

```php
$u = App\Models\User::where('username','<ADMIN_USER>')->first();
$k = app(App\Services\Api\KeyCreationService::class)->setKeyType(2 /* 2=application, 1=account */)
   ->handle(['user_id'=>$u->id,'memo'=>'lanomat-application',
             'permissions'=>['server'=>3,'node'=>1,'allocation'=>1,'egg'=>1,'user'=>1]]);  // 0 none, 1 read, 2 write, 3 read+write
// $k->identifier . $k->token is the bearer token: write it to the file, never display it
```

Scope enforcement was verified: `POST /api/application/nodes/<NODE_ID>/allocations`
with this key returns `403`. Account keys carry no scopes. A key can be
restricted with `allowed_ips`; the address to list is the **app server's tunnel
address** `<WG_APP_ADDR>` (post-masquerade source), not a container address.

Results, from the host itself and from a container on the app server over the
tunnel against `<PANEL_URL>` (token piped over SSH stdin into the container,
never stored on the app server):

| Call                                                    | From the venue host | From an app container via tunnel |
| ------------------------------------------------------- | ------------------- | -------------------------------- |
| `GET /api/application/nodes`                            | 200                 | 200                              |
| `GET /api/application/servers`                          | 200                 | 200                              |
| `GET /api/application/servers/{id}`                     | 200                 | 200                              |
| `GET /api/application/eggs`                             | 200                 | 200                              |
| `GET /api/client` (account key)                         | 200                 | 200                              |
| `GET /api/client/servers/{identifier}/resources`        | 200                 | 200                              |
| `POST /api/client/servers/{identifier}/power`           | 204                 | 204                              |
| `POST /api/application/servers` (valid Pelican payload) | 201                 | not run                          |
| `DELETE /api/application/servers/{id}`                  | not run             | 204 (then `GET` 404)             |
| invalid token                                           | not run             | 401                              |

Latency over the tunnel was 75 to 110 ms per call.

### Mismatches found in the pre-rebuild contract

These were blockers for enabling GameServers against a real Pelican and are
what the rebuild addressed (kept here as the reasoning behind the current
design and as a checklist for any future client):

1. `createServer` sent `{max_players, map, password, extra, egg_id, node_id}`;
   real Pelican answers `422`. Required: `name`, `user`, `egg` (not `egg_id`),
   `environment`, `limits{memory,swap,disk,io,cpu}`,
   `feature_limits{databases,allocations,backups}` and `allocation{default}` (or
   `deploy{...}`). `node_id` is not a create field. A client needs a per-game
   mapping (egg id, environment variable names, docker image, startup) and an
   allocation choice (a free port on the node).
2. In the Application API, `attributes.allocation` is an **integer allocation
   id**, not `{ip, port}`. Address and port come from `?include=allocations` or
   `/api/application/nodes/{node}/allocations`.
3. Power calls must use the server **identifier** (8 hex characters) or the
   uuid, not the numeric id: `.../servers/1/power` returned `404`. Store
   `attributes.identifier` next to the numeric id (get and delete use the
   numeric id).
4. Application API `status` is `null` once installed and `installing` during the
   install; running or offline is only in the Client API
   `/resources.current_state`. Mapping `null` to "running" is wrong for a stopped
   or crashed server (the Paper container exited on the EULA while `status`
   stayed `null`). Poll `/resources`.
5. An **account key of a root admin also works on application endpoints**
   (verified with `GET /api/application/servers` using a `pacc_` token). Use a
   dedicated non-admin service user as the owner of LANoMAT servers and issue the
   client key for that user.
6. Documentation and docblocks that mention the prefixes `ptla_`/`ptlc_` are
   outdated; the prefixes are `papp_`/`pacc_`.

## Step 6: LanCache DNS for game containers

Wings key `docker.network.dns` (default: two public resolvers) is applied per
container at (re)creation, so after a Wings restart and a server restart,
including the **installer containers**. Do not use `/etc/docker/daemon.json`
for this: it would also change the resolver of the LanCache's own containers and
of every other compose project on the host.

```bash
sudo cp -p /etc/pelican/config.yml /root/<config-backup>
sudo python3 - <<'PY'
p="/etc/pelican/config.yml"; s=open(p).read()
old="    dns:\n    - <DEFAULT_DNS_1>\n    - <DEFAULT_DNS_2>\n"; assert old in s
open(p,"w").write(s.replace(old,"    dns:\n    - <VM_IP>\n"))
PY
sudo systemctl restart wings   # then power-restart each game server so its container is recreated
sudo docker inspect <CONTAINER> --format '{{json .HostConfig.Dns}}'     # ["<VM_IP>"]
```

The file holds the node token: edit it by script, do not print it, keep edits to
existing keys (Wings rewrites the file at start with defaults).

Verification **inside** a Wings-created container (Docker's embedded resolver
forwards to `<VM_IP>`):

```bash
docker exec <CONTAINER> getent hosts lancache.steamcontent.com   # -> <VM_IP> (steam.cache.lancache.net)
docker exec <CONTAINER> getent hosts download.windowsupdate.com  # -> <VM_IP> (wsus.cache.lancache.net)
docker exec <CONTAINER> getent hosts example.com                 # -> real upstream addresses
```

The RPZ passthrough of the cache DNS does not bite: the cache sees container
traffic from the Docker bridge, not from `<VM_IP>`. DNS to `<VM_IP>` is a local
`INPUT`/DNAT path, and the cache DNS forwards upstream to the gateway, which the
egress isolation allows.

MISS then HIT through a container, with no `--resolve` override. The object from
the base runbook was already cached, so for a clean MISS exactly one cache file
was removed (`grep -rl <object> <CACHE_ROOT>/cache`, confirm the `KEY:` header,
`rm` that single file):

```bash
for i in 1 2 3; do docker exec <CONTAINER> curl -s -o /dev/null -w "%{http_code} %{size_download} %{remote_ip}\n" \
  http://download.windowsupdate.com/msdownload/update/v3/static/trustedr/en/disallowedcert.stl; done
# 200 <size> <VM_IP>  (three times); access.log: one MISS followed by two HIT within the same second
```

Gotchas: an origin `404` is not cached (a missing object is a MISS every time);
a single DNS server means game containers lose DNS if the cache DNS is down, but
a second resolver would let clients bypass the cache silently, so a single one
was chosen; the real SteamCMD result is in the next runbook.

## Step 7: resources (idle, panel + Wings + LanCache, no game server)

| Component               | Memory   | CPU    |
| ----------------------- | -------- | ------ |
| Pelican panel container | ~320 MiB | ~0.1 % |
| LanCache monolithic     | ~60 MiB  | ~0 %   |
| LanCache DNS            | ~85 MiB  | ~0 %   |
| Wings (systemd)         | ~36 MiB  | ~0 %   |

Host: about 0.8 GB used of 8 GB, about 4 GB of 200 GB disk used, load 0.05 on 4
vCPU. A running 1 GB Paper server added about 800 MB resident and about 1 % CPU
idle. WireGuard idle traffic is about 1 KB.

## Services and ports (final state, venue host)

| Service                | Bind                                | Firewall layer                                                |
| ---------------------- | ----------------------------------- | ------------------------------------------------------------- |
| WireGuard `wg0`        | outbound to the app server          | isolation unchanged                                           |
| Pelican panel (Docker) | `<VM_IP>:8080`, `<WG_VM_ADDR>:8080` | `DOCKER-USER` (NIC: LAN + workstations, `wg0`: tunnel subnet) |
| Wings API (systemd)    | `<VM_IP>:8081`                      | ufw: LAN, workstations, tunnel, panel network                 |
| Wings SFTP             | `0.0.0.0:2022` (set to `<VM_IP>`)   | ufw: LAN, workstations, tunnel                                |
| Game servers (Wings)   | allocation address and port         | `DOCKER-USER`                                                 |
| LanCache DNS and cache | `<VM_IP>:53/80/443`                 | `DOCKER-USER`                                                 |

Credential files (contents never shown, mode 0600): the WireGuard key files and
`wg0.conf` on both hosts, `/root/<credentials-file>` (panel admin),
`/root/<api-keys-file>` (application and client key, never copied to the app
server), `/etc/pelican/config.yml` (node token), and the panel `APP_KEY` inside
the Docker volume.

## Findings and gotchas

1. `DB_CONNECTION` missing from `/pelican-data/.env` breaks the second start
   (step 2).
2. Panel image namespace is `pelican`, not `pelican-dev`.
3. Wings belongs on the host, not in a container.
4. The Application API `status` of `null` means installed, not running.
5. The panel answers `/` with a redirect to `APP_URL`; test with `curl -sI`.
6. Panel to Wings needs its own narrow ufw rule; do not open a whole private
   block (game containers could reach the Wings API).
7. Docker must start after `wg0` when a container publishes a tunnel address.
8. `docker.network.dns` applies on container recreation only.

## Open questions (defaults chosen)

- **TLS.** Panel and Wings are plain HTTP (owner decision: LAN and tunnel only).
  For TLS later: a reverse proxy with `APP_URL=https://...`, `BEHIND_PROXY=true`,
  `TRUSTED_PROXIES=<proxy>` (file uploads fail otherwise); a browser console
  talks straight to Wings and cannot validate a certificate for a bare IP, so it
  needs a LAN DNS name and a certificate. LANoMAT only needs REST. Default: no
  browser console.
- LANoMAT reaches the panel at `<PANEL_URL>` inside the encrypted tunnel;
  `APP_URL` is the LAN address, so redirects and asset URLs point there.
- SQLite versus PostgreSQL for the panel (test: SQLite).
- Pin the panel digest and the Wings version.
- API key `allowed_ips` and the non-admin service owner (mismatch 5).
- Wings SFTP bind address; game port policy (see the next runbook).
- ufw and `DOCKER-USER` subnet lists are duplicated by hand; one source of truth
  per venue is wanted.
- Whether a game server must call back to the app (match telemetry). Default:
  the public HTTPS URL; nothing is opened on the app server.
- Enabling the GameServers module on production stays an owner decision.

## What LANoMAT automation would need

Maps to existing building blocks: `Hosts\Contracts\RemoteExecutor` (the only SSH
path), `Hosts\Actions\RegisterHost`/`ProbeHost`, `Lancache\Actions\ApplyLancacheSetup`,
`GameServers\Contracts\PelicanClient` (now rebuilt against the real API, see
[../gameservers-pelican.md](../gameservers-pelican.md)), and a future
`HypervisorClient` (see the base runbook). Nothing in this document is
implemented as a provisioning feature yet.

| Step                               | Becomes                                                                                                                                                                                                                                                                                                                  | New piece needed                                                                            |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| Tunnel                             | see [wireguard-tunnel.md](wireguard-tunnel.md)                                                                                                                                                                                                                                                                           | as there                                                                                    |
| Panel compose and scripted install | `RemoteExecutor::upload` of the rendered compose file, `run` of the install script, then `APP_INSTALLED=true` plus `DB_CONNECTION` and restart                                                                                                                                                                           | typed `PelicanPanelConfig` (bind addresses, ports, `APP_URL`, admin, pinned digest)         |
| Node and Wings                     | `run` `p:node:make` and `p:node:configuration`, write `config.yml` with mode 0600, install the binary with checksum verification, systemd unit; wait until `systemInformation()` succeeds                                                                                                                                | idempotent lookup of the node by name (`p:node:list`); Wings version and checksum as inputs |
| Allocations                        | port pool `27015` to `27050` on the node address, one allocation per server (+1 extra for CS2 TV), created through tinker or the panel                                                                                                                                                                                   | allocation pool allocator, because the application key cannot create allocations            |
| API keys                           | `KeyCreationService` in tinker: read-only scopes except `server` read/write, a dedicated non-admin owner, `allowed_ips=<WG_APP_ADDR>`                                                                                                                                                                                    | hand-off into LANoMAT's secret store; never logged                                          |
| Container DNS                      | edit `docker.network.dns` by assert-then-replace, restart Wings                                                                                                                                                                                                                                                          | none                                                                                        |
| Verification                       | handshake age, `302/200` from an app container, node `systemInformation()`, scripted create, start, status, delete of a 1 GB Paper server through `PelicanClient` incl. `/resources` polling, game port reachable from a LAN client, `docker inspect .HostConfig.Dns`, in-container `getent`, MISS then HIT, reboot test | probe(s) in the preflight registry (`HealthCheck`)                                          |
| Rollback                           | per-step compensating commands; keep `*.pre-*` backups; timer-armed safety net for firewall edits (as in the base runbook)                                                                                                                                                                                               | `rollback()` on each provisioning step                                                      |

Idempotency: `compose up -d` and `migrate --force` are idempotent;
`p:user:make` fails if the user exists (check first); eggs by name; nodes by
name; `config.yml` edits assert-then-replace; firewall changes through
marker-guarded blocks.

Secrets: nothing secret in compose files or logs; admin password, tokens and
keys in 0600 root files; an `artisan` call with the password in the environment
is briefly visible in `ps`; the node token lives in `config.yml`; the panel
`APP_KEY` must be backed up separately (loss makes encrypted tokens unreadable).
