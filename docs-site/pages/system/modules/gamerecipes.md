---
title: Game recipes
description: Recipes as agent skills, the LAN installer, files and multi-select avatars.
sidebar_icon: package
---

`GameRecipes` is the bridge between a LAN's file host and a player's PC. A **recipe** describes how
to install one game: a `recipe.yaml` with the facts and a `SKILL.md` that an agent can read. The
installer (the pi agent) plans the install, downloads the required content from the LAN file host
and optionally adds mods and avatars.

<div class="live">LIVE 17:42 · UT2004 · base install · 3 avatars selected</div>

## Who uses it

- **Teilnehmer** browse recipes, pick avatars, and run the install through the pi agent.
- **Orga** publishes recipes, uploads ISOs/files, and keeps the SMB import in sync.
- **Agents** read the recipe skill and call `recipe_install_plan` before downloading anything.

## Key screens

- `/games` — the participant **[games library](/docs/system/games-library)**: published recipes,
  LAN dot, "Nur LAN-Spiele", install/launch, live servers and running tournaments.
- `/games/{slug}` — one game: install deep link, launch, server list, tournaments, "Runde ankündigen".
- `/games/share` — **Spiel teilen**: upload or pick an archive, describe the game, follow your own
  shares. It creates a draft portable recipe with the archive pinned.
- `/app` — **App herunterladen**: the one download page, the desktop (voice) app and the launcher as
  a list of downloads per OS with checksums, install steps and the pairing address; `/launcher` and
  `/talk/client` redirect here, and their file routes stay. The live servers are not listed here any
  more — they live on `/servers`.
- `/admin/recipes` — **Spielrezepte**: the review queue, publish, unpublish, edit metadata, the
  lineup switch and the recipe's pull request.

## The review queue

The orga side is one Filament table (`/admin/recipes`) with a navigation badge: the number of
contributed drafts still waiting for a reviewer, in warning colour, with the tooltip **„Geteilte
Spiele, die auf Prüfung warten“**. No badge means the queue is empty.

| Column | What it shows |
|---|---|
| **Titel**, **Slug**, **Version** | The recipe's identity; slug and version are toggleable |
| **Status** | `Entwurf`, `Veröffentlicht` or `Archiviert` |
| **Beitrag von** | The contributor of a shared game; `—` for a curated recipe |
| **Datei** | The state of the pinned LAN file: **„Datei wartet auf Freigabe“**, **„Datei abgelehnt“**, **„Datei fehlt“**; `—` when the recipe pins no file |
| **In der Spieleliste zeigen** | The lineup flag as an icon: a green check or a grey minus |
| **Pull Request** | The catalogue state (**Pull Request offen**, **Im Katalog**, **Pull Request geschlossen**, **Nicht gesendet**, **Fehlgeschlagen**). Only a state that exists in the repository links to it; a blocked or failed one stays local. Under **Nicht gesendet** and **Fehlgeschlagen** the first line of the stored reason follows the badge |
| **Zuletzt geprüft**, **Dateien**, **Aktualisiert** | Review stamp, file count, last update |

Filters: **Status**, the **Geteilte Spiele** toggle (rows with a contributor), the **Spieleliste**
ternary (**Alle**, **In der Spieleliste**, **Nicht in der Spieleliste**) and **Pull Request**. The
list is sorted by **Aktualisiert**, newest first.

| Dark | Light |
|---|---|
| ![Game recipes admin table in dark theme with the "Geteilte Spiele" filter active: status, the file state, the pull request state with its stored reason, the file count and the last update](/screens/admin-recipes-dark.png) | ![Game recipes admin table in light theme with the same filtered lists, file states and pull request reasons](/screens/admin-recipes-light.png) |
| `RECIPES · DARK` — shared games, file state, pull request and its reason | `RECIPES · LIGHT` — same list |

