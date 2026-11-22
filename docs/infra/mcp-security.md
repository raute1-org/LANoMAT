# MCP, agent API and game recipes — security model

**Scope:** the `Mcp` module (`/mcp`, `/api/v1/*`, `/api/mcp/*`, `/mcp/connect`), the
`GameRecipes` module (`recipe_contribute`, recipe sync from SMB/git, install plans, avatars)
and the parts of the agent (`clients/agent`) that consume them.
**Audited:** 2026-11-21 (card 16, agent swarm); re-audited 2026-11-22 for the gap round (plan
variables, occupants, `lanReady`, the catalogue preview, the agent's path rule) and again after the
two reviews and the first real-browser run of the journey (`927776ae`, `9323ad48`, `64ccae49`). Code,
tests and this document are the record; a finding that is only documented here is listed under
*Residual risks*.

Related documents: [`native-voice-client-api.md`](native-voice-client-api.md) (the bearer-token
pattern this API copies), [`file-host.md`](file-host.md) (storage, signed URLs, quota),
[`game-recipes` docs](https://github.com/raute1-org/LANoMAT) in `resources/recipes/README.md`.

---

## 1. Assets and trust boundaries

| Asset | Lives in | Compromise means |
|---|---|---|
| Agent bearer token (`lmcp_…`) | agent config file (0600), `mcp_tokens.token_hash` | full read/write as that user through the agent API |
| Device code + user code | `mcp_device_codes` (hashes only), agent terminal, browser | one token minted for the approving user |
| Recipe content (`steps`, `commands`, `skill_markdown`) | `game_recipes` | **code that runs on a player's machine** |
| Shared files (ISOs, avatars, mods) | file host, `shared_files` | bytes served to the LAN; uploads are the injection surface for file names |
| Session (browser) | cookies | must never authenticate `/api/v1` or `/mcp` |
| Recipe token (`RECIPES_PR_TOKEN`) | server `.env`, app/queue/scheduler environment | write access to the one recipe repository (branches, pull requests) |

Trust boundaries:

```
players' machines ──(bearer token, HTTPS)──> Traefik ──> Laravel (no session on API routes)
   lanomat-agent / pi / Claude Desktop                     │
                                                           ├─> Postgres (hashes, recipes, files)
orga browser ──(Discord login, session+CSRF)──> /mcp/connect└─> file host (signed URLs), SMB share, git
```

Everything a **player** can author is untrusted: file names, LFG text, recipe drafts, avatar
metadata. Everything an **orga** publishes is trusted *by decision*, not by construction — a
contributed recipe becomes code on every player's machine after review.

---

## 2. Threat model (STRIDE-ish, ordered by severity)

Severity is the pre-fix rating. "Fixed" means changed in this card with a regression test.

| # | Threat | Sev | Control | Status |
|---|---|---|---|---|
| T1 | A participant overwrites (and unpublishes) an orga-published recipe by reusing its slug through `recipe_contribute` | **High** | `ContributeRecipe` now authorizes updates through `GameRecipePolicy::update`; a foreign published recipe is a 403, a foreign draft too | fixed |
| T2 | Anonymous rate-limit bypass on the device flow by forging `X-Forwarded-For` (all proxies are trusted → `$request->ip()` is client-chosen), plus a fresh `mcp_device_codes` row per call | **High** | `DeviceFlowController` adds a second throttle keyed on the **rightmost** `X-Forwarded-For` hop (the one Traefik appended, `McpClientAddress`); `StartDeviceAuthorization` prunes expired rows on every start | fixed |
| T3 | Approved file of a **draft** event downloadable by guessing its sequential id | **Medium** | `AccessibleFiles::ensureVisible` on both the download route and `file_download_url` (uploader + orga exempt, others 404); pinned recipe matches are filtered to non-draft events | fixed |
| T4 | Prompt injection through an uploaded **file name** (newlines, fake instruction lines, unbounded length) surfacing in `files_list`, install plans, avatars, recipe detail | **Medium** | `App\Support\SafeText` (re-exported to the MCP module as `App\Modules\Mcp\Support\SafeText`) flattens control characters to one bounded line and reduces names to a base name in every agent-facing projection; both upload paths store a cleaned name (`Files\Support\UploadFileName`) | mitigated |
| T5 | Tool errors echo raw exception text (SQL, absolute paths, caller-authored slugs) into the model's context | **Medium** | `LanomatTool` returns a fixed message for unexpected throwables, a sanitized one-liner for `RecipeException`/domain errors | fixed |
| T6 | A recipe `url` uses a non-HTTP scheme (`file://`, `javascript:`, `data:`), which the agent fetches on the player's machine | **Medium** | `RecipeValidator` accepts only `http`/`https` for `requirement.url`; empty is allowed (LAN-only recipe) | fixed |
| T7 | A poll of a draft event is votable by id through `poll_vote` | **Low–Medium** | `PollVoteTool` resolves the poll through a non-draft event (404 otherwise) | fixed |
| T8 | A `git+` source is parsed by git as an **option** (`--upload-pack=…` runs a command) or as a local read | **Low** (operator-only input) | `RecipeSourceLocator::gitUrl` allowlists `https/ssh/git/scp-style/absolute path`, rejects a leading `-` and whitespace; `git clone … -- <url> <dir>`; `GIT_TERMINAL_PROMPT=0` | fixed |
| T9 | `smbclient` invocation depends on `sprintf` + escaping; a hostile host/share/sub/password | **Low** | `RecipeSourceLocator::smbCommand` builds the command from literals and `escapeshellarg()` for every variable part; unit-tested as an exact contract | fixed |
| T10 | A recipe title like `..` becomes the install target dir and escapes it | **Low** | `BuildInstallPlan::targetDir` keeps only `Str::ascii(title)` characters in `A-Za-z0-9 ._-`, collapses dot runs, trims leading/trailing dots and spaces, falls back to the slug (the folder is substituted into the quoted arguments of step and launch commands) | fixed |
| T11 | Unbounded contribution payloads (megabytes of markdown) → DB/memory growth | **Low** | Request validation caps (description 10 000, skill markdown 65 535, ≤50 requirements/steps, ≤20 aliases/patterns) plus length caps in `RecipeValidator`; one participant holds at most `RECIPES_MAX_DRAFTS_PER_USER` drafts (`ContributeRecipe::ensureDraftBudget()`, orga exempt) | fixed |
| T12 | Token in a URL/query string or a log line | **Low** | The bearer is read from `Authorization` only (proven by test); no query-string fallback; device/token responses are `no-store`; 401s are `no-store` | verified/fixed |
| T13 | DNS rebinding / cross-site call against `/mcp` | **Low** | `laravel/mcp`'s `ValidateOrigin` middleware runs before our auth middleware and only allows `app.url` (+ `mcp.allowed_origins`, localhost) | verified |
| T14 | Session/CSRF confusion: a browser session authenticating the API | **Low** | `/api/v1` and `/mcp` are registered outside the `web` group (no session, no cookies, no CSRF); `/mcp/connect` is inside `web` | verified |
| T15 | Device-code brute force | **Low** | 8 chars from a 31-symbol alphabet, only SHA-256 digests stored, 600 s TTL, approval 10/min per user, polling 60/min per client hop, `slow_down` backoff | verified |
| T16 | IDOR on downloads, plans, avatars, contributions, revocation | **Low** | every path goes through a Policy or an explicit visibility query; foreign ids are 403/404; `DELETE /mcp/devices/{id}` owner-or-admin | verified |
| T17 | Published/draft leakage (recipes, events, files, polls) | **Low** | `GameRecipe::visibleTo/published`, `Event::publiclyVisible`, `VisibleFiles`, matcher filters, tool tests | verified |
| T18 | The share form is a second write path for recipe text and a file pin: a foreign or rejected file id, a traversal in the launcher path, a title that becomes the slug, two sharers taking each other's draft | **Medium** | `ShareGameRequest` (bounds, one-line rule, path rules), `ShareGame` (`SharedFilePolicy::download` on the file — the same ability an install uses, so never a rejected file or one of a non-public event; archive extension; unsafe-name and match-pattern checks; reserved slug `share`; existing-slug collisions), `ContributeRecipe` (one writer per slug via `pg_advisory_xact_lock`, the row read under it, `GameRecipePolicy::update` for an edit), `PortableRecipeBuilder` (relative paths only), `throttle:20,60` on `POST /games/share` | fixed |
| T19 | A `shared_file_id` pin used to reach a file the caller may not see, through `recipe_contribute` or `POST /api/v1/recipes` | **Medium** | `ContributeRecipe::requestedPins()` authorizes every pin with `SharedFilePolicy::download` (approved, or the caller's own pending upload — never rejected, never a non-public event) before it is stored; a pin is dropped from the requirement text and lives on the file row; `RecipeFileMatcher` re-checks visibility when a plan resolves it | fixed |
| T20 | Recipe text escaping into the **public** git repository: a private IP, an e-mail address, a token, a file-host URL or id, the contributor's name | **Medium** | `ProposeRecipe` runs `CatalogReadiness` (the repository validator's own rules) and `PublicSafeText` over the YAML, `SKILL.md`, both titles and the raw body **before** the first request; the body carries requirement labels only (never URLs or ids) and is escaped Markdown; attribution is off unless `RECIPES_PR_ATTRIBUTION=true` | mitigated |
| T21 | A `lanomat://join/<slug>?url=…` link weaponised to make a launcher open an arbitrary URL | **Low–Medium** | `LauncherJoinLink` builds the link server-side, only when `LauncherSupport::supports()`; `?url=` is `rawurlencode`d and accepted only through `JoinLinkTemplates::isAllowedUrl` (`steam`, `ut2004`, `http`, `https`; no control characters, quotes, `<>` or `\`); UT2004's `?address=` is a validated `host:port`; the Vue components render only a value starting with `lanomat://join/` | mitigated |
| T22 | The GitHub token leaking through an error message, a log line or the panel | **Low** | `GitHubRecipeRepository::oneLine()` replaces the token with `***` and caps the message before it becomes a `RecipeRepositoryException` message, a `pr_error` value or a log line; the bearer never appears in a URL | fixed |
| T23 | A shared **title** reaches a shell: the install folder is built from the title and substituted into the quoted arguments of step and launch commands (`Tiny Racers $(calc.exe)`) | **High** | `BuildInstallPlan::targetDir` keeps only `A-Za-z0-9 ._-` after `Str::ascii()` (Windows), `Str::slug()` of that on Linux/macOS; collapses dot runs, trims dots/spaces. Nothing that means anything to PowerShell, cmd or a POSIX shell survives | fixed |
| T24 | The **archive file name** reaches printed commands and Markdown: a backtick ends a code span, a control character a line, `*`/`?` turn the pinned glob into another archive's pattern | **Medium** | `PortableRecipeBuilder::hasUnsafeArchiveName()` refuses a backtick, `*`, `?`, control characters **and** the characters the installing client rewrites (`: " < > |`, a leading dot, a `..`), with a German "rename it" message; commands are single-quoted literals (`powershellLiteral()`, `bashLiteral()`), `SKILL.md` prints the same strings the steps run, prose escapes backticks (`markdownText()`/`markdownCode()`). The share page carries the precise reason per row (`GameShareController::unusableReason`) | fixed |
| T25 | Launch commands invisible at review: `metadata.launch_targets`, `play_url`, `portable` and each step's `verify` had no form field, so an orga released commands nobody saw | **Medium** | The edit page's read-only **Start und Launcher** section (`LauncherReview`) names the `LauncherSupport` rule, the portable flag with its missing conditions, `play_url` as plain text and every launch command verbatim (including commands derived from `metadata.launch`); the step's `verify` entries are listed; both publish confirmations print the launch commands and say they run on players' PCs | fixed |
| T26 | Flooding the repository with branches and pull requests from one participant or device | **Medium** | The automatic path calls `ProposeRecipe::limitReason()`: a rate limiter per contributor (`RECIPES_PR_PER_USER_HOURLY`, default 6/h) and a ceiling of open pull requests (`RECIPES_PR_MAX_OPEN`, default 30, an update of an open one exempt). Over a budget the job is skipped with `recipes.pull_request_skipped` and no state is written. Drafts are bounded separately (`RECIPES_MAX_DRAFTS_PER_USER`). The orga's manual propose is not limited | mitigated |
| T27 | Markdown in the public pull request: an image that phones home, `@team` mentions, `Fixes #12` | **Low** | The title and commit message are cleaned plain text (`PublicSafeText::plain()`); the body is escaped Markdown (`::markdown()`, every metacharacter escaped, one line, capped); the safety gate checks the **raw** text, so escaping cannot hide an address | fixed |
| T28 | Front-matter injection through participant text in the generated `SKILL.md` (a line break ends the front matter, a colon or a leading dash breaks the YAML) | **Medium** | `PortableRecipeBuilder::yamlFrontMatter()` writes it with `Yaml::dump`; title, summary, version, players and aliases must be one line (`ShareGameRequest`, `ContributeRecipe`); `SKILL.md` prose has backticks escaped | fixed |
| T29 | A too-broad `match` pattern claims another participant's archive once the pin is gone (`[a].zip` → `*.zip`) | **Medium** | `PortableRecipeBuilder::hasReliableMatchPattern()` requires at least three literal letters or digits after the extension and turns brackets into `*`; `CatalogReadiness` refuses a glob with a path separator | fixed |
| T30 | An admin-written **server address** reaches a player's shell through `{{server_host}}` / `{{server_port}}` | **Medium** | `PlanServerAddress` takes the address from a `Ready`/`NotResponding` `ServerLink` whose game carries **exactly** the recipe's slug, that the viewer may see anyway (`ServerListProjection`, behind `viewPermanent`); the host must match `[A-Za-z0-9.-]{1,253}` and the port lie in 1..65535, otherwise the value is the empty string (never trimmed or repaired); one `strtr()` pass, so an inserted value is never re-scanned for placeholders | mitigated |
| T31 | The **occupants** line turns into a stalking aid: who is on which server, and who is not | **Low–Medium** | Two gates: the audience is the event's people list (`EventPolicy::viewPeople` — a non-cancelled participant of that event, or orga; an account alone gets `null`), and Presence's visibility rule (`share_location`, blocks either way; the viewer always sees themselves) decides the people and the `more` count. At most eight named accounts; filled only on authenticated server pages; `null` on every bearer API | mitigated |
| T32 | `lanReady` tells a viewer something about uploads they may not see | **Low** | The rule is viewer-independent by design: every required base/disc image needs an approved upload of a non-draft event (pinned or pattern-matched) or an external URL. It never names a file, a pin or an event and never counts an optional requirement; "ready" only means "a fresh install has a source" | mitigated |
| T33 | The contribution answer's **`catalog`** object reveals catalogue or instance state the contributor did not write | **Low** | `ContributionCatalog` calls `ProposeRecipe::preview()`, which clones the recipe, exports its own text and runs `CatalogReadiness` + `PublicSafeText` — no repository call, no state write. The only non-authored value is one boolean, `pull_requests_enabled` (`RecipeRepository::isConfigured()`) | mitigated |
| T34 | A contributor's **`skill_markdown`** travels verbatim into the public repository and to every player's assistant | **Medium** | Stored as sent (newlines, carriage return and tab are the only control characters allowed; `ContributeRecipe` and the MCP tool both enforce it), exported as the SKILL.md body, and scanned by `CatalogReadiness` (60–150 lines, front matter whose `name` is the slug) and `PublicSafeText` (addresses, e-mails, key material, tokens) before anything is sent | mitigated |
| T35 | A plan writes the **wrong server** into a game's configuration: the recipe matcher tolerates an alias or a name resemblance, so without a native server the browser twin's public front end landed in the native game's config | **High** | `PlanServerAddress` is stricter than the install links: the server's game must carry **exactly** the recipe slug (no alias, no name match), the link must be `Ready` or `NotResponding` (never offline/failed/provisioning), and the ranking is answers first, then permanent, then lowest id. The recipe detail (`RecipeController::show`, `RecipeGetTool`, the native `RecipesApiController::show`) fills the same variables, so no client receives a raw placeholder (found by both gap-round reviewers) | fixed |
| T36 | The **occupant line is shown to any account** that can open the server page, not only to the event's people | **Low–Medium** | `PresenceServerOccupants::mayViewPeople()` asks `Gate::…->allows('viewPeople', $event)` once per event and viewer; `EventPolicy::viewPeople` is a non-cancelled participant of that event or orga, otherwise `null`. The global browser hands its viewer in explicitly; the projection no longer reads the session, and the download page `/app` builds no server rows at all | fixed |
| T37 | A contribution edit **inherits a pin** under a renamed or duplicated requirement, so an orga's file choice sticks to a requirement that now asks for something else | **Medium** | `ContributeRecipe` keeps a row's pin only while the requirement still names the same file: the match-pattern set (order/case-insensitive) and the digest are unchanged. A requirement that is new by role and label starts with no pin (only one from the payload counts), and no pin is handed out twice; the duplicate-file-row test covers it | fixed |
| T38 | An archive name the **installing client renames** (`: " < > |`, a leading dot, a `..`) is printed into commands that then cannot find the file | **Medium** | `PortableRecipeBuilder::hasUnsafeArchiveName()` refuses exactly those characters/shapes (plus backtick, `*`, `?`, control characters), so a share cannot pin them; the archive list says the same reason per row instead of calling it "no archive" | fixed |
| T39 | Upload names stored **before the cleaning** break a download (`Content-Disposition` refuses a path separator or control character) | **Low** | `UploadFileName::clean()` now also removes C1 controls, line/paragraph separators, zero-width characters, the byte-order mark and bidirectional formatting; `UploadFileName::forDownload()` cleans an old name again when it is offered and falls back to `file`; the resumable path refuses a name that cleans to nothing with its own message | fixed |

---

## 3. Controls in place

### 3.1 Identity and tokens (`app/Modules/Mcp`)

- **Bearer only.** `AuthenticateMcpToken` reads `Authorization: Bearer`, never the session.
  It resolves the user from `mcp_tokens` and sets a request user resolver; a stale browser
  session, a cookie or `?access_token=` authenticates nothing. It is prepended before
  `ThrottleRequests` and implicit bindings, so 401/404 never reveal object existence.
- **Digests at rest.** Only `sha256(token)` is stored (`McpCodes::hash`), compared with
  `hash_equals`. The plaintext exists once, in the device-flow response and in the terminal of
  `php artisan lanomat:mcp-token`.
- **Sliding expiry + revocation.** Every use (at most every 5 minutes) moves `expires_at` to
  `now + MCP_TOKEN_TTL_DAYS` (90). `revoked_at` wins immediately; the list at `/mcp/connect`
  shows name, creation and last use.
- **No credentials in responses.** `McpToken` hides `token_hash`; token responses carry
  `Cache-Control: no-store`.

### 3.2 Device flow (`/api/mcp/device`, `/api/mcp/token`, `/mcp/connect`)

- Codes are generated with `random_int`/`Str::random` (48 chars for the device code), only
  hashes are stored, the row is locked `FOR UPDATE` while it is decided/exchanged, and it is
  **deleted** on exchange, denial or expiry — single use.
- `slow_down` raises the poll interval by 5 s per too-fast poll without losing the update (the
  failure is thrown after the transaction commits).
- Approval happens in the browser under session + CSRF (`POST /mcp/connect`); the approving
  user is the token owner, and the row stores their id only on approve.
- Throttles: `throttle:mcp-device` (10/min per IP), `throttle:mcp-token` (30/min per IP and per
  device code), `throttle:mcp-approve` (10/min per user) — plus the proxy-aware limiter in
  `DeviceFlowController` that closes the forged-`X-Forwarded-For` gap.
- Expired rows are pruned on every start, so the unauthenticated endpoint cannot grow the table
  without bound.

### 3.3 Transport, origin, CSRF

- `/mcp` is a `laravel/mcp` web route. Its `ValidateOrigin` middleware rejects every request
  whose `Origin` is neither absent, nor `app.url`', nor `config('mcp.allowed_origins')`, nor
  `localhost`/`127.0.0.1` — the DNS-rebinding control. Requests without `Origin` (CLI agents)
  pass and then need a bearer token.
- `api/v1` is origin-agnostic by design: it carries no session and no cookies, so a cross-site
  page has nothing to ride on; it would still need the token.
- `/api/v1/*` and `/mcp` are registered in `bootstrap/app.php` **outside** the `web` group:
  no session, no cookies, no CSRF token. `ForceJsonAccept` keeps validation errors JSON.
- `/mcp/connect` (approve/revoke) *is* in the `web` group: session, CSRF, `auth`.

### 3.4 Authorization and visibility

- Every tool goes through a Policy (`Gate`) or an explicit visibility query; the actor always
  comes from the token, never from request input.
- Recipes: `GameRecipe::visibleTo()` = published for participants, everything for orga.
  `RecipeSkillResource` uses `published()`.
- Files: `SharedFilePolicy::download` (approved, own pending, or orga) **and**
  `AccessibleFiles::ensureVisible` (no approved file of a draft event by id).
- Recipe matching (`RecipeFileMatcher`): approved files only, non-draft events only, pinned
  ids re-checked, patterns verified in PHP with `fnmatch` after a coarse SQL prefilter.
- Install plans and contributions are always written for the calling user; there is no
  endpoint that takes a `user_id`.

### 3.5 Untrusted content (recipes, file names, tool output)

- `RecipeValidator` is the runtime authority over authored YAML: schema, kebab slugs, allowed
  roles/platforms/shells, unique step ids, `sha256` shape, http(s) URLs, and size caps for
  labels, patterns, steps, commands and URLs.
- `ContributeRecipe` clamps summary/description/skill markdown and authorizes updates through
  the policy (T1). Drafts are only visible to their contributor and to orga.
- `SafeText::line()` / `SafeText::fileName()` project user-authored text into agent output:
  control characters become spaces, one line, bounded length, file names reduced to a base
  name (no `..`, no `/`, no `\`). The class is `App\Support\SafeText`; the MCP module's
  `App\Modules\Mcp\Support\SafeText` is a one-line subclass so a module imports its own namespace.
- `Files\Support\UploadFileName::clean()` runs on **both** upload paths — the resumable ticket
  (`IssueUploadTicket`) and the multipart form (`UploadSharedFile`) — so a stored
  `original_name` no longer depends on how the bytes arrived. It removes C0 and C1 control
  characters, the line and paragraph separators, zero-width characters, the byte-order mark and the
  bidirectional formatting overrides, everything before the last path separator, and anything past
  255 characters. It deliberately keeps a backtick, `*`, `?` and a leading dot: a name a shared game
  cannot quote is refused by the recipe builder, not silently rewritten (see residual risk 18). A
  name that cleans to nothing is refused as an upload (`files.errors.invalid_name`, both paths), and
  `forDownload()` cleans a name stored before this rule when it is offered (`LocalFileStore`,
  `RemoteHostFileStore`).
- `LanomatTool` never echoes raw exception messages: unknown throwables become one fixed
  sentence (and are `report()`ed); `RecipeException` problems and domain exceptions are
  flattened to one line.

**Resolved (2026-11-21): the lossy contribution.** A contribution used to lose a recipe's launch
data: `recipe_contribute` had no `metadata` field and `ContributeRecipe` wrote
`metadata = {contributed_via: mcp}`, so a contributor's edit dropped `metadata.launch_targets`.
`ContributeRecipe` now accepts top-level `launch_targets` and `play_url`, keeps the existing metadata
when the payload carries none, caps the document at 20 000 bytes, refuses a non-http(s)
`play_url`/`live_url`/`avatar_converter_url`, and only lets an orga set `metadata.launcher_supported`.
The MCP tool and the REST controller forward those fields.

### 3.6 Recipe supply chain (SMB, git, SMB credentials)

- Source URLs come from the operator (`GAME_RECIPES_SOURCE`, `lanomat:recipes:sync`), never from
  a request. Even so, `git` is invoked with an argument array (`--depth 1 -- <url> <dir>`), a
  scheme allowlist and `GIT_TERMINAL_PROMPT=0`; `smbclient` with literal options and
  `escapeshellarg()` for every variable part; both with a 300 s `Process` timeout and a
  private (0700) temp dir that is removed afterwards.
- A resync never undoes a moderation decision (status) or an admin pin (`shared_file_id`).

### 3.7 Downloads and quota

- Uploads: per-event per-user quota and advisory locking in the `Files` module (see
  [`file-host.md`](file-host.md)).
- Downloads through the agent API: one file per request, `throttle:mcp-api` (240/min per
  token+IP) plus `throttle:60,1`; local store streams an opaque attachment with `nosniff`; the
  file-host store answers `302` to a short-lived signed URL with `Cache-Control: no-store` and
  `Referrer-Policy: no-referrer`.
- The agent sends the bearer token only to the LANoMAT origin (`ApiClient::headersFor`), so the
  signed file-host URL never receives it.

### 3.8 Sharing a game, file pins and the public pull request

- **The share form** (`/games/share`, `POST /games/share`) is an authenticated, throttled
  (`throttle:20,60`) write path: `ShareGameRequest` bounds every string, keeps title, summary, version
  and players on one line and validates the launcher path (relative, no leading separator, no drive
  letter, no `.`/`..` segment, no trailing separator, no control or shell characters). `ShareGame`
  authorizes the pinned file with `SharedFilePolicy::download` (the same ability an install uses),
  refuses an unsafe archive name and an unreliable match pattern, reserves the slug `share` and rejects
  a slug that is not the caller's own draft. The form's own file list is the viewer's own non-rejected
  files of the current event, the 50 most recent; `?file=<id>` only preselects one of them, and only
  when the name is a shareable archive.
- **A pin is not recipe text.** `ContributeRecipe` strips `shared_file_id` from the stored
  requirement and keeps it on the `game_recipe_files` row, keyed by role and label; a sync never
  overwrites it. On a contributor's edit an existing pin survives only while the requirement still
  names the same file — same match-pattern set, same digest — so a renamed requirement cannot keep
  the orga's old file under a new description, and a new row never inherits a pin (T37). One writer
  per slug (a Postgres advisory lock held until commit) makes two people sharing the same title take
  turns instead of each other's draft.
- **The share page's generated text is quoted, not concatenated.** `PortableRecipeBuilder` prints the
  archive path as a PowerShell or POSIX single-quoted literal, writes the `SKILL.md` front matter with
  `Yaml::dump`, and escapes backticks in participant prose.
- **The catalogue readiness check** (`CatalogReadiness`) refuses text the repository's validator would
  reject — before the branch exists — and **the public-safety gate** (`GameRecipes\Support\PublicSafeText`)
  refuses addresses, e-mails and tokens, context-aware: a dotted quad after `://`, `@`, a port or a host
  word always counts, while a version label (after `version`, `patch`, `build` …) or a name ending in a
  file extension does not. A finding stores a redacted reason (`line 12: a private IPv4 address
  10[REDACTED len=10].`) and `pr_state = blocked` (unless a pull request already exists, which keeps its
  state); nothing is sent. Requirements are listed as labels; file-host URLs and ids never leave.
- **The pull request workflow gets no secrets.** `.github/workflows/recipes.yml` runs on plain
  `pull_request` (not `pull_request_target`) with `permissions: contents: read` and only validates
  `resources/recipes`; the token lives in LANoMAT's environment, not in the repository.
- **A file name is cleaned once, at the door.** `UploadFileName::clean()` is the single rule for
  both upload paths (control characters out, path prefix out, 255-character cap). Everything a
  shared game prints is still quoted or refused (`PortableRecipeBuilder`), because cleaning is
  not quoting.

### 3.9 Server rows: plan variables, occupants, `lanReady`

- **`{{server_host}}` / `{{server_port}}`** (`GameRecipes\Support\PlanServerAddress`): the
  recipe never names this installation's server. The server's game must carry **exactly** the
  recipe's slug — no alias, no name resemblance; the browser twin of a native game never
  qualifies — and the link must be `Ready` or `NotResponding` (never offline, failed or still
  provisioning), among the servers the viewer may see anyway (`viewPermanent`). Ranking: a server
  that answers first, then a permanent server, then the lowest id (the earlier order put the exact
  slug and a permanent server ahead of a ready one). `variablesFor()` asks for the
  servers as models (`linksForGlobalBrowser()`, `linksForEventViewedBy()`, `gameOf()`) and is
  used by the plan and by the recipe detail of the REST API, the MCP tool and the native client,
  so no client receives a raw placeholder. The helpers (`variablesFor`, `text`, `check`, `step`,
  `launchTargets`) are public for that. `BuildInstallPlan` substitutes in step commands, step
  checks and launch targets with one `strtr()` pass. The host must match
  `[A-Za-z0-9.-]{1,253}`; the port 1..65535; anything else, and "no such server the viewer may
  see", is the empty string, so the step leaves the game's configuration alone. An IPv6 literal
  is empty by the same rule (the contract has no colons). A recipe without either placeholder
  costs no server query.
- **`occupants`** (`GameServers\Contracts\ServerOccupants`): GameServers owns the question, the
  Presence read model answers through `WebVoice\Support\PresenceServerOccupants` (the one place
  that already names both optional modules), and `NullServerOccupants` answers `null` while
  Presence is off. The audience is the event's people list: `EventPolicy::viewPeople` (a
  non-cancelled participant of that event, or orga) is asked once per event and viewer; anyone
  else gets `null`, however many accounts they have. Within that audience Presence's visibility
  rule applies; the list can be shorter than the server's own player count (people without an
  account, name matches that miss, people who hide their location). `ServerListProjection` takes
  the viewer explicitly — the global browser no longer reads the session — so a caller without a
  viewer gets no people. The event is the list's own event, except that the
  global browser and permanent servers use the current event. One resolver per routed web
  request (`ServerListProjection::perRequest()`): one location-snapshot read and one visibility
  computation per page, fresh in a queue worker or a console command.
- **`lanReady`** (`GameRecipes\Support\RecipeInstallLinks`): `true` when every required `base` or
  `iso` requirement of the matched recipe has an approved upload of a non-draft event (pinned,
  or matched by name) or an external URL. Viewer-independent and event-independent on purpose;
  `launcherJoinUrl` and `launcherSupported` do not depend on it, so a player who already owns
  the game can still join a row whose archive is gone. The answer is cautious about platforms (a
  Windows-only base missing on a Linux plan still makes it `false`), which is why the website says a
  fresh install **can** fail. The game card's `lan_ready` badge is a different fact: "playable
  here", which counts a running server even without the install file.

### 3.10 The catalogue preview and the contribution answer

- **One readiness answer.** `ProposeRecipe::preview(GameRecipe, ?RecipeStatus)` is what the
  panel's `CatalogPreview` and the contribution answer (`Mcp\Support\ContributionCatalog`) both
  ask. It clones the recipe for a hypothetical status, exports its own text and runs
  `CatalogReadiness` (the repository validator's rules) and `PublicSafeText`; no repository
  call, no write, empty list means ready. What it cannot do is guarantee the repository stays
  happy: it is a hand-written mirror of `resources/recipes/schema/validate.mjs`, so a rule
  added to the validator has to be added here too (residual risk 20).
- **The contribution answer.** `recipe_contribute` and `POST /api/v1/recipes` answer with
  `{catalog: {pull_requests_enabled, problems, problems_when_published}}`; problems are
  sentences from the preview over the contributor's own text. A second call with the same slug
  **replaces** the stored draft, so the caller must send the complete recipe (the answer, the tool
  description and the how-to say so); `skill_markdown` is part of it, refuses control characters
  other than newline, carriage return and tab, and the 20–1024-character `skill_description` rule
  is named in the schema. `lanomat://skills/contribute-recipe` (`RecipeContributeSkillResource`) is
  the how-to, generated from the same skeleton and example payload the tests contribute with.

---

## 4. Residual risks (accepted or owned elsewhere)

1. **Recipes are executable content.** A published `commands[].run` is executed on the player's
   machine by the agent. There is no command denylist; the only control is orga review of
   `recipe_contribute` drafts and of YAML on the recipe source. Treat publishing a recipe as
   code review.
2. **Archive extraction (zip-slip) happens in recipe commands.** The agent does not extract
   archives itself; recipes call `Expand-Archive`/`unzip`/`tar`. A hostile archive on the LAN
   file host can write outside `{{target}}` if the command does not guard it. Mitigations:
   archives are orga-approved files, a pinned `sha256` is verified before extraction, and
   recipes should extract into an empty directory. An agent-side safe-extract helper is
   recommended for a later agent card.
3. **SSRF from the player's machine.** A requirement `url` is fetched by the agent (only
   `http`/`https` now). It can still point at private addresses (`http://169.254.169.254/`,
   `http://192.168.x.x`) on the *player's* network. The plan marks such files `source:
   external` and the human must agree before the download. An agent-side private-IP allowlist
   is the next step (agent card).
4. **Prompt injection is reduced, not eliminated.** Tool output (file names, LFG text, recipe
   text, SKILL.md) still contains attacker-authored words. The agent treats everything from the
   server as data; the human confirms destructive steps and external downloads. Skills are
   written to `~/.agents/skills` and read by the model — review contributed SKILL.md too.
5. **Sliding token lifetime.** A stolen token stays valid as long as it is used at least once
   per 90 days. There is no absolute maximum lifetime and no automatic rotation. Revoke under
   `/mcp/connect` (or delete the row) when a laptop is lost.
6. **Token in terminals and shell history.** `php artisan lanomat:mcp-token` prints the token,
   and `pi mcp add … --bearer-token-env-var LANOMAT_TOKEN` encourages `export LANOMAT_TOKEN=…`.
   Terminal scrollback, CI logs and shell history are outside the app's control.
7. **One-proxy assumption behind the throttle.** `McpClientAddress` uses the rightmost
   `X-Forwarded-For` hop, i.e. the address the last proxy appended. With two proxies all
   clients share the bucket of the inner proxy; without a proxy `REMOTE_ADDR` is used. Keep the
   documented single Traefik in front of the app.
8. **`throttle:mcp-api` still keys on `$request->ip()`.** Rotating the header multiplies the
   per-IP buckets, but the limiter also keys on the token hash, so an authenticated caller gets
   at most `240/min × IPs` — bounded by their own token.
9. **SMB credentials fall back to the command line.** `RecipeSourceLocator::smbCommand()` writes a
   0600 `-A` credentials file into the private temp dir and passes that; only when the file cannot
   be written does it use `smbclient -U user%password`, which is visible to `ps` for the duration
   of the sync (seconds, on the app host, by the app user). The temp dir is removed afterwards.
   Mounting the share removes the fallback entirely.
10. **No download quota.** Uploads are quota-limited; a client can pull the same 50 GB ISO as
    often as the throttle allows. Bandwidth policing belongs to Traefik/the file host.
11. **A cleaned name is not a quoted name.** Both upload paths now store
    `UploadFileName::clean()` (control characters removed, path prefix removed, 255-character
    cap), so the same upload no longer reads differently depending on its transport. What
    survives cleaning is still authored text: a backtick, `*`, `?`, parentheses, spaces and a
    leading dot. Every consumer quotes it or refuses it; the files page shows it as stored.
12. **`config('mcp.allowed_origins')` is unset.** Only `APP_URL` and localhost are accepted. If
    the same installation is reachable under several host names (LAN IP + DNS name), add them —
    see the checklist.
13. **A shared game is a hostile archive until the orga looked at it.** The launcher unpacks the
    approved base archive; the recipe's checks prove a file exists, not that unpacking it is safe.
    The controls are file approval (`ApproveSharedFile`) and recipe review; a zip-slip inside the
    archive is still residual risk 2.
14. **What the public-safety gate cannot see.** `PublicSafeText` catches **accidents** — a pasted IP,
    an e-mail, a token — not someone determined to disguise one (an address split into separate YAML
    keys, an image link, base64), and not a person's name, a room number or an internal host name in
    prose. With `RECIPES_PR_ATTRIBUTION=true` the contributor's display name is added on purpose. Once
    pushed, a commit stays in the public repository's history even if the pull request is edited or
    closed.
15. **The `lanomat://` scheme is registered OS-wide.** A link pasted from anywhere reaches the
    launcher without a LANoMAT page. LANoMAT only builds links it considers safe (a published,
    supported recipe; a validated `host:port` or an allowlisted join URL); the launcher's own
    validation is the boundary. The link carries a slug and a join URL — no filesystem path and no
    command.
16. **The GitHub token can write to one repository.** A compromised app host can commit and open pull
    requests there. LANoMAT itself never merges, but a token with *Contents* write can merge through
    the API, so the token is scoped to that one repository, has an expiry and is revoked after the
    event.
17. **`{{target}}` and the other path variables are still substituted as text.** The **agent**
    refuses a path it cannot quote, before anything is downloaded, created or run:
    `clients/agent/src/path-quote.ts` is called from `install.ts` (every step up front),
    `runner.ts` (`applyStepVariables`, again before substitution) and `launch.ts` (the launch
    target), for the shells the OS can actually run. It refuses a quote (`'`, `"`, and on Windows
    the curly quotation marks PowerShell also reads as quotes), a backtick, `$`, a control
    character, the shell operators `;`, `&`, `|`, `<`, `>` (on every platform: a recipe may use the
    placeholder unquoted), a trailing backslash, a doubled backslash when bash is in the set, and —
    on Windows — `%`, `!`, `^`. A leading `~/` in the plan's folder or in `--target` is resolved to
    the home directory before substitution (`expandUserPath`); it was pasted literally into the
    quoted commands before. What it does **not** cover: an unquoted `{{target}}` in a recipe (a
    space already split the argument before the rule applies); the characters that are harmless
    inside the recipe's quotes but not outside (`(`, `)`, `#`, `*`, `?`, `[`, `]`, `~`, `,`, `=`),
    except for the shell operators above; a path pasted into a larger word; and a path produced by
    a caller that bypasses the check. The rule rests on the shells' documentation (PowerShell
    `about_Quoting_Rules`, cmd, bash) and on a real bash run in the tests; PowerShell and cmd were
    not run on a real Windows machine (residual risk 19).

    The **launcher** is unchanged: `clients/launcher/launcher-core/src/install.rs`
    (`launch_plan_command`, `mark_plan_executable`) does a plain
    `command.replace("{{target}}", &target_dir.to_string_lossy())` — no quoting, no refusal. A
    target containing `"` or `` ` `` would end the recipe's quoting and make that player's own
    launch/verify command wrong. In the shipped app this is not reachable through a chosen path:
    `clients/launcher/app/src/lib.rs` always calls `InstallEngine::install` with
    `target_dir: None`, so the target is the plan's `BuildInstallPlan::targetDir()` value (letters,
    digits, space, dot, underscore, hyphen). Only a future caller that sets
    `InstallOptions.target_dir` (the core's repair/portable override) re-opens it.
18. **An unsafe archive name is still refused by the share action, after cleaning.** The name is
    now cleaned on both upload paths (residual risk 11), but cleaning keeps a backtick, `*` and
    `?` on purpose: they would end a Markdown code span or turn the pinned glob into another
    archive's pattern. The share action also refuses `: " < > |`, a leading dot and a `..`, because
    the installing client stores the download under a name it rewrites — a command that still names
    the original would never find the file. It refuses a name whose match pattern would not reliably
    name it (`PortableRecipeBuilder::hasReliableMatchPattern`), with a German "rename it" message;
    a file shared under such a name can still be downloaded from the files page, it just cannot
    become a recipe, and the list says why.
19. **What is verified, and what is not.** The journey was driven once in headless Chromium (the
    only browser installed here): a form upload, the share page's refusals, review and publish, the
    server row's actions and the missing-files line, and the launcher link without a handler (no
    navigation, no dialog, no loss of focus; "Öffne den Launcher …", the notice after 2.5 s, retry
    and dismiss, modified clicks start nothing, a simulated loss of focus shows the "opened"
    confirmation). **Not verified:** a real launcher taking over, any other browser, the resumable
    upload path, real hardware, Windows with a real PowerShell or cmd, the NFSU2 step on game
    hardware, and the real GitHub API. The client, readiness gate and public-safety check run
    against `Http::fake`/`fakeRecipeRepository()` and unit tests; the agent's rule is exercised with
    Node tests and a real bash run in them; the first real branch, review and merge stay the pre-LAN
    checks in [`../pre-lan-acceptance-checklist.md`](../pre-lan-acceptance-checklist.md) §12.
