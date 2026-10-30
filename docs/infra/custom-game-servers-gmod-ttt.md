# Custom game servers on Pelican: Garry's Mod (TTT), Prop Hunt, CS2 GunGame

A runbook and analysis for "really custom" game servers on the Pelican setup from
[pelican-panel-wings.md](pelican-panel-wings.md): Garry's Mod with the Trouble in
Terrorist Town (TTT) gamemode was installed and run; Prop Hunt (Workshop
gamemode) and CS2 GunGame (Metamod + CounterStrikeSharp) were analysed. It also
answers whether the LanCache helps SteamCMD installs, and ends with the data
model for LANoMAT game server presets. Everything marked "verified" was run on
the test venue host; "unverified" items are analysis only.

Client-API calls use the server **identifier** (8 hex characters,
`<SERVER_ID>`); Application-API calls use the numeric id (`<SERVER_NUM>`). API
tokens are read from a root-only file inside a shell script on the host and are
never echoed.

## Placeholders

| Placeholder                  | Meaning                                                                       |
| ---------------------------- | ----------------------------------------------------------------------------- |
| `<VM_IP>`                    | LAN address of the venue host (node and allocation address)                   |
| `<NODE_ID>`                  | node id in the panel                                                          |
| `<PANEL_URL>`, `<PANEL_DIR>` | as in the Pelican runbook                                                     |
| `<SERVER_ID>`                | server identifier (Client API)                                                |
| `<SERVER_NUM>`               | numeric server id (Application API)                                           |
| `<SERVER_UUID>`              | server UUID (volume directory name, container name)                           |
| `<EGG_ID>`                   | numeric egg id in this panel (not stable across panels, see the preset model) |
| `<PORT>`                     | allocated game port                                                           |
| `<ALLOCATION_ID>`            | numeric allocation id                                                         |
| `<CACHE_ROOT>`               | cache data root, as in the base runbook                                       |
| `<WORKSHOP_COLLECTION_ID>`   | Steam Workshop collection id                                                  |
| `<WORKSHOP_ITEM_ID>`         | Steam Workshop item id                                                        |
| `<STEAM_WEB_API_KEY>`        | Steam Web API key (secret, owner decision pending, see open questions)        |
| `<GSLT>`                     | Game Server Login Token (secret)                                              |
| `<NETWORK>`                  | Docker network created by Wings for game containers                           |
| `<VOLUME>`                   | server volume path or name on the host                                        |
| `<HOSTNAME>`                 | server display name, e.g. `<event> TTT`                                       |
| `<MAP>`                      | map name from a content bundle                                                |
| `<PLUGIN_NAME>`              | name of a CounterStrikeSharp plugin directory                                 |

## Step 1: the Garry's Mod egg

Source of truth: the `pelican-eggs/games-steamcmd` repository, file
`gmod/egg-garrys-mod.yaml` (`meta.version PLCN_v3`, exported 2026-04-21; a legacy
JSON egg lives next to it). Its README calls it a copy of the panel-bundled egg
plus a `maxplayers` setting. The panel's cached egg index (filled by
`p:egg:update-index` in the previous runbook) lists it as "Garrys Mod", so
nothing has to be downloaded by hand. Importing is the same job the installer
uses:

```bash
cd <PANEL_DIR>
sudo docker compose exec -T panel php artisan tinker --execute='\App\Jobs\InstallEgg::dispatch("https://raw.githubusercontent.com/pelican-eggs/games-steamcmd/refs/heads/main/gmod/egg-garrys-mod.yaml");'
# the queue worker picks it up within seconds; verify:
sudo docker compose exec -T panel php artisan tinker --execute='foreach(App\Models\Egg::withCount("variables")->get() as $e) echo $e->id." ".$e->name." ".$e->variables_count.PHP_EOL;'
```

The UI route is Admin > Eggs > Import from URL. **Egg import is a panel-side
admin action, not available through LANoMAT's application key.** Presets must
therefore reference an egg that the venue administrator imported, matched by egg
UUID (`60ef81d4-30a2-4d98-ab64-f59c69e2f915` for this egg) and never by numeric
id.

Verified facts (`GET /api/application/eggs/<EGG_ID>?include=variables`):

- Features `gsl_token` and `steam_disk_space`, tags `source` and `steamcmd`.
- Runtime image `ghcr.io/pelican-eggs/games:source` (the only entry). Installer
  image `ghcr.io/pelican-eggs/installers:debian`, entrypoint `bash`.
