# LanCache — separate-host setup (M7, roadmap 7.5)

[LanCache](https://lancache.net) transparently caches Steam/Epic/Battle.net
(and other) game-download traffic: the first download of a game at the LAN
goes out over the internet once, every subsequent download of the same
files — any participant, any time during the event — is served at LAN
speed from the cache instead.

**LanCache does NOT run inside LANoMAT's own `compose.yml`.** This is a
deliberate correction of an earlier roadmap draft ("as a container in the
prod stack") — LanCache needs to own DNS resolution and ports 53/80/443 on
whatever network segment it serves, which does not compose cleanly with
LANoMAT's own `app`/`traefik`/`reverb-prod` services sharing a host. Instead:

- LanCache runs on its **own physical/virtual host** on the LAN.
- LANoMAT registers that host as a **`RemoteHost`** (`role=lancache`) in
  the managed-hosts registry (`app/Modules/Hosts/`, the same registry
  Task 1–4 built for custom game servers) — an IP/hostname + an SSH key,
  nothing else.
- LANoMAT's `ApplyLancacheSetup` action bootstraps the LanCache container
  **on that host over SSH**, via the same `RemoteExecutor` contract
  (`SshRemoteExecutor`/phpseclib in prod, `FakeRemoteExecutor` in tests)
  the custom-server actions use — LANoMAT never runs LanCache itself, it
  only remotely provisions and health-checks it.

This mirrors exactly how `7.4`'s custom Docker servers work: a `RemoteHost`
+ a structured, config-driven, `escapeshellarg`-quoted command run over SSH
— no free-typed shell strings, no direct Docker access from LANoMAT's own
host.

## 1. Register the LanCache host

In the Filament admin panel, **Remote Hosts** (`app/Modules/Hosts/Filament/
Resources/RemoteHosts/`):

| Field | Value |
|---|---|
| Name | e.g. `lancache-lan1` |
| Hostname | the LanCache machine's IP/hostname on the LAN |
| SSH port | usually `22` |
| SSH user | a user with Docker permissions on that host |
| SSH private key | pasted into the (write-only) key field — stored **encrypted at rest** (`RemoteHost::$ssh_private_key`, an `encrypted` cast; see `app/Modules/Hosts/Models/RemoteHost.php`) |
| Role | **`lancache`** (`HostRole::Lancache`) — `ApplyLancacheSetup`/`ProbeLancache` both reject any host whose role isn't exactly this |
| Event | optional — associate with the current LAN event if useful for filtering |

After saving, run **Probe** (`ProbeHost`, Task 2) once: this connects over
SSH, reads and pins the host's SSH host key fingerprint
(`RemoteHost::$host_fingerprint`), and sets `status`. Once pinned, every
future connection (`ApplyLancacheSetup`, `ProbeLancache`, or any other
action against this host) verifies the fingerprint **before** authenticating
— a changed host key (a re-imaged box, or a MITM) aborts the connection
instead of silently trusting a new key (see `SshRemoteExecutor`'s
pre-login pinning order).

**Operational note:** `SshRemoteExecutor` allows a connection when
`host_fingerprint` is still `null` (trust-on-first-use) — that first
connection is what pins the fingerprint. Always **probe a newly registered
host once, before running any other command or setup on it**; running
`ApplyLancacheSetup` (or any action) against a never-probed host connects
without a pinned key and accepts whatever host key is presented on that
first connection, a one-shot MITM window that probing first, on a trusted
network path, avoids.

## 2. What `ApplyLancacheSetup` actually runs

`app/Modules/Lancache/Actions/ApplyLancacheSetup.php`:

1. Authorizes the acting user (`$actor->isOrga()` — throws
   `AuthorizationException` otherwise).
2. Rejects any host that isn't `role=lancache`
   (`LancacheException::notALancacheHost()`).
3. Builds a `docker run` command entirely from configuration (never from
   free-typed input) and runs it on the host via `RemoteExecutor::run()`:

   ```bash
   docker run -d --name 'lancache' --restart 'unless-stopped' \
     -p '80:80' -p '443:443' -p '53:53/udp' \
     -e 'UPSTREAM_DNS=<upstream_dns>' \
     -v '<cache_volume>:/data/cache' \
     '<image>'
   ```

   Every dynamic value (`<image>`, `<upstream_dns>`, `<cache_volume>`) is
   `escapeshellarg`-quoted individually — the same injection-guard pattern
   `StartCustomServer` uses for custom game servers.

4. The three dynamic values come from `config('services.lancache.*')` (see
   `config/services.php` and the matching `.env` keys below), with the same
   defaults the action's source falls back to if unset:

   | Config key | `.env` key | Default | Meaning |
   |---|---|---|---|
   | `services.lancache.image` | `LANCACHE_IMAGE` | `lancachenet/monolithic:latest` | The LanCache Docker image to pull/run |
   | `services.lancache.upstream_dns` | `LANCACHE_UPSTREAM_DNS` | `8.8.8.8` | DNS server LanCache forwards non-cached queries to |
   | `services.lancache.cache_volume` | `LANCACHE_CACHE_VOLUME` | `lancache_data` | Named Docker volume backing `/data/cache` on the LanCache host |

Run **Apply setup** from the Filament `RemoteHost` record (or the
equivalent orga action) whenever the host is first provisioned, or to
restart/refresh the container with a new image/config — it's a plain
`docker run`, so a rerun after a manual `docker rm -f lancache` on the host
re-creates it cleanly.

## 3. `ProbeLancache` — health check

`app/Modules/Lancache/Actions/ProbeLancache.php` runs a read-only
`docker inspect -f '{{.State.Running}}' 'lancache'` on the host (same
authorization/role guard as `ApplyLancacheSetup`) — use this to confirm the
container is actually up before relying on it, without re-running the
bootstrap.

## 4. DNS pointing (Steam / Epic / Battle.net)

LanCache only works if game-client DNS lookups for the download CDNs
resolve to the LanCache host instead of the real internet — this is
infrastructure LANoMAT does not configure (it's a LAN-network concern, not
an app one), but the checklist is:

1. **Point the LAN's DHCP/DNS server at the LanCache host** for the
   duration of the event: either set the LanCache host itself as the DNS
   server handed out by DHCP (LanCache's own container listens on port 53
   and answers cached-domain queries itself, forwarding everything else to
   `UPSTREAM_DNS`), or add conditional-forwarding rules on the LAN's
   existing DNS server for the cached domains (LanCache's
   [cache domains list](https://github.com/uklans/cache-domains) —
   `steam`, `epicgames`, `blizzard`, and friends) pointing at the LanCache
   host's IP.
2. **Verify resolution from a client machine**, not just the LanCache host
   itself: `nslookup steamcontent.com` (or the current Steam CDN domain)
   from a normal participant machine should resolve to the LanCache host's
   LAN IP, not a public IP.
3. **Confirm the cache is actually being hit**: LanCache's own container
   logs (`docker logs lancache` on the LanCache host) tag each request
   `HIT`/`MISS`/`MISS [static]` — a second download of the same game should
   show `HIT`.

Client-side, no participant configuration is needed beyond normal LAN DHCP
— this is transparent caching, not a proxy participants must configure.

## 5. Pre-caching the vote-winner before the LAN day

The single biggest practical payoff (per the roadmap's Feature-Input
priority ⭐): **cache the winning game(s) of the pre-LAN vote before the LAN
starts**, so day-one doesn't spend hours pulling a 60 GB patch over a
shared uplink. Checklist:

- [ ] The event's game vote (Voting module) has closed and a winner is
      known — with enough lead time before the LAN (ideally several days,
      for a large game/patch).
- [ ] The LanCache host is up and reachable (`ProbeLancache` returns
      `Running: true`) **before** the pre-caching run, on the same network
      path participants will use on the day (so the cached bytes are
      actually reachable, not cached behind a segment that changes at
      setup time).
- [ ] From a machine whose DNS already resolves through LanCache (see
      section 4 — this can be done ahead of the full LAN DHCP switch-over,
      by pointing one machine's DNS at the LanCache host manually), install
      or update the winning game fully via its normal client (Steam/Epic/
      Battle.net). This "warms" the cache with every file the client
      touches.
- [ ] Check the catalog's **`install_hint`** for that game
      (`app/Modules/Games/Domain/InstallHint.php`, surfaced on the
      participant game-catalog view per roadmap 7.5's "So kommst du ran"
      row) — it can carry a `steam_url` deeplink, a `share_url` into the
      Files module (7.3, for installers/patches too large or awkward to
      pull via LanCache, e.g. mod packs), and a free-text `version_note`
      (e.g. "patch 1.4.2, verify build ID before the LAN"). Set/update this
      before the event so participants land on the exact right
      version/modpack once DNS is pointed at LanCache on the day.
- [ ] Re-run the pre-cache pass after any last patch before doors open —
      LanCache only serves what's actually been requested through it once.

## Relationship to the custom-server / Pelican paths

LanCache is orthogonal to how a *server* is hosted (Pelican eggs, M6, or
the custom-Docker escape hatch, 7.4) — it only accelerates how *clients*
download the *game* itself. A Pelican-hosted or custom-Docker game server
still benefits indirectly (players patch/update faster before joining), but
LanCache does not sit in the server-hosting path at all.