The edit page carries the recipe itself: description, homepage, license, the `SKILL.md` and every
required file as a typed row with a role and a filename pattern. The keys the form has no field for —
a requirement's operating system, the manual flag, notes, author, licence and tags — are shown
read-only under **Angaben zur Datei** („Nur für Windows“ — the platform's name, not its slug —,
„Von Hand zu besorgen …“, „Hinweis: …“, „Autor: …“, „Lizenz: …“, „Schlagwörter: …“), so a reviewer
sees a Windows-only or hand-fetched file before releasing it. The header actions are
**Veröffentlichen**, **Dateien freigeben und veröffentlichen**, **Pull Request öffnen / aktualisieren**
and **Archivieren**.

- **Dateien freigeben und veröffentlichen** appears while the recipe is not published and one of its
  pinned files waits for approval or was rejected. It approves every pending pinned file and
  publishes the recipe in one transaction, so a shared game never stays half-released. A rejected pin
  disables the action (**„Eine verknüpfte Datei wurde abgelehnt. Verknüpfe zuerst eine andere Datei —
  abgelehnte Dateien können nicht freigegeben werden.“**); the orga points the requirement at a new
  upload first.
- **Veröffentlichen** publishes without touching files. A contributed recipe joins the player lineup
  (`in_lineup = true`); a curated recipe keeps whatever the orga chose with the toggle.
- **Archivieren** hides a published recipe and keeps its release stamp.

The **In der Spieleliste zeigen** toggle is the lineup switch (helper text: „Die Spieler sehen die
eingestellte Spieleliste plus jedes Rezept mit diesem Schalter. Ist keine Spieleliste eingestellt,
erscheint ohnehin jedes veröffentlichte Rezept.“).

| Dark | Light |
|---|---|
| ![Game recipe edit form in dark theme with the SKILL.md text, the required DVD requirement with its filename patterns and the step editor](/screens/admin-recipe-edit-dark.png) | ![Game recipe edit form in light theme with the same SKILL.md, requirement and step fields](/screens/admin-recipe-edit-light.png) |
| `RECIPE EDIT · DARK` — SKILL.md, the required DVD with its patterns, the steps | `RECIPE EDIT · LIGHT` — same fields |

### Start und Launcher

A read-only section **„Start und Launcher“** sits on the edit page (help text: „Was der Launcher und
der Start auf den PCs der Spieler ausführen. Nur zur Ansicht — geändert wird es in der Rezeptdatei.“).
It answers what a reviewer used to release unseen:

- **„Launcher bietet es an“** or **„Launcher bietet es nicht an“**, with the rule that decided it
  under „Grund: …“: „der Schalter der Orga steht auf „immer anbieten““, „der Schalter der Orga steht
  auf „nie anbieten““, „UT2004-Pilot mit eigener Engine im Launcher“, „von Hand geprüft (generische
  Engine)“, „Browserspiel mit Spielen-Link“, „portables Archiv: Basis-Archiv, Datei-Prüfung und
  Startziel sind da“ or „keine Regel trifft zu — nicht Pilot, nicht geprüft, kein Spielen-Link,
  nicht portabel“.
- **„Portable (metadata.portable): ja/nein“**; when it is set, either „Form für den generischen
  Installer: vollständig (Pflicht-Basisarchiv, Datei-Prüfung, Startziel)“ or a warning naming what is
  missing (a required base archive, a file/folder check in a non-optional step, a launch target).
- The **Spielen-Link** as plain text — deliberately not a link, so an unreviewed URL is not one click
  away.
- **„Startbefehle (laufen auf dem PC der Spieler)“**: every launch target with its label, OS and shell
  (or „ohne Shell“), every command verbatim in mono, its notes, and commands derived from
  `metadata.launch` / `metadata.launch_vr` marked „abgeleitet aus …“. No target reads „Keine
  Startbefehle hinterlegt.“
- The **three-state launcher override** **„Launcher-Freigabe (metadata.launcher_supported)“**:
  **Automatisch entscheiden**, **Immer anbieten**, **Nie anbieten**. Only `metadata.launcher_supported`
  changes; every other metadata key keeps its value.

| Dark | Light |
|---|---|
| ![Contributed recipe edit page: the read-only requirement details ("Nur für windows", note, tags) above the read-only "Start und Launcher" section with the launcher verdict, the portable shape, the launch target and the override](/screens/admin-recipe-contributed-dark.png) | ![The same contributed recipe page in light mode](/screens/admin-recipe-contributed-light.png) |
| `RECIPE CONTRIBUTED · DARK` — "Angaben zur Datei", "Start und Launcher", override | `RECIPE CONTRIBUTED · LIGHT` — same sections |