- Startup:
  `./srcds_run -game garrysmod -console -port {{SERVER_PORT}} +ip 0.0.0.0 +host_workshop_collection {{WORKSHOP_ID}} +map {{SRCDS_MAP}} +gamemode {{GAMEMODE}} -strictportbind -norestart +sv_setsteamaccount {{STEAM_ACC}} +maxplayers {{MAX_PLAYERS}} -tickrate {{TICKRATE}} $( [ "$LUA_REFRESH" == "1" ] || printf %s '-disableluarefresh' )`
- "Server is running" detection: the console line `gameserver Steam ID`; stop
  command `quit`.
- Variables (name = default): `GAMEMODE`=sandbox, `LUA_REFRESH`=0,
  `SRCDS_MAP`=gm_flatgrass, `MAX_PLAYERS`=32 (max 128), `SRCDS_APPID`=4020 (not
  editable or viewable), `STEAM_ACC`= empty (GSLT, 32 alphanumerics, nullable),
  `TICKRATE`=22 (max 100), `WORKSHOP_ID`= empty (collection id, integer,
  nullable). There is **no hostname variable** (the hostname lives in
  `garrysmod/cfg/server.cfg`, default "New Gmod Server") and **no `-authkey`
  variable** (see step 6).
- Install script: downloads SteamCMD, `+login anonymous`,
  `+app_update ${SRCDS_APPID} ${EXTRA_FLAGS} validate +quit`, copies
  `steamclient.so` into `.steam/sdk32` and `sdk64`, then changes into
  `garrysmod/lua/autorun/server` and `garrysmod/cfg` and writes a stub
  `workshop.lua` and a default `server.cfg`. The script has **no `set -e`**: if
  SteamCMD fails, the `cd` commands fail and the files land in the wrong place,
  yet Pelican reports the install as successful (step 3).

## Step 2: allocations and the server

Source servers need **one allocation per server**: Docker publishes the port as
both TCP and UDP (`docker ps` shows `<VM_IP>:<PORT>->…/tcp` and `…/udp`), which
is what srcds needs (UDP game and query, TCP rcon and file transfer). The test
added `27016` and `27025` next to `27015` through tinker as in the previous
runbook (the application key cannot create allocations).

```text
POST /api/application/servers
{"name":"qa-gmod-ttt","user":1,"egg":<EGG_ID>,"docker_image":"ghcr.io/pelican-eggs/games:source",
 "startup":"<egg startup from step 1>",
 "environment":{"GAMEMODE":"terrortown","LUA_REFRESH":"0","SRCDS_MAP":"gm_construct","MAX_PLAYERS":"16",
                "SRCDS_APPID":"4020","STEAM_ACC":"","TICKRATE":"66","WORKSHOP_ID":""},
 "limits":{"memory":3072,"swap":0,"disk":20480,"io":500,"cpu":200},
 "feature_limits":{"databases":0,"allocations":1,"backups":0},
 "allocation":{"default":<ALLOCATION_ID>},"start_on_completion":false}
-> 201 {"id":<SERVER_NUM>,"identifier":"<SERVER_ID>","status":"installing"}
```

All environment keys must be sent (`SRCDS_APPID` included) or the API answers
`422`. `start_on_completion` is `false` on purpose: check the install first.

**The base game ships no TTT maps.** GMod app 4020 contains only `gm_construct`
and `gm_flatgrass`. The files under `gamemodes/terrortown/content/maps/*.txt` are
only per-map weapon and spawn configuration for CS:S maps, which need CS:S
content mounted (`cfg/mount.cfg` plus the CS:S dedicated content) to be playable.
Real TTT therefore needs workshop maps (a collection), uploaded `ttt_*.bsp`
files, or CS:S content. For the test `gm_construct` with `terrortown` is enough:
the gamemode loads and runs (it warns about missing `ttt_` spawn entities and
falls back to random weapon spawns).

## Step 3: install verification (pitfall)

Install number one (via the API) took 57 seconds and **failed silently**:
SteamCMD self-updated and logged in, then
`ERROR! Failed to install app '4020' (Missing configuration)`, with "Requested 51
app access tokens, 0 received, 51 denied" in the log. Pelican still set
`status: null` (installed, no error); only about 300 MB were on disk and there
was no `garrysmod/` directory. This is a known SteamCMD cold-start flake (the
first run only fetches app info; a retry works). It is **not** a LanCache problem:
it did not repeat on a second server with a fresh SteamCMD and the same DNS.

