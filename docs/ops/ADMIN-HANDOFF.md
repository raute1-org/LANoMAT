# Admin handoff — first lineup, NFSU2, Discord, MCP

Date: 2026-11-21 · Branch: `main` · Commit: the commit that contains this file (`git log -1 --format=%h`)

This is the short runbook for the admin. Detailed evidence lives in
`docs/ops/ut2004-pilot.md`, `docs/ops/nfsu2.md`, `docs/ops/nfsu2-server.md`,
`docs/ops/nfsu2-browser.md`, `docs/ops/nfsu2-package.md`, `docs/ops/discord-announcement.md`,
`docs/ops/discord-agent.md`, `docs/ops/launcher-lineup.md` and
`docs/launcher-agent-integration.md`.

---

## 1. URLs and addresses

| Thing | Address |
|---|---|
| LANoMAT | `https://lan.raute1.org` |
| Launcher page | `https://lan.raute1.org/launcher` — **live currently 404**, deploy first |
| First-lineup game page | `https://lan.raute1.org/games/need-for-speed-underground-2` |
| UT2004 server | `10.23.0.40:7777` (query `7778`, “Raute1 LAN”) |
| Our NFSU2 server (NFS-Online) | `10.23.3.220:20922` (test laptop) → target `10.23.0.12:20922` |
| NFSU2 server ports | TCP `13505, 20921, 20922, 20923`; UDP `20000–20005`; `13506` loopback only |
| NFSU2 browser | `https://nfsu2.xyz/` — **community port, NOT our server** |

`nfsu2.xyz` is the public “Underground 2 Revived” browser port (`th0mk/nfsu2-web`).
Our own server is the Python `moio9/NFS-Online-Server` running at the address above.

---

## 2. Deploy

```bash
cd /opt/lanomat
git pull --ff-only
./scripts/deploy/deploy.sh

docker compose --profile prod exec -T app \
  php artisan lanomat:recipes:sync --only=unreal-tournament-2004 --json
docker compose --profile prod exec -T app \
  php artisan lanomat:recipes:sync --only=need-for-speed-underground-2 --json
docker compose --profile prod exec -T app \
  php artisan lanomat:recipes:sync --only=need-for-speed-underground-2-browser --json
```

Then verify:

```bash
curl -sI https://lan.raute1.org/launcher | head -1          # must be 200, currently 404
curl -s  https://lan.raute1.org/api/v1/servers | head -c 300 # new endpoint after deploy
```

MCP pagination must work after deploy (`per_page=100`, all 24 tools, `nextCursor: null`).

---

## 3. First lineup / UI

`config/launcher.php`:

```conf
LAUNCHER_LINEUP=unreal-tournament-2004,need-for-speed-underground-2,need-for-speed-underground-2-browser
```

That hides every other recipe from the launcher, `/games`, the home chat and the
MCP `recipes_list`; the database is untouched. The native NFSU2 card and the browser card are both in the first lineup; the native one is first. To keep only the native install:

```conf
LAUNCHER_LINEUP=unreal-tournament-2004,need-for-speed-underground-2
```

then `php artisan config:clear` (or `config:cache`) and redeploy. The browser
recipe is the zero-install fallback; the native recipe installs the game and
the NFS-Online ASI client.

The launcher library shows UT2004 with Install/Repair/Start; the browser recipe
shows **Spielen** and opens `play_url`; the native recipe uses the generic
base/verify/launch fallback.

---

## 4. NFSU2 server (our own)

```bash
cd /opt/lanomat
NFS_ONLINE_SRC=/srv/nfs-online \
NFS_PUBLIC_HOST=10.23.0.12 \
docker compose -f docker/nfs-online/compose.yml up -d --build
docker compose -f docker/nfs-online/compose.yml ps
```

- Host decision: **10.23.0.12** for the event (same LAN as clients); the tested
  fallback is `10.23.3.220` (laptop).
- `PUBLIC_HOST` is what the server advertises; set it to the address clients use.
- Firewall: TCP `13505`, `20921:20923`; UDP `20000:20005`. Docker publishes
  through the `DOCKER` chain; verify from a second LAN host.
