# Admin handoff — game sharing, server browser install links, recipe pull requests

Date: 2026-11-22 · Branch: `main` · Commit: the commit that contains this file (`git log -1 --format=%h`)

This is the runbook for the admin who deploys and configures this release. It covers two rounds on
top of the last deploy: game sharing, server-browser install links and recipe pull requests
(2026-11-21), and the gap round that finished them (2026-11-22 — the NFSU2 server address, the
catalogue preview, the "Hier spielen" line, the missing-files line). It has two parts: what is new
and what to do about it (sections 1 to 8), and what is still open from the first-lineup handoff
(section 9). Details live in `docs/infra/recipe-pull-requests.md`,
`docs/deploy-lan-raute1.md`, `docs/ops/launcher-lineup.md`, `docs/infra/mcp-security.md` and the
participant guide `docs-site/pages/guides/share-a-game.mdx`.

**The user journey has been driven in a real browser, on test data.** On 2026-11-22 a headless
Chromium ran the whole journey end to end (`scripts/smoke/game-sharing-smoke.mjs`, section 6): sharing
with a form upload, the refusals, the orga's review and one-step publish, finding the game, the
server row's actions, the missing-files line, and what a `lanomat://` click does with no launcher
installed. Not verified: a real launcher taking over (the "opened" path is simulated), any other
browser (Firefox and WebKit were not installed), the resumable upload path, real hardware, Windows,
the NFSU2 step, and the real GitHub API. A launcher build of this tree compiles and packages on
Linux; it was not started. Section 6 is the short check on two PCs after the deploy; section 8 lists
what stays unverified.

---

## 1. What is new

- **Spiel teilen.** Participants share a ready-to-run game on `/games/share`: they upload a
  `.zip` or `.tar.bz2` (through the existing file host), name the start file, and get a draft.
  Entry points: "Spiel teilen" on `/games`, "Als Spiel teilen" on an own archive under Dateien, and
  in the launcher. A form upload stores the same cleaned file name the resumable path stores
  (control, invisible and bidirectional formatting characters and any path removed), so a shared
  game's recipe text never carries a line break, a directory or a name that reads as something it is
  not. Existing rows are not rewritten; a name stored before the cleaning is cleaned again when the
  download is offered.
- **One review step for the orga.** In `/admin/recipes`, the action "Dateien freigeben und
  veröffentlichen" approves the pinned file and publishes the game. The published game appears on
  `/games`, in the launcher and next to its servers without a config change.
- **Server browser leads to the game.** A server whose game has a published recipe in the lineup
  offers the launcher handoff, "Spiel installieren" and "Mitspieler suchen". The handoff is labelled
  **"Im Launcher beitreten"** when the link names the server (UT2004 passes the address on the
  command line, another game opens a join URL the launcher may open) and **"Im Launcher starten"**
  when it cannot — the launcher then installs and starts the game and the player joins from inside
  it. A server without such a recipe keeps its address and connect button.
- **Recipe pull requests (optional, off).** A shared game's recipe text can be proposed to this
  repository as a pull request automatically. Needs a GitHub token (section 4).
- **A fix for "Installieren does nothing".** When no launcher took over a click, the page now
  says so and offers the download. The cause on production is in section 3.

New in the gap round (2026-11-22), in sections 5, 6, 8 and 9:

- **The NFSU2 server address is configuration, not recipe text.** `NFS_SERVER_HOST` is the only
  source (no default); the NFSU2 recipe carries `{{server_host}}`, which an install plan fills in —
  only from a server whose game is **exactly** `need-for-speed-underground-2` (no alias, no name
  match). Section 9 has the exact steps, including what to do about a server row an earlier run
  left behind.