> Pitfall: **`status null` with "Missing configuration" in the install log does
> not mean installed. Verify that `garrysmod/` exists and retry.**

Recovery used:

- A manual retry with the same installer image, network and DNS as Wings:

    ```bash
    docker run --rm --network <NETWORK> --dns <VM_IP> -v <VOLUME>:/mnt/server -e HOME=/mnt/server \
      ghcr.io/pelican-eggs/installers:debian bash -c \
      'cd /mnt/server/steamcmd && ./steamcmd.sh +force_install_dir /mnt/server +login anonymous +app_update 4020 validate +quit'
    ```

    took about 100 seconds for download and verify (6.9 GB installed).

- Then a normal `POST /api/application/servers/<SERVER_NUM>/reinstall` (`204`;
  status `installing` for about 40 seconds while SteamCMD validated) ran the whole
  egg script cleanly, created `workshop.lua` and ended with
  `Success! App '4020' fully installed`.

An automation must verify an install by looking for `garrysmod/` (Client API
`GET files/list?directory=/garrysmod`) or the `Success!` line in the node's
install log (`/var/log/pelican/install/<SERVER_UUID>.log`), never by trusting
`status`, and retry through `/reinstall` (two or three attempts).

## Step 4: start and readiness with A2S

```text
POST /api/client/servers/<SERVER_ID>/power    {"signal":"start"}    -> 204
GET  /api/client/servers/<SERVER_ID>/resources                      -> current_state offline -> running in ~25 s
```

Wings flips to `running` on the `gameserver Steam ID` console line; there is no
"map loaded" signal, so use a Source query (A2S) for real readiness. The console
shows `Changing gamemode to Trouble in Terrorist Town (terrortown)`, the bound
address and port, `Connection to Steam servers successful` and `VAC secure mode
is activated`. Harmless noise: `Usage: sv_setsteamaccount <login_token>` (an
empty `STEAM_ACC` renders `+sv_setsteamaccount ` with no value),
`Unknown command "cl_cmdrate"/"mp_friendlyfire"` and "ranking negatively
impacted: no account token". The real fix for the first is to pass the argument
only when it is set (an edited egg, step 6).

A2S_INFO query from a LAN workstation (inbound traffic is not touched by the
egress isolation); pure standard library, run with
`python3 -I a2s.py <VM_IP> <PORT>`:

```python
import socket, struct, sys
host, port = sys.argv[1], int(sys.argv[2])
Q = b"\xff\xff\xff\xffTSource Engine Query\x00"
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.settimeout(3)
s.sendto(Q, (host, port)); d, _ = s.recvfrom(4096)
if d[4] == 0x41:                       # S2C_CHALLENGE: resend with the 4 challenge bytes appended
    s.sendto(Q + d[5:9], (host, port)); d, _ = s.recvfrom(4096)
assert d[:4] == b"\xff\xff\xff\xff" and d[4] == 0x49, d[:8]
p = 6                                    # skip header + protocol byte
def cstr():
    global p
    e = d.index(b"\0", p); r = d[p:e].decode("utf8", "replace"); p = e + 1; return r
name, mp, folder, game = cstr(), cstr(), cstr(), cstr()
appid, players, maxp, bots = struct.unpack_from("<HBBB", d, p)
print(name, mp, folder, game, appid, f"{players}/{maxp}", bots)
```

Result: `name='New Gmod Server' map=gm_construct folder=garrysmod game='Trouble in
Terrorist Town' appid=4000 players=0/16 bots=0`. So `game` carries the gamemode
title, `folder` is the game directory, and `appid` 4000 is GMod's client app id,
not the server's 4020. The server name needs `hostname` in `server.cfg` (Client
API file write), because the egg has no variable.

Changing the environment: `PATCH /api/application/servers/<SERVER_NUM>/startup`
with `{startup, environment{all keys}, egg, image, skip_scripts:true}` returns
`200`, then a client power `restart` (`204`). Sending only the changed keys
fails; the **complete** environment is required.

## Step 5: LanCache and SteamCMD (the key finding)

Same DNS as in the Pelican runbook (`docker.network.dns` set to the cache
address), so installer and game containers use the LanCache. Evidence is the
cache's nginx `access.log` (lines tagged `[steam]`, user agent
`Valve/Steam HTTP Client 1.0`, source address = the Wings Docker bridge).

