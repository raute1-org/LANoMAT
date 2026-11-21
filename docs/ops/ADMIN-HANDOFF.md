# Admin handoff — game sharing, server browser install links, recipe pull requests

Date: 2026-11-21 · Branch: `main` · Commit: the commit that contains this file (`git log -1 --format=%h`)

This is the runbook for the admin who deploys and configures this release. It has two parts:
what is new and what to do about it (sections 1 to 8), and what is still open from the first-lineup
handoff (section 9). Details live in `docs/infra/recipe-pull-requests.md`,
`docs/deploy-lan-raute1.md`, `docs/ops/launcher-lineup.md`, `docs/infra/mcp-security.md` and the
participant guide `docs-site/pages/guides/share-a-game.mdx`.

**Nothing in this release has been tried on real hardware or against the real GitHub API.** It is
covered by automated tests with faked external systems. Section 6 is the short real-world check to
run after the deploy; section 8 lists what is unverified.

---

## 1. What is new

- **Spiel teilen.** Participants share a ready-to-run game on `/games/share`: they upload a
  `.zip` or `.tar.bz2` (through the existing file host), name the start file, and get a draft.
  Entry points: "Spiel teilen" on `/games`, "Als Spiel teilen" on an own archive under Dateien, and
  in the launcher.
- **One review step for the orga.** In `/admin/recipes`, the action "Dateien freigeben und
  veröffentlichen" approves the pinned file and publishes the game. The published game appears on
  `/games`, in the launcher and next to its servers without a config change.
- **Server browser leads to the game.** Every running server offers "Im Launcher beitreten"
  (installs the game when it is missing, then joins), "Spiel installieren" and "Mitspieler suchen".
- **Recipe pull requests (optional, off).** A shared game's recipe text can be proposed to this
  repository as a pull request automatically. Needs a GitHub token (section 4).
- **A fix for "Installieren does nothing".** When no launcher took over a click, the page now
  says so and offers the download. The cause on production is in section 3.

---

## 2. Deploy

```bash
cd /opt/lanomat
./scripts/deploy/deploy.sh          # pulls main, rebuilds app queue scheduler reverb-prod, migrates
```

The deploy runs one new migration (`2026_11_22_200000_add_sharing_to_game_recipes`: seven nullable
or defaulted columns on `game_recipes`; no data is changed).

Then, once:

```bash
docker compose --profile prod exec -T app php artisan lanomat:recipes:sync --json
docker compose --profile prod exec -T app php artisan lanomat:preflight
```

The sync refreshes the catalogue from the configured source (`RECIPES_SOURCE`) and keeps every
local decision (status, pinned files, lineup switch). `--only=<slug>` and `--prune` are safe to
combine now: a recipe that still exists in the source is never deleted.

Requirements that already hold on production and must stay: `MODULE_GAME_RECIPES=true`,
`MODULE_FILES=true`, `MODULE_MCP=true` (or `MODULES_PROFILE=full`), and a running `queue` service.

---

## 3. Publish the launcher build (this is why "Installieren" did nothing)

On production, "Installieren" on a game page currently fires the bare `lanomat://install/<slug>`
link. The page only sends people to the launcher download when a launcher build is published on
the server, and `clients-dist/launcher/` is not part of git, so `git pull` never brings one.

```bash
cd /opt/lanomat
scripts/ops/build-launcher.sh       # Linux x86_64; compiles in Docker, needs a clean tree
ls clients-dist/launcher/           # manifest.json, SHA256SUMS, the tarball
```

The Windows portable exe comes from the GitHub workflow "Release launcher" (`workflow_dispatch`,
or a `launcher-v*` tag): download its `launcher-release` artifact and copy the contents into
`clients-dist/launcher/`. Details: `docs/deploy-lan-raute1.md`, "LANoMAT Launcher download".

Build it from this release: the launcher's own screens changed (the "Spiel teilen" entry, copy for
more than one game), and those ship inside the binary.

Check: `php artisan lanomat:preflight` shows "Launcher-Download" as OK, `/launcher` offers the
download, and "Installieren" on a game page leads to `/launcher`.

---

## 4. Recipe pull requests (optional)

Skip this section to leave the feature off; everything else works without it.

1. Create a **fine-grained personal access token** on GitHub (Settings → Developer settings →
   Personal access tokens → Fine-grained tokens): resource owner `raute1-org`, repository access
   "Only select repositories" → `LANoMAT`, repository permissions **Contents: Read and write** and
   **Pull requests: Read and write**. Nothing else. Prefer a bot account over a personal one: the
   pull requests are opened in that account's name.
2. In `/opt/lanomat/.env`:

   ```dotenv
   RECIPES_PR_ENABLED=true
   RECIPES_PR_REPOSITORY=raute1-org/LANoMAT
   RECIPES_PR_TOKEN=<the token>
   ```

