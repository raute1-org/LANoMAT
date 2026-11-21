# Recipe pull requests: a shared game into the git catalogue

A participant shares a game on `/games/share` (or through `recipe_contribute` / `POST /api/v1/recipes`).
LANoMAT stores it as a draft recipe. With this feature switched on, the recipe also travels into the
repository's own catalogue as a branch and a **pull request**, so the next deployment ships it for
everyone. This runbook is the operator's path from "off" to "the first merge", and the place to look
when nothing arrives on GitHub.

```text
share / contribute ──► draft game_recipes row ──► queued job ──► export recipe.yaml + SKILL.md
                                   │                              + the _catalog.md row
                                   │                                    │
                                   └── public-safety check ◄────────────┘
                                            │ ok                    │ findings
                                            ▼                       ▼
                              branch recipes/<slug> + PR      pr_state=blocked
```

Only recipe **text** leaves the instance. Game files never do: they stay on the LAN file host, and the
pull request names requirement labels, never file-host URLs or file ids.

The repository is public: every owner, name and token below is a placeholder.

| Placeholder         | Meaning                                                             |
| ------------------- | ------------------------------------------------------------------- |
| `<GITHUB_OWNER>`    | the GitHub user or organisation that owns the recipe repository      |
| `<REPOSITORY>`      | the repository name, e.g. `LANoMAT`                                  |
| `<GITHUB_TOKEN>`    | the fine-grained token (`github_pat_…`), never committed             |
| `<LAN_DOMAIN>`      | the name participants open the app with                             |

## 1. What it does and what it never does

It does:

- export one recipe into the repository's file format: `<path>/<slug>/recipe.yaml`,
  `<path>/<slug>/SKILL.md` and a row in `<path>/_catalog.md`;
- put those three files on one branch, `recipes/<slug>` (`RECIPES_PR_BRANCH_PREFIX` + slug), and make
  sure exactly one **open** pull request carries that branch — creating it, or updating its title and
  body and force-moving the branch;
- record the result on the recipe: `pr_number`, `pr_url`, `pr_branch`, `pr_state`, `pr_error`,
  `pr_synced_at`. Only the proposal job and the status sync write these columns (`forceFill`); no form
  does;
- ask the repository hourly how the open pull requests are doing.

It never:

- uploads, downloads, packs or hashes a game file. A game shared on `/games/share` is
  `metadata.portable: true`; the launcher installs it by unpacking the base archive. The archive stays on
  the LAN file host;
- merges, approves, labels or comments on a pull request. A human merges in GitHub;
- publishes a recipe. Publishing stays an orga action in the panel; the pull request only carries the
  recipe text that is already in the database;
- proposes anything that is not a contributed or panel-authored recipe (or one a sync adopted that
  already carries a pull request) — see `ProposeRecipe::isProposable()`;
- writes to a recipe that a sync adopted from the bundled/SMB/git catalogue unless that recipe already
  has a `pr_number`.

## 2. Prerequisites

- `MODULE_GAME_RECIPES=true`. The module requires `Files` and `Mcp` (`Module::requires()`), so both must
  be on as well; `lanomat:modules` fails the boot otherwise.
- The **queue worker** (`queue` service, `php artisan queue:work`) is running. The proposal is queued on
  purpose, so a participant's share never waits for GitHub. Without a worker the recipe keeps
  `pr_state = null`, and nothing tells you anything.
- A repository that already has the catalogue layout you configured: `<RECIPES_PR_PATH>/_catalog.md`
  with a `## Published` / `## Draft` / `## Archived` heading above a table. A missing catalogue file is
  tolerated (the proposal then carries only the two recipe files); a catalogue without the recipe's
  status heading is refused — see §7.
- A GitHub token with write access to that repository (§3).

## 3. The token

Create a **fine-grained** personal access token, not a classic one: a classic `repo` token can write to
every repository the user can see.