Each step in the form also lists its `verify` checks read-only, one line per check
(`file_exists: <Pfad>`, `dir_exists: <Pfad>`, `command: <Befehl>`) and no heading of its own; an
OS-specific check ends in „· nur windows“ (or the OS it names). The checks have no editor; they come
from the recipe.

Both publish confirmations list what lands on the players' PCs: **„Das wird veröffentlicht“**,
**„Schrittbefehle: :steps · Startbefehle: :launch“**, **„Diese Befehle laufen auf den PCs der
Spieler.“** or **„Keine Startbefehle hinterlegt.“** — with the commands themselves, so the orga sees
the shell lines before releasing them. When the catalogue checks would refuse the pull request at
that moment, both confirmations also carry the warning **„Der Pull Request wird nicht gesendet“** plus
the number of open points („ein offener Punkt – der Grund steht im Abschnitt „Katalog (Pull
Request)““); publishing itself is never blocked by it.

| Dark | Light |
|---|---|
| ![Publish confirmation "Dateien freigeben und veröffentlichen": the pull-request warning, the archive that is approved, the "Das wird veröffentlicht" counts and the launch command](/screens/admin-recipe-publish-confirm-dark.png) | ![The same publish confirmation in light mode](/screens/admin-recipe-publish-confirm-light.png) |
| `PUBLISH · DARK` — PR warning, files, counts, commands | `PUBLISH · LIGHT` — same confirmation |

### Katalog (Pull Request)

The read-only section **„Katalog (Pull Request)“** holds the state, the link, the refusal reason of
the last attempt and the last sync. With pull requests configured it adds **„Textprüfung für den
Katalog“** — the same checks the proposal runs, asked without a repository call and without writing
anything:

- a draft lists what the catalogue wants from a draft (**„Als Entwurf fehlt noch:“**) and what would
  still be missing once it is published (**„Nach dem Veröffentlichen fehlt noch:“**, with the help line
  that depends on `RECIPES_PR_TRIGGER`);
- a published or archived recipe lists only **„Was den Pull Request gerade blockiert:“**;
- an empty list answers **„Der Text ist bereit für den Katalog – ein Pull Request hätte nichts zu
  beanstanden.“**

The reasons themselves are the checks' English sentences, shown verbatim.

| Dark | Light |
|---|---|
| ![The "Katalog (Pull Request)" section on a draft: "Textprüfung für den Katalog" with the English findings under "Als Entwurf fehlt noch:" and "Nach dem Veröffentlichen fehlt noch:"](/screens/admin-recipe-catalog-dark.png) | ![The same catalogue section in light mode](/screens/admin-recipe-catalog-light.png) |
| `KATALOG · DARK` — draft and published findings, verbatim | `KATALOG · LIGHT` — same list |

## Guards and rules

- The **base game is the required content**: a published recipe carries one required `base` or `iso`
  requirement (the catalogue validator warns without one); patches, mods, tools and avatars are
  optional.
- `LauncherSupport` is the one place that decides `launcher_supported`: the UT2004 pilot, the
  hand-verified NFSU2 recipe, a browser recipe with `play_url`, or a portable recipe (required
  `base` archive, a file/dir check in a non-optional step, a launch target). The panel's
  **Launcher-Freigabe (metadata.launcher_supported)** field offers **Automatisch entscheiden**,
  **Immer anbieten** and **Nie anbieten**; the read-only **Start und Launcher** section explains
  which rule applied. The launcher unpacks, checks and starts; it never runs step commands.
- The **lineup** is the configured `LAUNCHER_LINEUP` plus every recipe with
  `game_recipes.in_lineup = true`; an empty `LAUNCHER_LINEUP` means no curation. The lineup drives
  `/games`, the launcher, the server browser and the `recipes_list`/`GET /api/v1/recipes` catalogue.
  A recipe outside it stays in the database and is still readable by slug (`recipe_get`,
  `GET /api/v1/recipes/{slug}`).