20. **The readiness gate is a second implementation.** `CatalogReadiness` mirrors
    `resources/recipes/schema/validate.mjs` in PHP; nothing shares the rules. A rule added to the
    validator alone makes `preview()` say "ready" for a pull request the workflow will reject
    (red instead of `blocked`). The reverse is harmless (a stricter preview). Keep the two files
    in step; the preview is not a promise.
21. **`skill_markdown` travels verbatim.** A contributor's full SKILL.md is stored as sent
    (control characters excepted), exported as the SKILL.md body and served to every player's
    assistant (`GET /api/v1/recipes/{slug}/skill`, `skills sync`). `PublicSafeText` catches
    addresses, e-mails and tokens, but not a person's name, an internal host name in prose, a
    Markdown image that phones home, an `@team` mention or HTML. It is also an instruction channel
    for other players' assistants — the prompt-injection residual risk 4 applies to it as much as
    to step commands. Only the orga's review stands between the stored text and the public
    repository; a second `recipe_contribute` call replaces the draft, it does not merge into it.
    Read a contributed SKILL.md before merging its pull request.
22. **A helper can choose a match server's address.** `SetManualJoinInfo` is authorised by
    `TournamentPolicy::setManualJoinInfo` (`isHelper()`: admin, orga or helper) and writes a
    human-typed `JoinInfo` onto the match's `ServerLink` as `Ready`. `PlanServerAddress` ranks an
    answering server first, and the global browser includes match servers of public events, so a
    helper who types an address for a match whose game carries exactly a recipe's slug can make
    that host the `{{server_host}}` written into the plans of players who can see the match (and of
    anyone planning without an event). It stays inside the same trust level as the recipe text —
    helpers run the live show — and the value is still shell-safe, but it is a manual input into
    players' game configurations, not only a join hint.