- Account/persona: `python nfs_online.py create-account` (the default auth is
  password; the client uses the persona). The server has no A2S/UT2004 query, so
  the server-browser entry is static (`query_protocol = NULL`).
- Client: `scripts/net_u2.asi` + `scripts/net_u2.ini` (`host = 10.23.0.12` in
  **both** `[network]` and `[lan]`); `dinput8.dll` at the game root. Linux:
  `WINEDLLOVERRIDES=dinput8=n,b wine SPEED.EXE` or Proton.
- The game base is `nfsu2-portable.zip` (2.59 GB, 1.2, `SPEED.EXE`, `DATA/`,
  `CARS/`, `TRACKS/`); `net_u2.asi` SHA-256
  `8db6557e685cf2dd47f825e62cc202cbace85f6cc4a43af271b8238e0367a04f`.
- Not done live: the `GameServer`/`Game` rows in the admin panel and the
  `10.23.0.12` container deploy (SSH there is password-only).

Admin server entry (when the host is final): create game
`need-for-speed-underground-2`, permanent server, source **external**, address
`10.23.0.12`, port `20922`, query **none**, connect string `10.23.0.12`.
`nfsu2://` is not in the join-URL allowlist; the launcher shows the host as copy
text.

---

## 5. Files / file host

| File | State |
|---|---|
| `ut2004-base.zip` | 2.5 GB flat base, complete, upload paused (resume) |
| `ut2k4+keygen.zip` | nosTEAM **installer**, not a portable base |
| `ut2004-portable-3374-{windows,linux,macos}.zip` | built, verified, not uploaded |
| `nfsu2-portable.zip` | **uploaded as SharedFile 20**, 2,602,084,675 B, sha256 `7a2897a0498acc8da5975463a68db77431a94cfd04eb2c80eed4a0acbf35efcf`; draft recipe 25 plan matches (win/linux, `missing: []`). Local build: 2,602,084,719 B, sha256 `0d7028…7219` |
| `net_u2.asi` / `net_u2.ini` | built, 112,640 B / 606 B |

Upload only works on the **LANoMAT** WLAN (`10.23.0.0/22`), not on `qc`
(`10.13.69.0/24`):

```bash
scripts/ops/ut2004/upload-file.sh \
  '/home/reneil1337/Downloads/ut2004-base.zip' ut2004-base.zip 64
```

The UT2004 flat base is complete; the upload was stopped for gaming and can be
resumed (the state file is kept). The portable archives are optional upgrades;
the flat base + OS patch already works.

---

## 6. Discord

- Announcement text: `docs/ops/announcements/2026-11-first-lineup.md`
- Webhook poster: `scripts/ops/discord-announce.sh --dry-run` / `--file ...`
- The text correctly calls `nfsu2.xyz` the **community** browser port.
- Do **not** post until `/launcher` is deployed (currently 404).
- For the real post, set `DISCORD_WEBHOOK_URL` (or `--webhook-file`); no webhook
  is committed.
- Bot → pi/MCP: `docker/discord-gateway/agent.mjs`; message content, mentions
  and `/ask`; deterministic MCP fallback; 24 Node tests green. Env:
  `DISCORD_BOT_TOKEN`, `LANOMAT_URL`, `LANOMAT_TOKEN`, `PI_MODEL`. The bot
  replies with install/join links; it does not install on a user's PC.

---

## 7. MCP / pi agent

- 24 tools in `LanomatServer`; page size 15 (page 1) + 9 (page 2). The live
  m12-768 cursor replayed page 1; HEAD fixes the cursor and supports
  `per_page=100` / numeric strings / padded base64url.
- `lanomat-agent mcp install` writes `~/.pi/agent/mcp.json`; token from
  `LANOMAT_TOKEN`, never in git.
- Tool matrix: `lanomat_me`, events, files, `recipes_list/get/install_plan/avatars`,
  `servers_list`, schedule/polls/catering/LFG; writes are registration, match,
  vote, LFG, draft recipe.
- The lineup filter applies to `recipes_list`; it does not remove tools.
- Live `GET /api/v1/servers` is 404 on m12-768; `servers_list` MCP works.
  After deploy the REST endpoint and the MCP cursor work.