3. Recreate the services so they read the new environment:

   ```bash
   docker compose --profile prod up -d app queue scheduler reverb-prod
   docker compose --profile prod exec -T app php artisan lanomat:preflight
   ```

   "Rezept-Katalog (Pull Requests)" must say that the token may read the catalogue and open pull
   requests. Every other answer names what is wrong (token rejected, repository not found, no
   write permission).

What it does with the defaults:

| Setting | Default | Meaning |
|---|---|---|
| `RECIPES_PR_TRIGGER` | `publish` | A pull request is opened when the orga publishes a shared or panel-written recipe. `contribute` opens it the moment a participant shares. |
| `RECIPES_PR_PER_USER_HOURLY` | `6` | Automatic proposals per contributor per hour. |
| `RECIPES_PR_MAX_OPEN` | `30` | Open pull requests of this installation; updates of an open one do not count. |
| `RECIPES_PR_ATTRIBUTION` | `false` | Adds the contributor's display name to the pull request. Leave it off: the repository is public. |
| `RECIPES_PR_BASE` / `_BRANCH_PREFIX` / `_PATH` | `main` / `recipes/` / `resources/recipes` | Where the files go. |

- One branch and one pull request per recipe (`recipes/<slug>`); a later change updates it.
- Only recipe text travels: `recipe.yaml`, `SKILL.md` and the row in `_catalog.md`. Never game
  files, never file-host addresses.
- Before anything is sent, the text is checked for addresses, e-mail addresses, keys and tokens,
  and against the rules of the repository's validator. A recipe that fails is not sent; the panel
  shows "Nicht gesendet" with the reason under "Katalog (Pull Request)".
- The workflow "Recipes" validates every recipe pull request. Read the diff before merging: recipe
  steps and launch targets are commands that run on players' PCs.
- After a merge, the next deploy ships the recipe in the bundled catalogue; the sync adopts it and
  keeps the local state.

By hand: `php artisan lanomat:recipes:propose <slug>` proposes one recipe now;
`php artisan lanomat:recipes:pr-status` refreshes the states (it also runs hourly).

---

## 5. What the orga does during the LAN

1. `/admin/recipes` shows a badge with the number of shared games that wait. The filter "Geteilte
   Spiele" lists them; the column next to it says whether the file still waits for approval.
2. Open the game. The section **"Start und Launcher"** shows every launch command and whether the
   launcher will offer the game. Read the steps and the launch commands: they run on players' PCs.
3. **"Dateien freigeben und veröffentlichen"** approves the pinned archive and publishes. The
   confirmation lists the files and the launch commands once more. (A rejected file blocks it:
   the participant has to share again with another file.)
4. The game is now on `/games` and in the launcher. "In der Spieleliste zeigen" is switched on
   automatically for shared games; switch it off to hide a game again without unpublishing it.
5. If a shared game should not be installable through the launcher, set the launcher switch in
   "Start und Launcher" to "Nie anbieten".

`LAUNCHER_LINEUP` keeps working as before; the players see the configured lineup plus every recipe
with the switch. A participant can have at most 20 games waiting for review
(`RECIPES_MAX_DRAFTS_PER_USER`).

---

## 6. Check after the deploy (15 minutes, two PCs)

1. **Share:** as a participant, upload a small portable game as `.zip` under Dateien of the
   current LAN, open `/games/share`, pick it, enter title and start file, "Spiel teilen". It appears
   under "Deine geteilten Spiele" with "Die Orga prüft gerade".
2. **Review:** as orga, `/admin/recipes` → the game → check "Start und Launcher" → "Dateien
   freigeben und veröffentlichen".
3. **Find:** as another participant, the game is on `/games` with "Installieren".
4. **Install:** on a PC with the launcher from this release, install it and start it.
5. **Join:** create or use a server of that game (admin: the game's slug must be the recipe's
   slug, or its name must match the title or an alias). On `/servers/all` the server shows "Im
   Launcher beitreten"; one click installs when needed and joins.
6. **No launcher:** on a PC without the launcher, click "Im Launcher beitreten". After two to
   three seconds the page asks "Kein Launcher-Fenster aufgegangen?" and offers the download.
7. **Pull request** (only with section 4): after step 2, a pull request `recipes/<slug>` is open
   in the repository and the panel shows "Pull Request offen". Merge it or close it; within the
   hour (or after `lanomat:recipes:pr-status`) the panel says "Im Katalog" or "Pull Request
   geschlossen".

---

## 7. New switches at a glance

| Variable | Default | Section |
|---|---|---|
| `RECIPES_PR_ENABLED`, `RECIPES_PR_REPOSITORY`, `RECIPES_PR_TOKEN` | off, empty, empty | 4 |
| `RECIPES_PR_TRIGGER` | `publish` | 4 |
| `RECIPES_PR_PER_USER_HOURLY`, `RECIPES_PR_MAX_OPEN` | `6`, `30` | 4 |
| `RECIPES_PR_ATTRIBUTION` | `false` | 4 |
| `RECIPES_PR_BASE`, `RECIPES_PR_BRANCH_PREFIX`, `RECIPES_PR_PATH`, `RECIPES_PR_API_URL` | `main`, `recipes/`, `resources/recipes`, GitHub | 4 |
| `RECIPES_MAX_DRAFTS_PER_USER` | `20` | 5 |