In the GitHub web UI: avatar menu → **Settings** → **Developer settings** → **Personal access tokens** →
**Fine-grained tokens** → **Generate new token**.

- **Repository access:** *Only select repositories* → exactly the recipe repository.
- **Repository permissions:** *Contents*: **Read and write**; *Pull requests*: **Read and write**.
  Everything else stays *No access*. Metadata is added automatically as read-only.
- **Expiration:** the event, plus a buffer. An expired token makes the Preflight check warn
  (`recipe_repository_unauthorized`) and every new proposal `pr_state = failed`.

Put it into the server's `.env` (never into git, never into a commit message):

```dotenv
RECIPES_PR_ENABLED=true
RECIPES_PR_REPOSITORY=<GITHUB_OWNER>/<REPOSITORY>
RECIPES_PR_TOKEN=<GITHUB_TOKEN>
```

Then recreate the containers (`docker compose --profile prod up -d app queue scheduler`). A plain
`restart` keeps the old environment: `env_file` is only read when a container is created.

The token is used by the app (proposal job, command) and by the scheduler (status sync); both read it
from the same binding. `GitHubRecipeRepository` sends it as `Authorization: Bearer`, never in a URL,
and replaces it with `***` in every error line it stores in `pr_error`.

## 4. Configuration

`config/recipes.php` → `pull_requests`. Every value comes from the environment.

| Variable                   | Default                 | Meaning                                                                                         |
| -------------------------- | ----------------------- | ----------------------------------------------------------------------------------------------- |
| `RECIPES_PR_ENABLED`       | `false`                 | Master switch. Off: the contract is bound to `NullRecipeRepository`, nothing is queued or sent   |
| `RECIPES_PR_REPOSITORY`    | empty                   | `owner/name` of the recipe repository. Empty or malformed counts as "not configured"            |
| `RECIPES_PR_TOKEN`         | empty                   | the fine-grained token (§3). Empty counts as "not configured"                                    |
| `RECIPES_PR_BASE`          | `main`                  | base branch the proposal is layered on and the pull request targets                             |
| `RECIPES_PR_BRANCH_PREFIX` | `recipes/`              | prefix of the proposal branch; the branch is `<prefix><slug>` (an empty value falls back to `recipes/`) |
| `RECIPES_PR_PATH`          | `resources/recipes`     | repository directory that holds `<slug>/recipe.yaml`, `<slug>/SKILL.md` and `_catalog.md`       |
| `RECIPES_PR_TRIGGER`       | `contribute`            | `contribute`: open the pull request when a participant shares. `publish`: only after the orga published |
| `RECIPES_PR_ATTRIBUTION`   | `false`                 | append `Shared by <display name> at the LAN.` to the pull request body                          |
| `RECIPES_PR_API_URL`       | `https://api.github.com` | REST base URL; only change it for a GitHub Enterprise host                                     |

`RECIPES_PR_ENABLED=false`, an empty repository or an empty token all bind `NullRecipeRepository`:
`isConfigured()` is false, the listeners return immediately, the hourly sync exits quietly and the
Preflight check is `skipped`. With `enabled=true` but repository or token missing, proposals are also
skipped — the Preflight check reports the gap (`recipe_repository_incomplete`).

## 5. When a pull request is opened

Two Laravel events drive it. Both listeners only decide *whether to queue*; the queued
`ProposeRecipePullRequestJob` does all the talking.

| Trigger                          | Listener                    | Fires when                                                                 |
| -------------------------------- | --------------------------- | -------------------------------------------------------------------------- |
| `RecipeContributed`              | `ProposeContributedRecipe`  | `trigger = contribute`, repository configured, and the recipe is a contribution (`contributor_user_id` set) |
| `RecipePublished`                | `ProposePublishedRecipe`    | repository configured and the recipe is proposable (contribution, panel, or already has a `pr_number`) — in **both** trigger modes |