---

## 5. Operator checklist (pre-LAN)

- [ ] `APP_URL` is exactly the origin players type (it is the MCP Origin allowlist).
      Additional host names go into `config/mcp.php` → `allowed_origins` (publish the package
      config or add the key); never `'*'`.
- [ ] The app port is bound to `127.0.0.1` and only Traefik is public (one proxy — see
      residual risk 7).
- [ ] `APP_DEBUG=false`; exception pages never reach `/mcp` or `/api/v1`.
- [ ] `CACHE_STORE=redis` (rate limits must be shared across app/queue/scheduler workers).
- [ ] `MCP_TOKEN_TTL_DAYS` is sane for the event (default 90); `MCP_CODE_TTL` stays ≤ 600.
- [ ] Review every contributed recipe's `steps[].commands` before publishing; publishing equals
      code execution on players' machines.
- [ ] Recipe source: `GAME_RECIPES_SOURCE` points at the intended share/repo; `smbclient`
      installed only on hosts that sync; `GAME_RECIPES_SMB_PASSWORD` comes from the environment
      (never a shell argument that lands in history).
- [ ] Audit `/mcp/connect` after the LAN: revoke tokens for devices that should not keep access.
- [ ] Recipe pull requests, if wanted: `RECIPES_PR_ENABLED=true` with a fine-grained token for exactly
      one repository (*Contents* and *Pull requests* read and write); Preflight **Rezept-Katalog
      (Pull Requests)** shows `ok` ([`recipe-pull-requests.md`](recipe-pull-requests.md)).