- **Depot downloads do go through the cache.** SteamCMD fetches depot chunks
  over plain HTTP from `cache<N>-<site>.steamcontent.com` hosts, all resolved to
  the cache by the cache DNS. GMod is depots 4021 and 4023, plus 1006 (the
  shared SteamCMD depot); 6.89 GB installed. No SteamCMD flag was needed.
- First download: **8865 requests, 8865 MISS, 0 HIT**, about 3.5 GB on the wire
  (chunks are compressed; 6.9 GB on disk). The cache directory grew to about 3.4
  GB.
- Second, fresh server (new volume, fresh SteamCMD): the identical 8865
  requests, **8862 HIT, 3 MISS**, about 3.5 GB served from the cache, cache
  directory unchanged. The 3 MISS are the `/depot/<id>/manifest/<digest>/5/<request-code>`
  URLs: manifests carry a per-request code and are never cacheable (4 to 593
  KB, normal).
- Not visible in the log: the SteamCMD bootstrap (about 41 MB of self-updates
  over HTTPS from the Steam CDN) and the Steam connection-manager traffic
  (websocket/TCP 443 and 27018+), login and app info. Those bypass the cache,
  which is fine (small, not cacheable).
- **Install time did not improve.** First install: about 100 s download and
  verify (cold). Second install: about 170 s wall including SteamCMD update and
  verify (about 120 s download window). The host's uplink was fast (about 0.5
  Gbit/s) and the VM disk was the bottleneck (writing 6.9 GB), so a HIT served
  from the same disk is not quicker. The benefit is **WAN traffic** (3.5 GB saved
  per further install) and independence from the Steam CDN on a poor venue
  uplink; it only pays off in speed when the venue WAN is slower than the cache
  disk. Say so honestly in any user-facing documentation.
- What would defeat the cache: a container with another DNS (Wings default
  resolvers; the DNS setting is required), HTTPS depot URLs (not observed; if
  Valve moves the CDN to TLS the cache stops working). `validate` after the first
  install does not re-download, so hits matter for first installs of **new**
  servers, not for restarts or unchanged-build updates.

## Step 6: custom content

### 6a Prop Hunt (Workshop gamemode): mechanics verified, content not installed

Mechanics tested on the TTT server: set `WORKSHOP_ID` to a bogus id and restart.
The console prints `Initializing Steam libraries for Workshop..`,
`Fetching collection info...`, `Processing collection ...`,
`Item is not a collection!` and `Mounted 0 of 0 workshop addons!`. So the egg's
`+host_workshop_collection {{WORKSHOP_ID}}` path works and the server reaches the
Steam Workshop API without a key from the host. **Whether a real collection
downloads without `-authkey` was not verified** (no real collection and no key
were used). The GMod wiki states that collections need
`-authkey <Steam Web API key>` on the command line (unverified here), and the
egg has no variable for it.

Plan (needs the owner decision on the key):