New scheduled commands (they run in the `scheduler` service on their own):
`lanomat:recipes:pr-status` hourly, `lanomat:files:hash --limit=20` nightly at 04:45 (files that
arrive through the resumable upload have no SHA-256 until then).

New Preflight check: "Rezept-Katalog (Pull Requests)". It is skipped while the feature is off and
never turns the event-day check red.

---

## 8. Known limits and what is not verified

- **Not tried for real:** the whole journey on real PCs, the launcher build of this release on
  Windows and Linux, and the pull request path against the real GitHub API (it is tested against a
  fake that follows GitHub's documented behaviour). Section 6 is that test.
- **The "no launcher" notice is a guess by design.** A browser cannot ask whether the launcher is
  installed; the page only notices that nothing took the focus. A launcher window that opens
  behind the browser also triggers the notice, which is why it asks instead of claiming a failure.
- **Shared games are Windows and Linux only**, as a `.zip` or `.tar.bz2` that runs as it is.
  Installers and disc images are not supported by the share page.
- **A shared game whose archive is later deleted or rejected** stays listed; the launcher then
  reports the missing file. The recipe list in the panel shows "Datei fehlt".
- **The launcher's install folder** on Windows is `C:\Games\<title>` with only letters, digits,
  space, dot, underscore and hyphen kept. Games installed before this release keep their folder;
  a title with other characters gets a new folder name on the next install.
- **The public-safety check catches accidents**, not someone who hides an address on purpose.
  That is why the default opens pull requests only for recipes the orga has published.
- **The installer puts the install path into commands as text.** A player who chooses an install
  path with a quote character in it can break their own install command. Not changed here.
- **The NFSU2 recipe still carries the test host address** in its steps and metadata
  (`resources/recipes/need-for-speed-underground-2/recipe.yaml`); see section 9.
- **CI stays disabled by hand** (`gh workflow enable CI`). The new "Recipes" workflow and the
  existing public-safety and gitleaks workflows run.

---

## 9. Still open from the first-lineup handoff

Unchanged by this release; listed so nothing gets lost.

| Thing | Address |
|---|---|
| LANoMAT | `https://lan.raute1.org` |
| UT2004 server | `10.23.0.40:7777` (query `7778`, "Raute1 LAN") |
| Our NFSU2 server (NFS-Online) | `10.23.3.220:20922` (test laptop) → target `10.23.0.12:20922` |
| NFSU2 server ports | TCP `13505, 20921, 20922, 20923`; UDP `20000–20005`; `13506` loopback only |
| NFSU2 browser | `https://nfsu2.xyz/` — community port, **not our server** |

- **First lineup:** `LAUNCHER_LINEUP=unreal-tournament-2004,need-for-speed-underground-2,need-for-speed-underground-2-browser`.
  Shared games add themselves through the panel switch (section 5).
- **NFSU2 server on the target host:**

  ```bash
  NFS_ONLINE_SRC=/srv/nfs-online NFS_PUBLIC_HOST=10.23.0.12 \
    docker compose -f docker/nfs-online/compose.yml up -d --build
  NFS_SERVER_HOST=10.23.0.12 docker compose --profile prod exec -T app \
    php artisan db:seed --class=LauncherLineupSeeder
  ```

  Firewall: TCP `13505`, `20921:20923`; UDP `20000:20005`. Align the host in the recipe's
  `net_u2.ini` step and in `make_package.sh --server` with the final address, then sync and publish
  the native NFSU2 recipe and pin `nfsu2-portable.zip`.
- **Files:** `nfsu2-portable.zip` is uploaded (SharedFile 20). `ut2004-base.zip` (2.5 GB) and the
  portable UT2004 archives are built but not uploaded; uploads only work on the event LAN:
  `scripts/ops/ut2004/upload-file.sh <file> <name> 64`.
- **Discord:** announcement text in `docs/ops/announcements/2026-11-first-lineup.md`, poster
  `scripts/ops/discord-announce.sh --file …` with `DISCORD_WEBHOOK_URL`; post only after
  `/launcher` offers a download. Bot: `DISCORD_AGENT_ENABLED=true`, `LANOMAT_URL`, `LANOMAT_TOKEN`,
  `PI_MODEL`.
- **Not tested:** NFSU2 race with two clients and split screen; any macOS run.
- **MCP:** the server registers 29 tools (`tools/list` with `per_page=100` returns all of them).

Evidence and detail for these: `docs/ops/ut2004-pilot.md`, `docs/ops/nfsu2.md`,
`docs/ops/nfsu2-server.md`, `docs/ops/nfsu2-package.md`, `docs/ops/discord-agent.md`,
`docs/launcher-agent-integration.md`.