- An install plan links the LAN copies through a bearer-authenticated download URL; only when a
  required file has no LAN copy does the plan fall back to the requirement's external URL
  (`status: external`, or `manual` for a release page), and the agent needs `--allow-external` before
  it fetches one. That preference is hard-wired; `RECIPES_PREFER_LAN` is configured but read by no
  code path today.
- `plan` is pure: it resolves files and sizes but downloads nothing. The agent confirms before the
  transfer. A plan carries the recipe's `version` and a `generated_at` stamp; each download carries
  the `sha256` the launcher verifies before it reuses a file.
- A recipe never names this installation's server: step commands, step checks and launch targets may
  use `{{server_host}}` and `{{server_port}}`, which the server fills in before any client sees the
  text — in the install plan and in the recipe detail (`recipe_get`, `GET /api/v1/recipes/{slug}`,
  the native client's read-only Spiele tab, which lists the published lineup and fetches
  `…/recipes/{slug}` and `…/recipes/{slug}/plan` under `/api/voice-client/v1`). The value comes from
  a server whose game carries exactly the recipe's slug (no
  alias or name match: the browser twin of a native game never qualifies), that is Ready or
  NotResponding and that the viewer may see; a server that answers ranks first, then a permanent one,
  then the lowest id. The host keeps letters, digits, dot and hyphen (at most 253 characters, never
  trimmed), the port must be 1–65535. Without such a server both are the empty string, and the step
  leaves the game's configuration alone.
- Avatars are **multi-select** content: zero or more, each with a size and a target path.
- Deleting a recipe removes its own requirement rows; the uploaded files stay on the file host, so
  another recipe that pins or matches them keeps working.
- An install is idempotent: re-running it verifies hashes and skips what is already there.

## Shared games and the catalogue

A participant packs a ready-to-run game folder as `.zip` or `.tar.bz2`, uploads it to the file host
and describes it on `/games/share`. `ShareGame` refuses a foreign or rejected file, a file the caller
may not download, a name that is not an archive, a name the installing client would store under
another name (a backtick, `*`, `?`, a control character, `:`, `"`, `<`, `>`, `|`, a leading dot, two
dots in a row) or that gives too weak a pin glob, a taken title and the slug `share`;
`ContributeRecipe` refuses the contribution beyond `RECIPES_MAX_DRAFTS_PER_USER` drafts waiting for
review (20 by default, orga exempt).
`PortableRecipeBuilder` writes the payload (`metadata.portable: true`, one required `base`
requirement, file checks, launch targets) and `ContributeRecipe` stores it as the contributor's
draft. The orga's part is one action plus one switch: **Dateien freigeben und veröffentlichen**
approves the pinned archive and publishes the recipe, and publishing a contributed recipe sets
**In der Spieleliste zeigen** and rewrites the generated sentences that call the text a draft. Read
[Share a game](/docs/guides/share-a-game) for the participant view.

A contribution made through MCP or REST answers with a `catalog` object next to the recipe:
`pull_requests_enabled` (whether this installation proposes at all), `problems` (what would block a
pull request for the stored status) and `problems_when_published`. Both paths accept a full
`skill_markdown`; without one the skill is rendered from the fields. A second call for the same slug
**replaces** the stored draft, so the complete recipe has to be sent — a field left out is cleared,
not kept. A pin the orga set on a file row survives a contributor's edit only while the requirement
still names the same file (same patterns and digest); a renamed or duplicated requirement starts
without a pin and never inherits one.

## Pull requests into the git catalogue

A recipe is a database row; the git catalogue is what the next deployment ships. `GameRecipes`
bridges the two through the `RecipeRepository` contract: `GitHubRecipeRepository` over the GitHub
REST API, `NullRecipeRepository` when the module runs without a link, and a fake in tests. Off by
default (`RECIPES_PR_ENABLED`), and never used at runtime without `RECIPES_PR_REPOSITORY` and
`RECIPES_PR_TOKEN`.