- [ ] `RECIPES_PR_TRIGGER=publish` (the default) unless unreviewed text may reach the repository; the
      budgets `RECIPES_PR_PER_USER_HOURLY` and `RECIPES_PR_MAX_OPEN` are the ones you want.
- [ ] Read every recipe pull request before merging it: the repository is public, the history is
      permanent, and `steps[].commands` run on players' machines. In the panel, **Start und Launcher**
      and the publish confirmation show the step and launch commands verbatim.
- [ ] A blocked proposal shows a **redacted** reason in the panel (`line …, an e-mail address
      jo[REDACTED len=…]`), never the value; a proposal over a budget is visible only as
      `recipes.pull_request_skipped` in the queue log.
- [ ] `NFS_SERVER_HOST` has no built-in default: set it before `php artisan db:seed
      --class=LauncherLineupSeeder` or the seeder registers no native NFSU2 server and says so.
      A recipe never names this installation's server; `{{server_host}}`/`{{server_port}}` are
      filled in per viewer and are empty without a server whose game carries exactly the recipe's
      slug. A helper who types a match server's address can thereby choose that value (residual 22).
- [ ] Every server page needs a logged-in viewer *who may see that event's people* for the occupant
      line (`EventPolicy::viewPeople`: a participant of the event, or orga); the bearer APIs never
      carry people. `share_location` and blocks decide who inside that audience is visible.