- **The panel says what would block a pull request before the orga publishes** ("Textprüfung für
  den Katalog"), and the recipe list shows the reason under "Nicht gesendet" / "Fehlgeschlagen".
- **A contribution can be completed without the panel**: the MCP tool `recipe_contribute` takes
  `skill_markdown`, and its answer carries `catalog.problems` (and `problems_when_published`). An
  assistant repeats the call until `problems` is empty; a second call **replaces** the draft, so it
  must send the complete recipe (an omitted field is cleared). The orga still reviews and publishes.
- **"Hier spielen"** on a server card and row: up to five faces of the LANoMAT accounts the
  Presence read model places on that server, plus a count. It is shown to **registered participants
  of a publicly visible event and to orga** (an account alone learns nothing, and a draft event
  shows no line), and somebody who hides their location is neither listed nor counted; the viewer
  always sees themselves. Needs the `Presence` module.
- **"Not all files are on the LAN"** as one calm line next to the install actions
  (`install.lanReady` false): "Für dieses Spiel liegen gerade nicht alle Dateien auf dem
  LAN-Server; eine Neuinstallation kann deshalb scheitern. …". The launcher handoff stays: whoever
  already has the game can still join.
- **The Node agent refuses an install folder it cannot quote** (a quote, `$`, a backtick, a control
  character, `;`, `&`, `|`, `<`, `>`, a trailing backslash; on Windows also the curly quotes and
  `%`, `!`, `^`), instead of building a broken command. A leading `~/` is resolved for the plan's
  folder and for `--target`; before, `~/Games/<slug>` was pasted literally into quoted commands on
  Linux and macOS.
- **The match page** keeps one primary action (report/confirm/go live) and renders the server join
  outline instead of a second amber button.
- **A published shared game stops calling itself a draft** in its description, its steps and its
  `SKILL.md`. The share page refuses archive names the installing client would store under another
  name (`:` `"` `<` `>` `|`, a leading dot, two dots in a row) and says why in the list; a form
  upload on the share page now really selects the new archive.

---

## 2. Deploy

```bash
cd /opt/lanomat
./scripts/deploy/deploy.sh          # pulls main (fast-forward), rebuilds the prod services, migrates
```

The script builds and recreates `traefik app queue scheduler reverb-prod postgres redis` with fresh
base images, waits for `app` to be healthy, then runs `migrate --force`. It never touches volumes or
`.env`.

Migrations: this round adds none (no change under `database/migrations`). If the last deploy was
before 2026-11-21, this deploy also runs `2026_11_22_200000_add_sharing_to_game_recipes` — seven
nullable or defaulted columns on `game_recipes`, no data changed.

Then, once:

```bash
docker compose --profile prod exec -T app php artisan lanomat:recipes:sync --json
docker compose --profile prod exec -T app php artisan lanomat:preflight
```

**Why the sync is part of the deploy this time.** The NFSU2 recipe changed in the source
(`resources/recipes/need-for-speed-underground-2/`): its Windows and Linux steps no longer carry an
address but `{{server_host}}`. The panel and the install plans read the recipe from the database,
not from the image, so the new step text reaches them only through `lanomat:recipes:sync`. The
command reads `RECIPES_SOURCE` (default: the bundled `resources/recipes`) and **overwrites** the
row from the YAML: title, summary, description, steps, requirements and the skill text. It keeps
what a file cannot express — the moderation status (a local `status` that differs from the YAML is
not overwritten), the admin's file pins (`shared_file_id`, keyed by role and label), `in_lineup`,
`contributor_user_id` and the `pr_*` columns. `--only=<slug>` narrows what is *updated*; a recipe
that still has a directory in the source is never pruned, and `--only` and `--prune` are safe to
combine. If `RECIPES_SOURCE` points at a NAS copy of the catalogue, that copy must carry the new
NFSU2 `recipe.yaml`; otherwise the sync writes the old step text back. Without the variable the
bundled directory in the image is the source.

Requirements that already hold on production and must stay: `MODULE_GAME_RECIPES=true`,
`MODULE_FILES=true`, `MODULE_MCP=true` (or `MODULES_PROFILE=full`), and a running `queue` service.
The "Hier spielen" line additionally needs `MODULE_PRESENCE=true`, and the viewer must be a
registered participant of that publicly visible event or orga; without either the line simply never
appears (the server rows are unaffected).

---

## 3. Publish the launcher build (this is why "Installieren" did nothing)

On production, "Installieren" on a game page currently fires the bare `lanomat://install/<slug>`
link, and a PC that cannot handle it now ends in the launcher-link notice ("Kein Launcher-Fenster
aufgegangen?", which offers "App herunterladen") instead of doing nothing. The primary action becomes
a link to `/app` only when a launcher build is published on the server, and `clients-dist/launcher/`
is not part of git, so `git pull` never brings one.

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

**Where the players find it: `/app`.** One page lists every downloadable build of the LANoMAT app:
"Sprache und Chat (Desktop-App)" and "Spiele (Launcher)", each with its builds per OS, version,
SHA-256, size and install steps. Every entry point in the app carries the label **"App herunterladen"**
and leads to `/app` (shell header, sidebar, Filament topbar, quick actions, the notice under a
launcher link). `/launcher` and `/talk/client` only redirect to `/app`, so old bookmarks and Discord
posts keep working; the files themselves stay under `/launcher/download/<file>`,
`/launcher/download-latest` and `/talk/client/download/<file>`. Without a build the launcher card
says "Noch kein Launcher veröffentlicht. Bis dahin installierst du die Spiele wie gewohnt von Hand
über die Spiele-Bibliothek."; without either build the page says "Noch keine App veröffentlicht".

Check: `php artisan lanomat:preflight` shows **"Launcher-Download"** as OK (with `GameRecipes` on, as
the launcher needs) and, for the desktop app, **"Desktop-App-Download"**; `/app` lists the build, and
"Installieren" on a game page leads to `/app` (it fires the `lanomat://` deep link when no launcher
build is published or the recipe is not launcher-supported).

A build of this tree compiles on Linux x86_64; it was not started, and not built on Windows. The
launcher is the current desktop build that installs games and will later be folded into the one
LANoMAT app (`docs/superpowers/decisions/2026-11-22-all-in-one-client-adr.md`); the voice app's
"Spiele" tab is a read-only library with an explained placeholder for install/start until that
phase. Until then the launcher is the build the players get, so build it from this release and
publish it. `scripts/ops/build-launcher.sh` refuses a
dirty tree; it does not run fmt/clippy/cargo test (the release workflow does). The new
"Frontend guards" workflow runs the launcher UI contract (`check-ui.mjs`) on a pull request, not the
Rust gate.

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
  and against the rules of the repository's validator. A recipe that fails is not sent. The panel
  now shows the same answer **before** you publish: the section "Katalog (Pull Request)" carries
  "Textprüfung für den Katalog" (what a draft is missing and what would still be missing once
  published, or one line that the text is ready), and both publish confirmations carry one warning
  line with the number of open points. The list shows the state and, under "Nicht gesendet" /
  "Fehlgeschlagen", the first line of the reason.
- A contribution made through the MCP tool can be finished by the contributor: `recipe_contribute`
  takes `skill_markdown` and answers with `catalog.problems`; an assistant repeats the call until
  that list is empty. A second call for the same slug **replaces** the draft — the complete recipe
  must be sent, and a field left out is cleared (the tool description and the how-to resource say
  so). It still only creates a draft — publishing (and the pull request, with the default trigger)
  stays your action.
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
   Below it, "Katalog (Pull Request)" answers "Textprüfung für den Katalog" when pull requests are
   configured: the reasons a pull request would be refused, as a draft and after publishing. Fix
   them (or accept that no pull request is opened) before you publish; publishing is never blocked
   by it.
3. **"Dateien freigeben und veröffentlichen"** approves the pinned archive and publishes. The
   confirmation lists the files and the launch commands once more, counts them ("Schrittbefehle: N ·
   Startbefehle: M") and adds the warning line when the catalogue would refuse the text. (A rejected
   file blocks it: the participant has to share again with another file. When the pinned file is
   already approved — an admin upload is approved on arrival — the action is not offered; use
   **"Veröffentlichen"**, which carries the same confirmation.)
4. The game is now on `/games` and in the launcher. "In der Spieleliste zeigen" is switched on
   automatically for shared games; switch it off to hide a game again without unpublishing it.
5. If a shared game should not be installable through the launcher, set the launcher switch in
   "Start und Launcher" to "Nie anbieten".

`LAUNCHER_LINEUP` keeps working as before; the players see the configured lineup plus every recipe
with the switch. A participant can have at most 20 games waiting for review
(`RECIPES_MAX_DRAFTS_PER_USER`).

Two things the players see next to a server, both of which you do not configure per server:

- **"Hier spielen"** names up to five accounts on the server (plus "+N") from the Presence read
  model. It is shown to registered participants of a **publicly visible** event and to orga (the
  audience of the event's people list; a draft event shows no line, also to the orga), needs
  `MODULE_PRESENCE=true`, and a person's own "Meinen Standort teilen" — somebody who hides their
  location is neither listed nor counted.
- **The missing-files line** appears when the game's archive is not on the LAN right now (the
  pinned file was deleted or rejected, and no other approved archive matches): "Für dieses Spiel
  liegen gerade nicht alle Dateien auf dem LAN-Server; eine Neuinstallation kann deshalb
  scheitern. …". The launcher handoff stays; whoever already has the game can still join. Bring the
  file back or pin another one to make a fresh install possible again.

Publishing also changes the text players read: a shared game's generated description, step notes
and `SKILL.md` stop calling it a draft when the orga publishes it, so "Über das Spiel" no longer
says it needs verification on the LAN.

If you pin a file for a requirement and a contributor later edits the recipe (MCP or panel), the
pin survives only while the requirement still names the same file — same `match` patterns, same
`sha256`. A renamed or duplicated requirement starts without a pin; check the pinned file after
such an edit.

---

## 6. Check after the deploy (20 minutes, two PCs)

1. **Share:** as a participant, upload a small portable game as `.zip` under Dateien of the
   current LAN, open `/games/share`, pick it, enter title and start file, "Spiel teilen". It appears
   under "Deine geteilten Spiele" with "Die Orga prüft gerade".
2. **Review:** as orga, `/admin/recipes` → the game → check "Start und Launcher" → "Dateien
   freigeben und veröffentlichen".
3. **Find:** as another participant, the game is on `/games` with "Installieren".
4. **Install:** download the launcher from **`/app`** (the header's "App herunterladen"), install
   it on a PC from this release and start the game.
5. **Join:** create or use a server of that game (admin: for the install block the game's slug must
   be the recipe's slug, or its name must match the title or an alias). On `/servers/all` the server
   shows the launcher handoff, "Spiel installieren" and "Mitspieler suchen". It says **"Im Launcher
   beitreten"** when the link names the server — UT2004 passes `?address=<host>:<port>`, another
   game opens a join URL the launcher may open — and **"Im Launcher starten"** when it does not (a
   plain `address:port` server of a non-UT2004 game): the launcher then installs and starts the game
   and the player picks the server inside it.
6. **No launcher:** on a PC without the launcher, click the handoff. After 2.5 seconds the page asks
   "Kein Launcher-Fenster aufgegangen?" and offers **"App herunterladen"**, "Nochmal versuchen" and a
   dismiss; the click itself neither navigates nor opens a dialog.
7. **Pull request** (only with section 4): after step 2, a pull request `recipes/<slug>` is open
   in the repository and the panel shows "Pull Request offen". Merge it or close it; within the
   hour (or after `lanomat:recipes:pr-status`) the panel says "Im Katalog" or "Pull Request
   geschlossen".

Four checks for the gap round, ten minutes:

8. **NFSU2 from the plan** (section 9 has the setup): as a participant who sees the native NFSU2
   server, build the install plan (`lanomat-agent plan need-for-speed-underground-2 --json`) and
   check that the Windows and Linux steps show `host = <your NFS host>` where `{{server_host}}` was.
   Then run the agent's `install` on Windows once and on Linux (Wine or Proton) once: the client
   reaches the lobby of that server. The launcher only unpacks the archive, so a launcher install
   keeps the host baked into `nfsu2-portable.zip` — check that archive separately. With no server
   row, or only the browser twin's server, the step must leave the `host` lines of `net_u2.ini` as
   the file carries them (this case has never been run either).
9. **"Hier spielen":** as a registered participant, with two accounts on the same server, one of
   them switches "Meinen Standort teilen" off; the line must drop that person from the faces and
   from the count while the viewer still sees themselves. A logged-in account that is **not**
   registered for the event sees no line at all; without `MODULE_PRESENCE` nobody does.
10. **Not all files on the LAN:** reject or delete the pinned archive of a published shared game;
    its server rows say "Für dieses Spiel liegen gerade nicht alle Dateien auf dem LAN-Server …"
    and keep the launcher handoff. Pin another approved archive to clear the line.
11. **Agent paths:** choose an install folder with an apostrophe (`--target`), e.g. a home directory
    like `…/O'Brien/Games`: the agent must refuse it before downloading, name the character and the
    folder, and suggest `--target`. A folder without such characters must install normally; a
    `--target '~/Games/test'` installs into the home directory, not into a literal `~` folder.

### The scripted journey (optional, test instance only)

`scripts/smoke/game-sharing-smoke.mjs` replays the journey in a headless browser against a seeded
test database — the same run the 2026-11-22 verification used. What it drives (and what it does not
cover) is in `scripts/smoke/README.md`; it changes while the pages do. **Never point it at
production**: the seeder refuses the production environment, but the commands below start with
`migrate:fresh`, which drops every table. Use a throwaway instance (a dev stack or a copy of the
database).

```bash
# in the app container / on the dev stack
php artisan migrate:fresh --force
php artisan storage:link
php artisan db:seed --class=GameSharingSmokeSeeder

# from the checkout with dev dependencies installed (npm ci) and Playwright's
# Chromium (npx playwright install chromium, unless a system Chromium is set)
APP_URL=http://127.0.0.1:8000 node scripts/smoke/game-sharing-smoke.mjs
```

It uses form uploads (`FILES_STORE=local`), writes a placeholder launcher manifest when none is
published, and takes a screenshot of every step. `SMOKE_STRICT=1` makes its findings fail the run;
`SMOKE_BROWSER=firefox` or `webkit` needs the browser installed. It is not in CI. The seeder creates
three throwaway accounts (`smoke-sharer@example.test`, `smoke-orga@example.test`,
`smoke-player@example.test`, password `smoke-demo-password`) and one live LAN, `smoke-lan`.

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
| `NFS_SERVER_HOST` | empty (no built-in address) | 9 |
| `MODULE_PRESENCE` | off in `core` | 5 |

New scheduled commands (they run in the `scheduler` service on their own):
`lanomat:recipes:sync` daily at 04:15 (it also pulls the source over the NFSU2 step change, so a
later recipe edit does not need a deploy), `lanomat:recipes:pr-status` hourly, and
`lanomat:files:hash --limit=20` nightly at 04:45 (files that arrive through the resumable upload
have no SHA-256 until then).

New Preflight check: "Rezept-Katalog (Pull Requests)", registered while `GameRecipes` is on. It is
skipped while `RECIPES_PR_ENABLED=false` and never turns the event-day check red.

---

## 8. Known limits and what is not verified

- **Verified in a real browser (headless Chromium, test data, 2026-11-22):** sharing with a form
  upload, the refusals, the orga's review and one-step publish, finding the game, the server row's
  actions, the missing-files line, and the launcher link with no `lanomat://` handler (the browser
  does not navigate, no dialog, "Öffne den Launcher …", the notice after 2.5 s with "App
  herunterladen", retry and dismiss, a modified click starts nothing, a simulated focus loss shows
  "Launcher geöffnet. …" for about six seconds). `scripts/smoke/game-sharing-smoke.mjs` repeats the
  run (section 6).
- **Not verified:** a real launcher taking over the click (the "opened" path is simulated), Firefox
  and WebKit (not installed), the resumable (tus) upload path, "Hier spielen" on a server row (the
  smoke does not cover presence), real hardware, Windows, and the pull request path against the real
  GitHub API (tested against a fake that follows GitHub's documented behaviour).
- **The NFSU2 step change was never run.** Neither the Windows/PowerShell nor the Linux bash
  variant of `{{server_host}}` in the NFSU2 recipe has executed once — not on Windows, not under
  Wine or Proton, and not with "no server row" (the branch that leaves `net_u2.ini` alone). The
  substitution itself is unit-tested; the shell text is not.
- **The agent's path rules for PowerShell and cmd rest on the shells' documentation**, not on a
  run: PowerShell's `about_Quoting_Rules` for `'`, `"`, the curly quotes and the backtick, `cmd /?`
  and SS64 for `%`, `!` and `^`. The shell operators `;` `&` `|` `<` `>` (refused on every platform)
  and the `~/` resolution were exercised with a real bash; the Windows quotes were not.
- **The "no launcher" notice is a guess by design.** A browser cannot ask whether the launcher is
  installed; the page only notices that nothing took the focus. A launcher window that opens
  behind the browser also triggers the notice, which is why it asks instead of claiming a failure.
- **Shared games are Windows and Linux only**, as a `.zip` or `.tar.bz2` that runs as it is.
  Installers and disc images are not supported by the share page. A file is offered for "Als Spiel
  teilen" only when the share page would take it: an archive name (`.zip`, `.tar.bz2`), no backtick,
  `*` or `?`, none of `: " < > |`, no leading dot, no two dots in a row, and at least three letters
  or digits left for the match pattern. Upload names lose control, invisible and bidirectional
  formatting characters; a name stored before that rule is cleaned when the download is offered.
- **A shared game whose archive is later deleted or rejected** stays listed; the recipe list in the
  panel shows "Datei fehlt", and its server rows say not all files are on the LAN. The launcher
  then reports the missing file on an install attempt; joining an existing install still works.
- **The launcher's install folder** on Windows is `C:\Games\<title>` with only letters, digits,
  space, dot, underscore and hyphen kept; on Linux and macOS `~/Games/<slug>`. Games installed
  before this release keep their folder; a title with other characters gets a new folder name on
  the next install.
- **The launcher still pastes the install path into commands as text.** The Node agent refuses a
  folder it cannot quote (a quote, `$`, a backtick, a control character, `;` `&` `|` `<` `>`, a
  trailing backslash, and on Windows the curly quotes, `%`, `!` and `^`); the launcher's own Rust
  installer has no such guard. Its default folders cannot contain those characters.
- **A plan names a server only of the recipe's exact game.** Since 2026-11-22 an install plan takes
  `{{server_host}}` only from a server whose game slug is the recipe's slug (the browser twin, a
  sequel or a name-alike never qualifies) that is `Ready` or `Antwortet nicht` (it runs, but does
  not answer a query), never one that is off, failed or still being set up; among them, an answering
  server first, then a permanent one, then the lowest id. The recipe detail (API, MCP, native
  client) fills the same values, so no client receives a raw placeholder. The concrete consequence:
  without a native NFSU2 server the step now leaves `net_u2.ini` alone instead of writing
  `nfsu2.xyz` into it.
- **The public-safety check catches accidents**, not someone who hides an address on purpose.
  That is why the default opens pull requests only for recipes the orga has published. The guard
  (`scripts/ops/check-public-safe.sh`) now also scans `resources/recipes/`, `config/` and `lang/`.
- **CI stays disabled by hand** (`gh workflow enable CI`). The new "Frontend guards" workflow (JS
  unit tests and the launcher UI contract), the "Recipes" workflow and the existing public-safety
  and gitleaks workflows run.

---

## 9. Still open from the first-lineup handoff

Still open from the first-lineup handoff and listed so nothing gets lost; where this release changed
an instruction, the bullet says so.

| Thing | Address |
|---|---|
| LANoMAT | `https://lan.raute1.org` |
| UT2004 server | `10.23.0.40:7777` (query `7778`, "Raute1 LAN") |
| Our NFSU2 server (NFS-Online) | `10.23.3.220:20922` (test laptop) → target `10.23.0.12:20922` |
| NFSU2 server ports | TCP `13505, 20921, 20922, 20923`; UDP `20000–20005`; `13506` loopback only |
| NFSU2 browser | `https://nfsu2.xyz/` — community port, **not our server** |

- **First lineup:** `LAUNCHER_LINEUP=unreal-tournament-2004,need-for-speed-underground-2,need-for-speed-underground-2-browser`.
  Shared games add themselves through the panel switch (section 5).
- **NFSU2 server on the target host:** start the server, then tell LANoMAT the host.

  ```bash
  NFS_ONLINE_SRC=/srv/nfs-online NFS_PUBLIC_HOST=10.23.0.12 \
    docker compose -f docker/nfs-online/compose.yml up -d --build
  ```

  `NFS_SERVER_HOST` is the **only** source of that host: there is no built-in default. Put
  `NFS_SERVER_HOST=10.23.0.12` into `/opt/lanomat/.env`, recreate the app container so it reads the
  new value (`docker compose --profile prod up -d app queue scheduler reverb-prod`), then register
  the server row:

  ```bash
  docker compose --profile prod exec -T app php artisan db:seed --class=LauncherLineupSeeder --force
  ```

  A shell variable in front of `docker compose exec` does *not* reach the command inside the
  container (use `.env` + recreate, or `docker compose exec -e NFS_SERVER_HOST=…`). `--force` is
  required: `db:seed` asks for confirmation in production, and with `-T` (no TTY) the prompt would
  cancel the run. Without a host the seeder prints a warning: it registers no native NFSU2 server
  if there is none, and **leaves one that exists from an earlier run exactly as it is** — that row's
  address is what install plans keep naming until you move it. **On the production upgrade the
  earlier row still carries the old test address**: set `NFS_SERVER_HOST` to the final host, recreate
  the app and run the seeder again (or edit the server in the panel) before players install NFSU2.
  The browser server row is unchanged. Firewall: TCP `13505`, `20921:20923`; UDP `20000:20005`.
- **No recipe text names the host.** The NFSU2 recipe's Windows and Linux steps carry
  `{{server_host}}`; an install plan (and the recipe detail the agent reads its launch targets from)
  fills it only from a server whose game slug is **exactly** the recipe's slug, that is Ready or at
  least answering-not, never one that is off or being set up; an answering server first, then a
  permanent one, then the lowest id. It rewrites both `host` lines of `net_u2.ini`. Without such a
  server the step leaves the file as it is, and the player takes the address from `/servers` or from
  you. A name-alike (the browser twin, a sequel) never qualifies — before 2026-11-22 it did, and a
  fresh installation without a native server wrote `nfsu2.xyz` into the game's configuration.
  Editing the recipe is no longer part of a host change; setting `NFS_SERVER_HOST` (or the server
  row) is.
- **The portable archive still carries a host.** `nfsu2-portable.zip` was built with
  `make_package.sh --server` and has the host baked into its `scripts/net_u2.ini`. The launcher
  never runs step commands, so a launcher install gets exactly that host (or the `net_u2.ini` from
  the LAN file host, if one is matched); only the agent's install plan rewrites it. Build the
  archive with the final host — see `docs/ops/nfsu2-package.md` — then sync and publish the native
  NFSU2 recipe and pin `nfsu2-portable.zip`.
- **Files:** `nfsu2-portable.zip` is uploaded (SharedFile 20). `ut2004-base.zip` (2.5 GB) and the
  portable UT2004 archives are built but not uploaded; uploads only work on the event LAN:
  `scripts/ops/ut2004/upload-file.sh <file> <name> 64`.
- **Discord:** announcement text in `docs/ops/announcements/2026-11-first-lineup.md`, poster
  `scripts/ops/discord-announce.sh --file …` with `DISCORD_WEBHOOK_URL`; post only after
  `/app` offers a download. Bot: `DISCORD_AGENT_ENABLED=true`, `LANOMAT_URL`, `LANOMAT_TOKEN`,
  `PI_MODEL`.
- **Not tested:** NFSU2 race with two clients and split screen; any macOS run.
- **MCP:** the server registers 29 tools (`tools/list` with `per_page=100` returns all of them).

Evidence and detail for these: `docs/ops/ut2004-pilot.md`, `docs/ops/nfsu2.md`,
`docs/ops/nfsu2-server.md`, `docs/ops/nfsu2-package.md`, `docs/ops/discord-agent.md`,
`docs/launcher-agent-integration.md`.
