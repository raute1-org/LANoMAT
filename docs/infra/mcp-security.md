# MCP, agent API and game recipes — security model

**Scope:** the `Mcp` module (`/mcp`, `/api/v1/*`, `/api/mcp/*`, `/mcp/connect`), the
`GameRecipes` module (`recipe_contribute`, recipe sync from SMB/git, install plans, avatars)
and the parts of the agent (`clients/agent`) that consume them.
**Audited:** 2026-11-21 (card 16, agent swarm). Code, tests and this document are the record;
a finding that is only documented here is listed under *Residual risks*.

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
| T4 | Prompt injection through an uploaded **file name** (newlines, fake instruction lines, unbounded length) surfacing in `files_list`, install plans, avatars, recipe detail | **Medium** | `GameRecipes\Support\SafeText` flattens control characters to one bounded line and reduces names to a base name in every agent-facing projection | mitigated |
| T5 | Tool errors echo raw exception text (SQL, absolute paths, caller-authored slugs) into the model's context | **Medium** | `LanomatTool` returns a fixed message for unexpected throwables, a sanitized one-liner for `RecipeException`/domain errors | fixed |
| T6 | A recipe `url` uses a non-HTTP scheme (`file://`, `javascript:`, `data:`), which the agent fetches on the player's machine | **Medium** | `RecipeValidator` accepts only `http`/`https` for `requirement.url`; empty is allowed (LAN-only recipe) | fixed |
| T7 | A poll of a draft event is votable by id through `poll_vote` | **Low–Medium** | `PollVoteTool` resolves the poll through a non-draft event (404 otherwise) | fixed |
| T8 | A `git+` source is parsed by git as an **option** (`--upload-pack=…` runs a command) or as a local read | **Low** (operator-only input) | `RecipeSourceLocator::gitUrl` allowlists `https/ssh/git/scp-style/absolute path`, rejects a leading `-` and whitespace; `git clone … -- <url> <dir>`; `GIT_TERMINAL_PROMPT=0` | fixed |
| T9 | `smbclient` invocation depends on `sprintf` + escaping; a hostile host/share/sub/password | **Low** | `RecipeSourceLocator::smbCommand` builds the command from literals and `escapeshellarg()` for every variable part; unit-tested as an exact contract | fixed |
| T10 | A recipe title like `..` becomes the install target dir and escapes it | **Low** | `BuildInstallPlan::targetDir` strips separators and dot runs, falls back to the slug | fixed |
| T11 | Unbounded contribution payloads (megabytes of markdown) → DB/memory growth | **Low** | Request validation caps (description 10 000, skill markdown 65 535, ≤50 requirements/steps, ≤20 aliases/patterns) plus length caps in `RecipeValidator` | fixed |
| T12 | Token in a URL/query string or a log line | **Low** | The bearer is read from `Authorization` only (proven by test); no query-string fallback; device/token responses are `no-store`; 401s are `no-store` | verified/fixed |
| T13 | DNS rebinding / cross-site call against `/mcp` | **Low** | `laravel/mcp`'s `ValidateOrigin` middleware runs before our auth middleware and only allows `app.url` (+ `mcp.allowed_origins`, localhost) | verified |
| T14 | Session/CSRF confusion: a browser session authenticating the API | **Low** | `/api/v1` and `/mcp` are registered outside the `web` group (no session, no cookies, no CSRF); `/mcp/connect` is inside `web` | verified |
| T15 | Device-code brute force | **Low** | 8 chars from a 31-symbol alphabet, only SHA-256 digests stored, 600 s TTL, approval 10/min per user, polling 60/min per client hop, `slow_down` backoff | verified |
| T16 | IDOR on downloads, plans, avatars, contributions, revocation | **Low** | every path goes through a Policy or an explicit visibility query; foreign ids are 403/404; `DELETE /mcp/devices/{id}` owner-or-admin | verified |
| T17 | Published/draft leakage (recipes, events, files, polls) | **Low** | `GameRecipe::visibleTo/published`, `Event::publiclyVisible`, `VisibleFiles`, matcher filters, tool tests | verified |

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
  name (no `..`, no `/`, no `\`).
- `LanomatTool` never echoes raw exception messages: unknown throwables become one fixed
  sentence (and are `report()`ed); `RecipeException` problems and domain exceptions are
  flattened to one line.

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
9. **SMB credentials on the command line.** `smbclient -U user%password` is visible to `ps` for
   the duration of the sync (seconds, on the app host, by the app user). Mounting the share or
   using an authentication file would remove this; sshd-style hardening is out of scope.
10. **No download quota.** Uploads are quota-limited; a client can pull the same 50 GB ISO as
    often as the throttle allows. Bandwidth policing belongs to Traefik/the file host.
11. **File names are stored raw on one upload path.** `UploadSharedFile` (multipart) keeps
    `getClientOriginalName()` as-is, unlike `IssueUploadTicket::cleanName()`. The agent surface
    sanitizes on read (`SafeText`), but the Files module should normalize on write too (owned by
    the `Files` module, not this card).
12. **`config('mcp.allowed_origins')` is unset.** Only `APP_URL` and localhost are accepted. If
    the same installation is reachable under several host names (LAN IP + DNS name), add them —
    see the checklist.

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

Run them with:

```bash
docker compose run --rm app php vendor/bin/pest tests/Feature/Mcp tests/Feature/GameRecipes
```

---

## 7. Open items handed to other cards / modules

| Item | Owner |
|---|---|
| Normalize `original_name` in `UploadSharedFile` (multipart path) with `cleanName()` | `Files` module |
| Agent-side safe archive extraction (zip-slip) and private-IP block for external URLs | agent cards 6/8 |
| Absolute token lifetime / rotation policy | MCP follow-up |
| `config/mcp.php` `allowed_origins` for multi-host installs | ops/deployment |
| Regenerate `tests/Architecture/module-dependencies.php` (GameRecipes → GameServers/Lfg/Tournaments pre-exist from `GamePageController`) | games-page card 5 |