| Piece | Behaviour |
|---|---|
| Trigger | `RECIPES_PR_TRIGGER=publish` (the default) opens or updates the pull request when the orga publishes, so the repository only sees reviewed text; `contribute` is the opt-in that proposes as soon as a participant shares or edits a draft. With the link configured, publishing a contributed or panel recipe always opens or updates it |
| What may be proposed | A contributed or panel recipe, or a synced one that already has a pull request. An **archived** recipe is never proposed again (`isProposable()`); the panel action **Pull Request öffnen / aktualisieren** is offered only for a recipe the pipeline would take |
| Branch and pull request | One per recipe: `RECIPES_PR_BRANCH_PREFIX` + slug (default `recipes/<slug>`). A later proposal moves the branch and updates the same pull request — never a second one |
| Files | `<RECIPES_PR_PATH>/<slug>/recipe.yaml`, `<slug>/SKILL.md` and the recipe's row in `_catalog.md`. Only text; game files stay on the LAN file host, and the body names requirement labels, never file ids or URLs |
| Readiness check | Before the first request, the exported text must satisfy the repository's own gate (`CatalogReadiness`): recipe schema, `SKILL.md` front matter and 60–150 lines, 3–6 steps for a draft plus the LAN-verification sentence, one command per entry, OS-matching shells, base-name globs. A recipe that would fail `validate.mjs` is refused here instead of turning the pull request red |
| Safety check | Then `PublicSafeText` checks the YAML, the skill, both commit titles and the body (same rules as `scripts/ops/check-public-safe.sh`: private, public and link-local IPv4 addresses — also split or as a numeric URL host — IPv6 addresses, e-mail addresses, private keys, SSH keys, bearer/cloud/chat tokens). A refused recipe is **Nicht gesendet** and is never sent. The title is cleaned plain text in the commit and PR title; participant prose in the body is escaped Markdown |
| Limits | Automatic proposals are capped per contributor per hour (`RECIPES_PR_PER_USER_HOURLY`, 6) and by this installation's open pull requests (`RECIPES_PR_MAX_OPEN`, 30; updating a recipe that already has one does not count). A skipped attempt writes no recipe state; the orga's command and the panel action are not limited |
| States | **Pull Request offen** → **Im Katalog** / **Pull Request geschlossen** from the repository; **Nicht gesendet** and **Fehlgeschlagen** are local. A recipe that already has an open pull request keeps it when a new attempt is blocked or fails (the reason is stored); the hourly `lanomat:recipes:pr-status` keeps the open ones honest |
| Panel | **Pull Request öffnen / aktualisieren** queues the proposal and says „Pull Request wird vorbereitet“; the **Katalog (Pull Request)** section on the edit page shows the state, the link, the refusal reason and the last sync |
| Commands | `lanomat:recipes:propose <slug>` in the foreground, `lanomat:recipes:pr-status [--json]` for the sweep, `lanomat:recipes:export <path> --format=repository` to write the repository layout by hand |

Check the link before the LAN: Preflight's `recipe_repository` check is green when the token may read
and push, and warns — never goes down — when it cannot.

The catalogue itself is synced with `lanomat:recipes:sync` from the bundled directory, an SMB share
or a git source. A resync keeps a moderator's status and the admin's file pins. `--only=<slug>`
narrows what is **updated**, never what counts as present, so `--only=<slug> --prune` never deletes
the other recipes; `--prune` only removes rows of that source whose recipe directory is gone, and an
empty or unreadable source prunes nothing. `node resources/recipes/schema/validate.mjs [dir]`
checks the schema, the `SKILL.md` contract, uniqueness, the draft rules and the `_catalog.md` rows;
`.github/workflows/recipes.yml` runs the same check on recipe pull requests.

## Docs for agents

`recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`, `recipe_contribute`;
the skill itself is the resource `lanomat://recipes/{slug}/skill`, and
`lanomat://skills/contribute-recipe` is the how-to for a catalogue-ready contribution. The lists
also appear as REST endpoints under `/api/v1/recipes`.

<div class="callout-live">
  <strong>Read the skill first.</strong> The YAML is metadata; the SKILL.md carries the install
  steps, the pitfalls and the verify step. Agents should read both before planning.
</div>