`RecipeContributed` is dispatched by `ContributeRecipe` after the write commits, i.e. from the share page,
the MCP tool `recipe_contribute` and `POST /api/v1/recipes`. `RecipePublished` is dispatched by
`PublishRecipe`, i.e. by the panel's "Veröffentlichen" action, by "Dateien freigeben und veröffentlichen",
and by a form save that flips the status to `published`.

So:

- `trigger = contribute`: the pull request appears as soon as somebody shares (one queued job per
  contribution). Publishing the same recipe proposes again, which updates the branch and the existing
  open pull request.
- `trigger = publish`: the contribution creates the draft only; nothing leaves LANoMAT until the orga
  releases it. Publishing then opens the pull request.

The job is unique per recipe for 60 s, tries three times with a 30 s and a 120 s pause, and has a 60 s
timeout. When the last try fails and no pull request exists yet, the recipe gets `pr_state = failed`
with the reason in `pr_error`; an existing pull request is never overwritten by a failure.

The action itself skips a recipe that is not proposable (`ProposeRecipe::isProposable()`: source
`contribution` or `panel`, or `pr_number` set). If the exported files are byte-identical to what the base
branch already has, nothing is committed and no state changes.

## 6. What is in the pull request

| Part        | Value                                                                                                                  |
| ----------- | ---------------------------------------------------------------------------------------------------------------------- |
| Branch      | `recipes/<slug>` by default (`RECIPES_PR_BRANCH_PREFIX` + slug). Force-moved when a new commit is created; an unchanged proposal commits nothing |
| Files       | `<path>/<slug>/recipe.yaml`, `<path>/<slug>/SKILL.md`, `<path>/_catalog.md` (row added or replaced under the status heading, slug order) |
| Commit/title| `feat(recipes): add <title>` when the base branch has no `<path>/<slug>/recipe.yaml` yet, otherwise `feat(recipes): update <title>` |
| Body        | heading, `Slug`, `Platforms`, `Status at the LAN`, a launcher line, the summary, `### Required files` (labels only), a `### Review` checklist, optionally the attribution line, and a closing sentence |

The YAML is `lanomat.recipe/v1` and validates against `resources/recipes/schema/recipe.schema.json`;
`RecipeParser` reads it back into the same row (`RecipeYamlExporter`).

Deliberately absent from the pull request: game files, file-host URLs, `shared_file_id`, the contributor
unless `RECIPES_PR_ATTRIBUTION=true`, `in_lineup`, `contributed_via`, and every `pr_*` column. A draft
whose pinned file is still pending approval can be proposed; the file state stays in LANoMAT.

Attribution is **off** by default because the repository may be public and a display name is personal
data. When it is on, the last paragraph is `Shared by <display name> at the LAN.` — nothing else from the
person.

## 7. The public-safety check

`GameRecipes\Support\PublicSafeText` inspects the exported YAML, the `SKILL.md`, both possible pull
request titles and the body **before the first request leaves LANoMAT**. It uses the same address
classes as `scripts/ops/check-public-safe.sh` and the same redaction (two leading characters plus the
length, never the value).

It refuses:

- a private IPv4 address (10/8, 172.16/12, 192.168/16, 100.64/10), a public IPv4 address, or an IPv6
  literal;
- a URL whose host is a bare dotted quad;
- an e-mail address, except `*@example.*`, `*@lanomat.local` and `*@users.noreply.github.com`;
- a private key block, an SSH public key, or a token with a known prefix (`lmcp_`, `lvc_`, `ghp_`,
  `github_pat_`, `gho_`);
- a documentation-range address (`203.0.113.x`, `198.51.100.x`, `192.0.2.x`) is accepted in prose but
  refused as a URL host. A `version:` value and a dotted quad inside a file name are not addresses.

On a finding the recipe gets `pr_state = blocked` and the reason in `pr_error`, for example:

```text
recipe.yaml: line 12: a private IPv4 address 10[REDACTED len=10].
the pull request body: line 4: an e-mail address jo[REDACTED len=18].
```