- [ ] Keep the file host's signed-URL secret (`FILES_HOST_SECRET`, ≥ 32 chars) and the file host
      firewall LAN-only; the MCP token is not what protects the file host.
- [ ] `php artisan lanomat:doctor` (or the Preflight panel) green for files, voice, Discord.

---

## 6. Test map

| Control | Test |
|---|---|
| `/mcp` Origin (DNS rebinding), no session, bearer required | `tests/Feature/Mcp/McpSecurityTest.php` — *refuses a foreign Origin…* |
| Token only in the header, no echo, `no-store` | same file — *accepts the bearer token only in the Authorization header*, *never echoes a rejected token…* |
| Device-row pruning | same file — *prunes expired pairing rows…* |
| Proxy-aware device throttle | same file — *throttles device starts by the hop the proxy appended…* |
| Recipe takeover (T1) | same file — *does not let a participant overwrite a published recipe…*, *lets a participant create a new draft…* |
| File-name injection (T4) | same file — *flattens prompt-injection payloads…* |
| Tool error hygiene (T5) | same file — *never leaks exception internals…* |
| Draft poll (T7) | same file — *refuses to vote in a poll of a draft event* |
| Download IDOR + draft-event file (T3/T16) | same file — *forbids downloading another users pending file by id*, *hides an approved file of a draft event…* |
| Revocation IDOR + CSRF wiring | same file — *forbids revoking another users agent token*, *keeps the browser pairing routes inside the web…* |
| Contribution caps (T11) | same file — *caps an oversized contribution…* |
| Token digest at rest, single use | same file — *keeps the plaintext token out of the database…*; `tests/Feature/Mcp/DeviceFlowTest.php` |
| URL schemes, size caps (T6/T11) | `tests/Feature/GameRecipes/RecipeSecurityTest.php` |
| git option injection (T8) | same file — *rejects git sources that git would parse as an option…* |
| SMB escaping (T9) | same file — *quotes every variable part of the smbclient command* |
| Target-dir traversal (T10) | same file — *keeps a recipe title from becoming a path outside the games folder* |
| Draft/pending file never matched by a recipe | same file + `tests/Feature/Mcp/McpToolsTest.php` |
| Share form refusals, pinned-file authorization (T18/T19) | `tests/Feature/GameRecipes/GameShareTest.php`, `tests/Unit/GameRecipes/PortableRecipeBuilderTest.php` |
| Public-safety gate, context rules (T20) | `tests/Unit/GameRecipes/PublicSafeTextTest.php`, `tests/Feature/GameRecipes/RecipeProposalTest.php` |
| Catalogue readiness, budgets, kept pull request, after-commit job (T20/T26) | `tests/Unit/GameRecipes/CatalogReadinessTest.php`, `tests/Feature/GameRecipes/RecipeProposalTest.php`, `tests/Feature/GameRecipes/RecipePullRequestCommandsTest.php` |
| Title→shell folder, hostile archive names, one-line rule, draft budget (T18/T23/T24/T28/T29) | `tests/Feature/GameRecipes/GameShareTest.php`, `tests/Feature/GameRecipes/GameSharingFoundationTest.php`, `tests/Unit/GameRecipes/PortableRecipeBuilderTest.php` |
| Launch commands visible at review (T25) | `tests/Feature/GameRecipes/RecipeResourceTest.php` |
| Git client: branch/path validation, one open pull request, token redaction (T20/T22) | `tests/Feature/GameRecipes/GitHubRecipeRepositoryTest.php` |
| `lanomat://join` link rules, `lanReady` (T21/T32) | `tests/Feature/GameServers/ServerInstallLinksTest.php` |
| Plan variables: exact slug only, Ready/NotResponding, ranking, host/port characters, empty fallbacks, recipe-detail fill (T30/T35) | `tests/Feature/GameRecipes/InstallPlanServerVariablesTest.php`, `tests/Feature/GameRecipes/Nfsu2PlanTest.php` |
| Occupant line: audience (`viewPeople`), visibility, blocks, event scope, `null`, one read per page (T31/T36) | `tests/Feature/GameServers/ServerOccupantsTest.php`, `tests/Feature/GameServers/ServerCatalogueReadsTest.php` |
| Agent refuses an unquotable path and shell operators; `~/` resolution; bash quoting evidence (residual 17) | `clients/agent/tests/path-quote.test.ts`, `install.test.ts`, `launch.test.ts`, `runner.test.ts` |
| Contribution answer's `catalog`, `skill_markdown`, control characters, 422, replacement wording (T33/T34) | `tests/Feature/Mcp/RecipeContributionCatalogTest.php`, `tests/Feature/GameRecipes/RecipeCatalogPreviewTest.php` |
| Cleaned file name on both upload paths and on download, C1/zero-width/bidi, shareable-archive and rename rules (T4/T18/T38/T39) | `tests/Feature/Files/UploadFileNameTest.php`, `tests/Feature/Files/ShareableArchiveLinkTest.php`, `tests/Unit/GameRecipes/PortableRecipeBuilderTest.php` |
| Pin survival and non-inheritance on an edit (T37) | `tests/Feature/GameRecipes/ContributionFileRowsTest.php` |
| The two launcher labels (`joinLinkNamesServer`) | `tests/js/launcherJoin.test.ts` |
| The unified download page `/app`, its file routes and the `/launcher`/`/talk/client` redirects | `tests/Feature/GameRecipes/LauncherPageTest.php`, `tests/Feature/App/AppDownloadPageTest.php` |
| Published shared text loses its draft wording | `tests/Feature/GameRecipes/PublishRecipeTest.php` |