---

## 8. macOS / launcher

- macOS release job uses `macos-15` for arm64 + `macos-15-intel` best effort;
  empty `APPLE_*` secrets are unset so an unsigned `.dmg` builds; signing is a
  separate step once a Developer ID exists.
- `clients/launcher` generic fallback handles non-UT2004 recipes: base
  extraction, plan verify paths, Windows `.exe` / Linux `wine` launch. UT2004
  path unchanged.
- `/launcher` and the macOS download are not live until deploy + artifact copy
  into `clients-dist/launcher/`.
- Permanent blocker: no SSH from this laptop to `46.225.28.53`; deploy with the
  key that is on the server.

---

## 9. Known issues / blockers

1. **Live `/launcher` 404**, `/api/v1/servers` 404, MCP page-2 cursor stale:
   deploy `main`.
2. **SSH deploy key** missing from this laptop; admins deploy.
3. **File host only on the event LAN**; uploads need the `LANoMAT` WLAN.
4. **`nfsu2.xyz` is not ours**; do not call it our server.
5. **NFSU2 native end-to-end** (game client → server login/lobby/race) not
   tested; server + ASI build are tested separately.
6. **Server host** currently the laptop `10.23.3.220`; target `10.23.0.12`.
7. **Live Game/Server rows** for NFSU2 not created yet; admin steps above.
8. **Recipe validator**: 4 pre-existing errors in `uevr` / `god-of-war-iii-remastered`
   (aliases/additionalProperties); the UT2004, NFSU2 and browser recipes validate.
   The browser recipe has a warning because it deliberately has no base/iso.
9. **No Mac runtime test**; the browser port is tested in Brave/Chrome on Linux.
10. **Discord webhook** absent; announcement is prepared.

---

## 10. Evidence

- UT2004 installed and starts at **v3374**, main menu visible.
- NFSU2 browser boots to the profile screen in Chrome and Brave with WebGPU;
  screenshots in `docs/screenshots/nfsu2/`.
- NFS-Online server: Python tests 30 OK; Docker healthy; ports bound; client
  `net_u2.asi` PE32 built.
- Launcher core: 211 tests; generic base test; macOS verify/launch tests.
- Discord agent: 24 Node tests.
- PHP: GameRecipes 151, Discord 124, MCP 205 (the 2 pagination expectations
  were corrected to 24/9 after the 24-tool count).


---

## 11. Live upload/recipe facts (2026-11-21 16:49)

- **SharedFile 20**: `nfsu2-portable.zip`, approved, **2.602.084.675 B**, SHA-256
  `7a2897a0498acc8da5975463a68db77431a94cfd04eb2c80eed4a0acbf35efcf`.
  Download `https://lan.raute1.org/api/v1/files/20/download`; byte ranges verified.
  The local rebuild is 2.602.084.719 B / `0d7028…7219`; the +44 B is only
  `README-LANoMAT.txt`, the game/ASI/loader bytes are identical.
- **Recipe 25 is live as draft**, created via `POST /api/v1/recipes`; base rows
  280/281 already match SharedFile 20; `plan` for windows/linux reports
  `missing: []` and `matched`. It lacks `metadata`/`launch_targets`/`version`
  because that REST path is lossy: run the YAML sync, then check/publish.
- **Host:** the uploaded package ini advertises `10.23.0.12`; the running test
  server is the laptop `10.23.3.220`. The recipe step currently rewrites
  `net_u2.ini` to `10.23.3.220`. For the permanent VM server, align
  `make_package.sh --server 10.23.0.12`, the YAML step and the server env.
- **Browser recipe not live yet**; `need-for-speed-underground-2-browser`
  needs the same deploy/sync. `nfsu2.xyz` remains the community port.
- **Admin server row**: create game `need-for-speed-underground-2` and a
  permanent external server `NFSU2 (NFS-Online, unser Server)`, address
  `10.23.3.220` (test) or `10.23.0.12` (target), port `20922`, query empty,
  connect string host. The `LauncherLineupSeeder` does this after deploy.