Where the admin sees it:

- the recipe list (`/admin/recipes`) has a **Pull Request** badge column and a matching filter; the
  `pr_state` badge is a warning colour for `blocked`, red for `failed`, and links to the pull request
  only for states that exist in the repository;
- the recipe's edit page has the read-only section **Katalog (Pull Request)** with the state badge, the
  pull request link, the `pr_error` text in danger colour, and "Zuletzt abgeglichen: …" (or "Noch nicht
  mit dem Repository abgeglichen");
- the share page shows the same state as the third step of "Deine geteilten Spiele", under **Katalog**.

To fix and re-propose: edit the recipe so the offending text is gone (a title, summary, step command or
requirement label — the finding names the file and line), save, then press **Pull Request öffnen /
aktualisieren** on the edit page (queues the job) or run `php artisan lanomat:recipes:propose <slug>` in
the foreground to see the reason immediately. The next proposal overwrites `pr_error` and the state.

## 8. States and the hourly sync

`GameRecipes\Enums\PullRequestState`, with its labels from `lang/de/recipe_pull_requests.php`:

| State     | German label                 | Meaning                                                                                     |
| --------- | ---------------------------- | ------------------------------------------------------------------------------------------- |
| `open`    | `Pull Request offen`         | The branch and the pull request exist. The sync keeps asking                               |
| `merged`  | `Im Katalog`                 | Merged into the base branch. The next deployment ships the recipe (§10)                     |
| `closed`  | `Pull Request geschlossen`   | Closed without a merge. The sync stops asking; a new proposal targets the same branch again |
| `blocked` | `Nicht gesendet`             | The public-safety check or the catalogue structure refused it; nothing was sent             |
| `failed`  | `Fehlgeschlagen`             | The repository (or the job) could not take it; `pr_error` has the reason                     |

`lanomat:recipes:pr-status` runs hourly (`routes/console.php`, while `GameRecipes` is on) and only reads
rows with `pr_state = open` and a `pr_number`. It stores the repository's answer and `pr_synced_at`. One
pull request that cannot be read is reported and skipped; the run continues and exits `0`. While the
repository is not configured the command does nothing.

`blocked` and `failed` are never synced — they have no pull request (or none that left LANoMAT). Only a
new proposal changes them.

## 9. Commands

### `lanomat:recipes:propose <slug>`

Runs one proposal in the foreground — the orga's "propose now", and the way to see why a share was
refused.

```console
$ php artisan lanomat:recipes:propose my-shared-game
Pull Request offen (#42): https://github.com/<GITHUB_OWNER>/<REPOSITORY>/pull/42
```

```console
$ php artisan lanomat:recipes:propose my-shared-game
Nicht gesendet: recipe.yaml: line 12: a private IPv4 address 10[REDACTED len=10].
```

```console
$ php artisan lanomat:recipes:propose my-shared-game
Keine Änderung am Katalog – für "my-shared-game" wurde kein Pull Request angelegt.
```

Exit codes: `0` success, including "nothing to propose"; `1` for an unknown slug (`Kein Rezept mit dem
Slug "…" gefunden.`), a repository that is not configured (`Pull Requests sind nicht aktiviert
(RECIPES_PR_ENABLED, RECIPES_PR_REPOSITORY, RECIPES_PR_TOKEN).`), a blocked/failed proposal, or a
repository exception (`Der Pull Request konnte nicht angelegt werden: …`).

### `lanomat:recipes:pr-status [--json]`

```console
$ php artisan lanomat:recipes:pr-status
3 offene Pull Requests geprüft.
Nicht abgefragt: broken-game (GitHub rate limit (HTTP 403): API rate limit exceeded)
```

```json
{
    "checked": 3,
    "updated": [
        { "slug": "shared-a", "number": 41, "state": "merged" },
        { "slug": "shared-b", "number": 42, "state": "open" }
    ],
    "failed": [
        { "slug": "broken-game", "number": 43, "reason": "GitHub rate limit (HTTP 403): ..." }
    ]
}
```

Exit code `0` in every case; a per-pull-request failure is in `failed[]` (or a warning line), not in the
exit code.

## 10. Preflight

`Preflight\Checks\RecipeRepositoryCheck` (key `recipe_repository`, panel label
**Rezept-Katalog (Pull Requests)**) is registered while `GameRecipes` is on. It never reports `down`: a
missing catalogue link must not turn the event-day check red.

It calls `GET <RECIPES_PR_API_URL>/repos/<RECIPES_PR_REPOSITORY>` with the token and reads
`permissions.push`.

| Result    | Message (German, `lang/de/preflight.php`)                                                                       |
| --------- | --------------------------------------------------------------------------------------------------------------- |
| `skipped` | `Nicht konfiguriert.` — `RECIPES_PR_ENABLED` is false                                                            |
| `warn`    | `Pull Requests sind aktiviert, aber Repository oder Token fehlen (RECIPES_PR_REPOSITORY, RECIPES_PR_TOKEN).`     |
| `ok`      | `Das Token darf den Rezept-Katalog lesen und Pull Requests anlegen.` — HTTP 200 with `permissions.push: true`    |
| `warn`    | `Das Token kann den Rezept-Katalog lesen, aber nicht schreiben (Contents und Pull requests: write fehlen).`      |
| `warn`    | `GitHub lehnt das Token ab (HTTP 401). RECIPES_PR_TOKEN prüfen.`                                                 |
| `warn`    | `Das Token darf nicht auf das Repository zugreifen (HTTP 403), oder das Rate-Limit ist erschöpft.`                |
| `warn`    | `Das Repository wurde nicht gefunden (HTTP 404). RECIPES_PR_REPOSITORY als "owner/name" prüfen.`                  |
| `warn`    | `GitHub antwortet auf die Repository-Abfrage mit HTTP :status.`                                                  |
| `warn`    | `Die GitHub-API ist nicht erreichbar. Geteilte Rezepte bleiben als Entwurf liegen.`                              |

A `404` usually means the token is fine but the repository is not in its *Only select repositories*
list: a fine-grained token answers `404`, not `403`, for a repository it cannot see.

## 11. After a merge

Merging the pull request puts `resources/recipes/<slug>/` and the catalogue row into the base branch.

1. **The next deployment ships the recipe.** The image contains `resources/recipes`; the instance
   imports it with its first `lanomat:recipes:sync` (the daily 04:15 run, or by hand).
2. **A running instance adopts it** with `lanomat:recipes:sync` (daily at 04:15, or by hand:
   `php artisan lanomat:recipes:sync resources/recipes --only=<slug> --json`).
   The YAML is the authority: title, summary, description, steps, requirements descriptors, metadata and
   platform list are overwritten from the file. The sync keeps what a file cannot express:
   - the moderation status: a `status` that differs from the YAML is not overwritten (a re-publish stays
     a panel decision);
   - the admin's file pins (`shared_file_id`), keyed by role and label;
   - the row and file-row ids, so plan references and pinned files survive;
   - `in_lineup`, `contributor_user_id` and the `pr_*` columns — the parser never produces them.
3. The recipe's pull request state becomes `merged` at the next hourly sync (`Im Katalog`). The merge in
   GitHub is what makes it true; LANoMAT only reads it.

A merged recipe that the sync has not adopted yet is **not** in the running instance's catalogue: the
pull request says "Im Katalog", the deployment has not happened. That is expected, and the reason the
row links to GitHub instead of pretending the recipe is live.

## 12. Troubleshooting

| Symptom                                                         | Cause and fix                                                                                                                  |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `pr_state` stays `null` after a share                            | Repository not configured (`NullRecipeRepository`), `trigger = publish` and nothing published yet, or no queue worker running   |
| `401` in `pr_error` / Preflight `unauthorized`                   | Token rejected: regenerated, expired or copied with a line break. Replace `RECIPES_PR_TOKEN`, recreate the containers         |
| `403` with `API rate limit exceeded`, `retry-after` or `x-ratelimit-remaining: 0` | Rate limit. The client retries up to 3 attempts (150 ms base, `Retry-After` up to 2 s); the queue job retries twice (30 s, 120 s). With write access the 5000/h budget is ample |
| `403` without those headers                                      | A plain permission error: *Contents* or *Pull requests* is not read and write, or the token is not fine-grained for this repo  |
| `404` on the repository                                         | `RECIPES_PR_REPOSITORY` is not `owner/name`, or the token does not include this repository. Fine-grained tokens hide other repos behind 404 |
| `blocked` with `the catalogue: The catalogue has no draft section.` | The base branch's `_catalog.md` has no heading matching the recipe's status. Add the heading and table (or change `RECIPES_PR_PATH`), then re-propose |
| `blocked` with `the catalogue: The catalogue's published section has no table.` | The heading exists but the table under it is missing or malformed                                     |
| `Keine Änderung am Katalog …` / nothing to propose               | The exported files are byte-identical to the base branch, the recipe is not proposable, or the repository is not configured     |
| A pull request was closed without merging                        | The sync stores `closed` and stops asking. A new proposal moves the branch and opens a **new** pull request, because the repository has no open one for that head. The old one stays closed in GitHub |
| A second pull request appeared for the same recipe               | Two proposals raced before the first pull request existed (the job is unique per recipe for 60 s only). Close the duplicate; the next proposal keeps the one it finds first |
| `Im Katalog`, but `/games/<slug>` does not show the game         | The merge is not deployed or not synced yet (§11), or the lineup hides it — see [`../ops/launcher-lineup.md`](../ops/launcher-lineup.md) |

## 13. Security notes

- **The repository is public.** A pull request is public the moment it is opened, including its branch,
  its commit message and its body. That is why the public-safety check runs first and why attribution is
  off by default.
- **The workflow gets no secrets.** `.github/workflows/recipes.yml` runs on plain `pull_request` (never
  `pull_request_target`) with `permissions: contents: read` and validates `resources/recipes` with
  `node resources/recipes/schema/validate.mjs`. A pull request cannot reach the token, because the token
  lives in LANoMAT and never in the repository.
- **A merged recipe is executable content.** `steps[].commands` run on a player's machine through the
  agent. Review the pull request before merging — the review checklist in the body is a prompt, not a
  gate. Treat a merge as code review.
- **The token can write.** Keep it to one repository, *Contents* and *Pull requests* only, with an
  expiry. Revoke it in GitHub when the event is over; LANoMAT degrades to `failed` proposals without it.
- **A blocked recipe stays in LANoMAT.** Its text is in the database (and in the panel), not in the
  repository; the redacted reason names the class and the line, never the value.

## 14. Checklist

- [ ] `php artisan lanomat:modules` shows `GameRecipes` (with `Files` and `Mcp`).
- [ ] `RECIPES_PR_ENABLED=true`, `RECIPES_PR_REPOSITORY` and `RECIPES_PR_TOKEN` set in `.env`; containers
      recreated (not restarted).
- [ ] `php artisan lanomat:preflight` shows **Rezept-Katalog (Pull Requests)** as `ok`.
- [ ] The base branch has `resources/recipes/_catalog.md` with the status headings the recipes use.
- [ ] `php artisan lanomat:recipes:propose <slug>` on a test recipe opens one pull request; the workflow
      is green.
- [ ] A second proposal for the same slug updates the same pull request (no second one).
- [ ] Close the test pull request → `lanomat:recipes:pr-status` stores `closed`.
- [ ] A blocked test (put an e-mail address in the summary) shows `Nicht gesendet` and a redacted reason
      in the panel, and GitHub saw nothing.
- [ ] Merge a real one: `pr-status` turns it into `Im Katalog`, and the next deploy/sync serves it.