Run them with:

```bash
docker compose run --rm app php vendor/bin/pest tests/Feature/Mcp tests/Feature/GameRecipes
```

---

## 7. Open items handed to other cards / modules

| Item | Owner |
|---|---|
| Quote or refuse a target path in the launcher's own `{{target}}` substitution (`launcher-core/src/install.rs`), the way the agent does (residual risk 17) | `clients/launcher` / the merged client |
| One source for the catalogue rules (`validate.mjs` ↔ `CatalogReadiness`, residual risk 20) | `GameRecipes` / `R1` |
| Agent-side safe archive extraction (zip-slip) and private-IP block for external URLs | agent cards 6/8 |
| Run the agent's path rule on real Windows PowerShell 5.1 and cmd (residual risk 19) | pre-LAN hardware check |
| Repeat the journey in a second browser and with a real launcher taking over (`scripts/smoke/game-sharing-smoke.mjs` is manual, headless Chromium only) | pre-LAN hardware/browser check |
| Absolute token lifetime / rotation policy | MCP follow-up |
| `config/mcp.php` `allowed_origins` for multi-host installs | ops/deployment |
| The occupant adapter lives in `WebVoice` while its binding depends only on GameServers + Presence; move it if WebVoice ever stops being the bridge | `GameServers` / `WebVoice` |