1. Fork the egg (Admin > Eggs > export, edit, import as "Garrys Mod (LANoMAT)").
   Add variables `HOSTNAME` (applied to `server.cfg` or `+hostname`) and
   `STEAM_WEB_API_KEY` (not viewable and not editable by users, rules
   `nullable|alpha_num|size:32`), and an optional startup guard:
   `... +host_workshop_collection {{WORKSHOP_ID}} $( [ -n "$STEAM_WEB_API_KEY" ] && printf %s "-authkey $STEAM_WEB_API_KEY" ) ...`,
   likewise for `+sv_setsteamaccount`. The Application API cannot add egg
   variables, so this is a one-time admin import (or an egg file kept in the
   LANoMAT repository and imported through the panel's `InstallEgg` job or UI).
2. The collection must contain the gamemode addon plus its maps and be public.
   Set `GAMEMODE` to the gamemode **folder** name from the addon
   (`garrysmod/gamemodes/<name>`; check it after the first start), `SRCDS_MAP`
   to a map from the collection and `WORKSHOP_ID` to the collection id.
3. Players only download workshop content automatically if the server also calls
   `resource.AddWorkshop("<WORKSHOP_ITEM_ID>")` for every item, usually in
   `garrysmod/lua/autorun/server/workshop.lua` (the install script writes an
   empty stub; the file API can write it). Without it clients see missing
   models (purple-black).
4. Server-side workshop downloads land in `garrysmod/cache/workshop` and
   `steam_cache`. Whether they pass through the LanCache depends on Steam serving
   them from the same `steamcontent` hosts (likely, unverified).
5. At the LAN the **clients'** own workshop downloads can also go through the
   LanCache (their DNS is the cache), which is the real LAN-party benefit.

### 6b Hand-uploaded gamemode or addon folder (verified)

The Client API file write creates parent directories. Test and cleanup (all
`204` unless noted):

```text
POST /api/client/servers/<SERVER_ID>/files/write?file=%2Fgarrysmod%2Faddons%2Fqa-dummy%2Freadme.txt   body: qa dummy
GET  /api/client/servers/<SERVER_ID>/files/list?directory=%2Fgarrysmod%2Faddons%2Fqa-dummy             -> readme.txt, 8 bytes
GET  /api/client/servers/<SERVER_ID>/files/contents?file=...                                           -> "qa dummy"
POST /api/client/servers/<SERVER_ID>/files/delete   {"root":"/garrysmod/addons","files":["qa-dummy"]}
```

On disk the file is `garrysmod/addons/qa-dummy/readme.txt`, owned by the
`pelican` user; `addons/` did not exist after the install and the write created
it.

Other paths (the upload URL endpoint's existence was verified; the rest is not
tested): `GET /files/upload` returns a signed one-shot URL to Wings
(`http://<node>:8081/upload/file?token=...`, multipart POST; never log the
token) for large files; `POST /files/pull {"url":..,"directory":..}` lets Wings
download a zip from a URL (for example an artifact hosted by LANoMAT over the
tunnel) and `POST /files/decompress` unpacks it. That is the cleanest way to drop
a gamemode folder, `ttt_*.bsp` maps and `maps/*.bsp.bz2` (for an `sv_downloadurl`
FastDL) onto a fresh server. SFTP on port 2022 works with the panel user's
credentials (not tested). A gamemode in an addon needs
`garrysmod/addons/<name>/gamemodes/<gamemode>/` plus a `<gamemode>.txt`; the
`GAMEMODE` variable is the folder name.

### 6c CS2 GunGame (Metamod + CounterStrikeSharp): analysis only

No 60 GB download was run. The panel-bundled egg "Counter-Strike 2" (SteamCMD app
730; same as `counter_strike/counter_strike_2/egg-counter--strike2.yaml`) uses
the image `ghcr.io/parkervcp/steamcmd:sniper`, has the feature
`steam_disk_space`, and an installer in the same family as the GMod one (no
`set -e`, no post-install hooks). Startup:

```text
LD_LIBRARY_PATH=$HOME/game/bin/linuxsteamrt64:$LD_LIBRARY_PATH ./game/bin/linuxsteamrt64/cs2 -dedicated
  $( [ "$VAC_ENABLED" == "1" ] || printf %s ' -insecure' ) -ip 0.0.0.0 -port {{SERVER_PORT}} -tv_port {{TV_PORT}}
  -maxplayers {{MAX_PLAYERS}} $( [ "$RCON_ENABLED" == "0" ] || printf %s ' -usercon' )
  +game_mode {{GAME_MODE}} +game_type {{GAME_TYPE}} +map {{SRCDS_MAP}} +hostname "{{SERVER_NAME}}"
  +sv_password "{{SERVER_PASSWORD}}" +rcon_password "{{RCON_PASSWORD}}" +sv_setsteamaccount {{STEAM_GSLT}}
```

Variables: `AUTO_UPDATE`=1 (declared but not used by the startup),
`RCON_ENABLED`=0, `VAC_ENABLED`=1, `GAME_MODE`=1, `GAME_TYPE`=0,
`SRCDS_MAP`=de_dust2, `MAX_PLAYERS`=12 (1 to 64), `RCON_PASSWORD`, `SERVER_NAME`,
`SERVER_PASSWORD`, `SRCDS_APPID`=730, `TV_PORT`=27020, `STEAM_GSLT` (rule
`required`, size 32). Two consequences:

- **TV port.** Pelican only publishes the main allocation. GOTV on `TV_PORT`
  needs a second allocation (`feature_limits.allocations` of at least 2 and an
  additional allocation) or stays off. Pool policy: one allocation per server, one
  extra only for CS2 TV.
- **GSLT.** A CS2 server cannot be created without a 32-character token (a real
  `<GSLT>`, or a dummy for LAN-only testing that the server rejects with a log
  warning).

The egg has **no support for Metamod or CounterStrikeSharp** (no variables, no
install hook). GunGame is therefore "base egg plus content bundle applied after
install", in this order (via file API, or `files/pull` plus `decompress`):

1. Install CS2 normally (about 60 GB, 33 GB minimum per the egg's README; a
   second install at the same venue comes from the LanCache).
2. Metamod:Source 2.0 (the development build for CS2) into
   `game/csgo/addons/metamod/` and `game/csgo/addons/metamod.vdf`.
3. Patch `game/csgo/gameinfo.gi`: add `Game	csgo/addons/metamod` as the **first**
   entry of `SearchPaths` (below `Game_LowViolence`). **A CS2 update overwrites
   `gameinfo.gi`**, so the patch must be re-applied after every game update. The
   preset needs an idempotent "ensure patch" step after start or update (grep
   plus sed, or a startup wrapper), because `AUTO_UPDATE` does nothing here.
4. CounterStrikeSharp, the **with-runtime** build, into
   `game/csgo/addons/counterstrikesharp/` (`api`, `bin`, `dotnet`, `gamedata`,
   `plugins`, `configs`).
5. GunGame plugin DLL(s) into
   `game/csgo/addons/counterstrikesharp/plugins/<PLUGIN_NAME>/` and its config
   into `.../configs/plugins/<PLUGIN_NAME>/`; weapon list and `mp_` cvars via
   `game/csgo/cfg/` or the plugin config.
6. Environment: `GAME_TYPE=0`, `GAME_MODE=1` (or the combination the plugin
   documents; it normally forces its own cvars), a map such as `de_dust2` or
   `de_inferno`, `MAX_PLAYERS=16`, `STEAM_GSLT=<GSLT>`. VAC on or off for plugin
   servers is an owner choice.
7. Readiness: A2S_INFO (same packet; `game` is "Counter-Strike 2"), plus a
   console or RCON `meta list` / `css_plugins list` check for the plugin. RCON
   is not usable from the game client; use an external RCON client or the Wings
   console websocket.

Failure modes: wrong `gameinfo.gi` position (Metamod silently absent),
`dotnet`/glibc mismatch (CounterStrikeSharp needs the sniper runtime image,
which is the default), wrong ownership under `game/csgo/addons` (must be the
`pelican` user, which file API writes already satisfy), updates reverting
`gameinfo.gi`.

## Step 7: resources

- TTT idle (map `gm_construct`, no players, tick 66): 175 to 190 MiB resident,
  about 1 % of one core, 6.8 GB on disk. Docker shows the limit as about 3.3 GiB
  (3072 MiB plus overhead). A populated 16-player round with workshop content is a
  few hundred MB to about 1.5 GB more, so 3072 MB is a safe LAN default.
- Host with GMod, the panel, the LanCache and a second installer running: about
  1.2 GB used, about 5.8 GB page cache (from the 6.9 GB install), about 6.6 of
  7.9 GB available, load 0.4 to 1.0 after the installs; panel about 360 MiB,
  cache about 155 MiB, cache DNS about 93 MiB.
- Disk: about 22 GB used after the second install (two 6.8 GB trees, 3.4 GB
  cache, other test stacks), about 16 GB after deleting the second server.
- Timings: first install about 100 s (cold) plus a 57 s failed attempt; second
  install about 170 s (cache HIT, no WAN traffic); start to `running` about 25 s.

## Step 8: cleanup and rollback

The second test server was removed with `DELETE /api/application/servers/<SERVER_NUM>`
(`204`, later `GET` `404`); its volume and container were gone and its allocation
was free again. The first server was kept stopped with its files (6.8 GB) and can
be started again with a client `power start`. The egg stays imported. Full
rollback: delete the servers through the API (the volume goes with them), delete
unused allocations through tinker, remove the egg in the UI **only after** no
server uses it.

## Findings and gotchas

1. GMod base ships no TTT maps; TTT needs map content (workshop, upload or CS:S).
2. Pelican marks a failed SteamCMD install as success (the install script exit
   status is that of its last command). Verify through the install log or file
   list and reinstall on failure; "Missing configuration" on the first run is a
   SteamCMD flake.
3. Depots go through the LanCache over HTTP (`steamcontent.com`); manifests are
   always a MISS; the speed gain is nil on a fast WAN with a slow disk, the gain
   is WAN bytes.
4. Empty egg variables render as empty tokens (`+sv_setsteamaccount ` with no
   value); custom eggs should guard optional arguments.
5. No hostname variable and no `-authkey` variable in the egg: hostname through
   `cfg/server.cfg` (file API), workshop authentication through an edited egg.
6. Environment changes: `PATCH /servers/{id}/startup` needs **all** egg variables
   in `environment`, and the server needs a restart.
7. The server volume directory is root-only: use `sudo sh -c '...'` for globs on
   the host. The Application API takes the numeric id, the Client API the
   identifier.
8. The CS2 egg has no addon support: Metamod patches `gameinfo.gi`, which every
   game update overwrites.

## Open questions (defaults chosen)

- **Steam Web API key for workshop collections (Prop Hunt, TTT map packs): owner
  decision pending.** Default: none on the test host; no production key is reused.
  Options: a dedicated test key (root-only, passed as a hidden egg variable) or
  keep workshop content out and use uploaded files only.
- **Console and TLS.** The browser console and file upload talk to Wings over
  plain HTTP from the browser to a LAN address (unchanged from the Pelican
  runbook). LANoMAT needs REST, A2S and the file API only. Default: no browser
  console.
- **Port range.** Pool `27015` to `27050` on the node address, one allocation per
  server, one extra only for CS2 TV (`27020` by default is inside the pool, so
  allocate it deliberately). The client port `27005` is outbound only.
- **Content hosting.** Where do gamemode and map bundles live: LANoMAT storage
  served over the tunnel and fetched with `files/pull`, or a read-only mount?
  Default: a zip served by LANoMAT, pulled by Wings.
- **GSLT.** CS2 requires one per server (the egg enforces it); GMod works
  anonymously. The owner provides the tokens; LANoMAT stores them as a secret per
  preset.
- **Own egg fork** "Garrys Mod (LANoMAT)" with a hostname variable, an auth-key
  variable and guarded optional startup arguments: **planned**, kept in the
  LANoMAT repository.
- On a fast venue WAN the LanCache saves bytes but not time. Document, do not
  oversell.

## What LANoMAT automation would need

Maps to: `GameServers\Contracts\PelicanClient` (create, get, list, power,
resources, delete, nodes, allocations, eggs; see
[../gameservers-pelican.md](../gameservers-pelican.md)), `Hosts\Contracts\RemoteExecutor`
(host-level work such as the `gameinfo.gi` patch or log reads), the `Lancache`
module (the venue's cache is the reason container DNS matters), and the existing
`Games` module's server config. Nothing below is implemented yet.

| Need                           | Becomes                                                                                                                                 |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| Egg resolver                   | match the preset's egg UUID against `listEggs()`; refuse with a clear message when the venue admin has not imported the egg             |
| Install verifier with retry    | after `createServer`, poll the install state, then check the file list or install log; `reinstall` up to three times                    |
| Optional-argument-guarded eggs | the LANoMAT egg fork (hostname, auth key, guarded optional arguments), shipped in the repository                                        |
| File bundle applier            | `files/write`, `files/pull`, `files/decompress` with sha256 verification of each bundle                                                 |
| Post-update patch step         | idempotent "ensure patch" job for `gameinfo.gi`, run after start and after game updates                                                 |
| A2S readiness probe            | UDP A2S_INFO with challenge in the app (host from the allocation, port from the allocation), expectations on `folder` and `game`        |
| Allocation pool allocator      | pick the first free port in `27015`-`27050`; allocation creation needs panel-side tinker or UI because the application key cannot do it |
| Content store                  | preset content bundles served by LANoMAT over the tunnel                                                                                |

Secrets (GSLT, Steam Web API key, RCON passwords) are stored encrypted and
non-fillable per preset and are only handed to the panel as hidden egg
variables; they never appear in logs or command strings.

### The preset data model

One row per "game server preset", copied onto a server at creation; per-event
overrides only on the whitelisted keys:

```text
Preset
  key               ttt | prop-hunt | cs2-gungame            (stable slug)
  title, connect_template   "steam://connect/{ip}:{port}"  (+ password variant: steam://connect/{ip}:{port}/{password})
  egg_ref           {uuid, fallback_name, id_resolved_at_runtime: int}   # match the uuid; never trust the numeric id across venues
  docker_image      key from the egg's docker_images (label -> image)
  startup_override  null | string          # only for forked eggs / optional arguments
  environment       {KEY: {default, overridable_per_event: bool, secret: bool}}
  limits            {memory, swap, disk, io, cpu}
  feature_limits    {databases, allocations, backups}
  allocation        {strategy: "pool-first-free", pool: "27015-27050", count: 1, extra: ["tv:27020"]}
  content           {type: none|workshop|upload|mounts, workshop_collection_id?, workshop_authkey_secret_ref?,
                     files: [{dest, source_url|inline, sha256, mode}], post_install: [ensure_gameinfo_patch, ...]}
  lifecycle         {install_check: "log_contains 'Success! App' && file_exists garrysmod/", install_retries: 3,
                     start_policy: on_event_start | manual, stop_policy: on_event_end + grace 60 s, reinstall_on: [...]}
  readiness         {type: a2s_info, host: allocation_ip, port: allocation_port, expect: {folder, game_contains}, timeout_s: 120, interval_s: 5}
  post_start        optional [{console: "meta list"}, ...]   (only through the Wings websocket if the console is enabled)
```

Worked examples (angle-bracket values are per venue or per owner; ids are not
stable across panels):

1. **TTT** (verified):
   egg uuid `60ef81d4-30a2-4d98-ab64-f59c69e2f915`, image
   `ghcr.io/pelican-eggs/games:source`. Environment: `GAMEMODE=terrortown`
   (fixed), `SRCDS_MAP=<a ttt_ map from the content bundle, fallback gm_construct>`
   (overridable), `MAX_PLAYERS=16` (overridable), `TICKRATE=66`,
   `WORKSHOP_ID=<collection id or empty>` (overridable), `STEAM_ACC=<GSLT or empty>`
   (secret), `LUA_REFRESH=0`, `SRCDS_APPID=4020` (fixed). Limits: memory 3072,
   disk 20480, cpu 200, swap 0. One allocation from the pool (TCP and UDP on the
   same port automatically). Content: `workshop` (map collection, needs the auth
   key) **or** `upload` of a zip with `ttt_*.bsp` and a `server.cfg` that sets
   `hostname` (and `sv_password`). Readiness: `a2s_info` with `game_contains
"Trouble in Terrorist Town"`. Connect: `steam://connect/<ip>:<port>`.
2. **Prop Hunt** (Workshop gamemode, unverified):
   same egg, preferably the LANoMAT fork with `STEAM_WEB_API_KEY`. Environment:
   `GAMEMODE=<prop_hunt folder name>`, `SRCDS_MAP=<a ph_ map>`,
   `WORKSHOP_ID=<collection with gamemode and maps>`, `MAX_PLAYERS=24`,
   `TICKRATE=33`. Content: `{type: workshop, collection_id, authkey: secret
reference}` plus a file
   `garrysmod/lua/autorun/server/workshop.lua` with `resource.AddWorkshop(...)`
   lines so clients download automatically. Limits: memory 4096 (many models),
   disk 20480. Readiness: `a2s_info` with `folder garrysmod` and `game_contains
"Prop Hunt"` (the title comes from the gamemode's `.txt`; check after the first
   run).
3. **CS2 GunGame** (Metamod + CounterStrikeSharp, unverified):
   egg matched by uuid read from the panel (fallback name "Counter-Strike 2"),
   image `ghcr.io/parkervcp/steamcmd:sniper`. Environment: `GAME_TYPE=0`,
   `GAME_MODE=1`, `SRCDS_MAP=de_dust2` (overridable), `MAX_PLAYERS=16`,
   `SERVER_NAME=<event> GunGame`, `RCON_ENABLED=0`, `RCON_PASSWORD=<secret>`,
   `VAC_ENABLED=1`, `STEAM_GSLT=<secret, required>`, `TV_PORT=27020` (only with
   a second allocation). Limits: memory 8192, disk 61440 (at least 33 GB, about
   60 GB used), cpu 400. Allocation: 1 (+1 TV, optional). Content: `upload` of
   three bundles (Metamod and CounterStrikeSharp extracted into `game/csgo/`,
   the GunGame plugin into
   `game/csgo/addons/counterstrikesharp/plugins/<PLUGIN_NAME>/`) plus
   `post_install: patch gameinfo.gi (idempotent, again after every game update)`.
   Lifecycle `install_check`: `game/bin/linuxsteamrt64/cs2` exists. Readiness:
   `a2s_info` with `game_contains "Counter-Strike 2"`, then a plugin check
   through RCON or console (`css_plugins list` contains the plugin).
