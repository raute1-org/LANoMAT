# Agent swarm — MCP, agent, game recipes and docs

**Date:** 2026-11-21 · **Scale:** 24 concurrent subagents (19 workers + 5 reviewers/mergers)

This is the operating spec for building out the LANoMAT MCP server, the `lanomat-agent`
installer, the game-recipe library and the Zudoku docs with a 24-slot swarm. It is written so a
fresh coordinator can pick it up without the original conversation.

## 1. Goal and definition of done

One vertical slice is done when all of the following are true on `main` (or on the stack of PRs
ready to merge):

1. `docker compose run --rm app composer check` is green (Pint, PHPStan level 8, full Pest).
2. `npm run lint:check`, `npm run format:check`, `npm run types:check`, `npm run build` are green.
3. `clients/agent`: `npm run lint`, `npm test`, `npm run build` are green; Docker image builds.
4. `docs-site`: `npm run build`, `npm run check:links` are green; the Docker image serves
   `/docs/` and a deep link such as `/docs/recipes/ut2004` returns 200.
5. A fresh install can pair an agent (`lanomat-agent login`), sync skills, list recipes and build
   a UT2004 install plan against a seeded LANoMAT, with the ISO matched on the LAN file host.
6. The docs cover every module, the MCP tool catalogue, the REST API, the agent, the recipe
   schema, the games page and the deployment, with current screenshots.
7. No secret is committed (Venice key, SMB password, tokens); `gitleaks` is clean.

## 2. Slot layout (24)

| Slot | Role | Kind | Owns |
|---|---|---|---|
| C | Coordinator | main agent | queue, spawns, final merge, user updates |
| R1 | Architecture reviewer | reviewer | module gating, policies, route matrix, PHPStan |
| R2 | Security reviewer | reviewer | tokens, device flow, SMB, path traversal, prompt injection, IDOR |
| R3 | Test reviewer | reviewer | full Pest in an isolated DB, frontend checks, flaky tests |
| R4 | Docs reviewer | reviewer | build, links, screenshots, brand, accuracy vs code |
| R5 | Merge captain | reviewer | rebase/cherry-pick, conflict resolution, post-merge suite |
| W1–W19 | Workers | worker | one worktree each, disjoint files, targeted tests |

The coordinator keeps **24 child sessions alive**: when a slot finishes, it is immediately
re-spawned with the next task from the queue (§6). Reviewers are re-spawned for every wave with a
fresh diff; a reviewer never reviews its own worker's change.

## 3. Isolation rules (non-negotiable)

- **One git worktree per worker.** `git worktree add /tmp/opencode/swarm/wN -b swarm/wN <base>`.
  The main checkout is the coordinator's; workers never edit it.
- **Disjoint paths.** Every task lists its writable paths. A worker that needs a shared file
  (`composer.json`, `Module.php`, `routes/web.php`, `AppServiceProvider.php`, `compose.yml`) asks
  the coordinator instead of editing it, unless the task names that file.
- **Database per worker.** `docker exec lanomat-postgres-1 createdb -U lanomat lanomat_swarm_wN`
  and run Pest with `-e DB_DATABASE=lanomat_swarm_wN`. Never point two workers at
  `lanomat_test`: `RefreshDatabase` deadlocks.
- **Ports per worker.** Frontend/docs workers use `4000 + N`; the app uses `8000 + N`.
  `DOCS_PORT`, `APP_URL` and `VITE_PORT` follow the task card.
- **Vendor/node_modules.** Symlink `vendor` and `node_modules` from the main checkout. If a worker
  adds a PHP class, it runs `composer dump-autoload` in its own vendor **copy** (the task card
  says whether it may add classes; tests-only tasks may not).
- **Commit every task** on `swarm/wN` with Conventional Commits. No uncommitted work is handed to
  a reviewer.
- **Tests before hand-off.** Each worker runs the narrowest test set that proves its change and
  pastes the command + result into its report. Reviewers re-run the broad set.

## 4. Review protocol

1. Worker commits and reports: branch, commits, files, commands run, known gaps.
2. Coordinator assigns the branch to a reviewer from a different specialty:
   - code/architecture → R1, security → R2, test/coverage → R3, docs → R4.
3. Reviewer checks out the branch in its own worktree, reads the diff, runs the checks, and
   returns **APPROVE**, **REQUEST CHANGES** (with exact file:line and a failing command) or
   **BLOCK** (contract ambiguity, needs the coordinator).
4. A REQUEST CHANGES goes back to the same worker session (not a new one) with the findings. The
   worker fixes, re-tests, and hands back. Two failed rounds escalate to the coordinator.
5. APPROVED branches go to the merge captain (R5) in dependency order:
   `recipes-content → backend (recipes+MCP+games page) → agent → docs → CI/ops`.
6. R5 merges one branch at a time, runs `composer check`, the frontend checks, the agent suite and
   the docs build after each merge. A red merge is reverted, not fixed forward.
7. The coordinator re-runs the full suite on the merge result; only then is a PR opened.

## 5. Quality bars per area

**Backend (recipes/MCP/games page)**
- Module gated (`Modules::enabled`/`routable`), no unguarded optional→optional calls.
- Every new route has a `RouteAuthorizationMatrixTest` entry (public/self-service/open-object).
- Every tool goes through a Policy or an explicit visibility query; a foreign id is 404, a policy
  refusal 403, a domain rule 422.
- `composer check` green; new classes have `@property`/`@return` types for PHPStan level 8.
- Device flow: digests only, constant-time compare, throttle, single use, sliding expiry, revoke.
- SMB/git sources: no shell interpolation of user input; `escapeshellarg` + `Process` timeouts.

**Agent**
- Offline tests only; no network, no real home directory, no key printed.
- Token file 0600, config merge preserves unrelated keys, downloads verify sha256 and resume.
- Commands are quoted per shell; Windows paths with spaces work; `--dry-run` has no side effects.

**Docs**
- Every page has real content; internal links resolve; `check:links` green.
- Screenshots are current, labelled, and use the Signalpult palette; no raw hex in pages.
- The `/docs` basePath works in dev and in the nginx image; deep links return 200.
- Facts match the code (tool names, env vars, routes, limits).

**Ops**
- Docs container is profile `docs`, path-routed on the app host, healthcheck green.
- CI runs the backend shards, the agent suite and the docs build; no secret in any file.
- `.env.example`, README and CHANGELOG mention every new switch.

## 6. Task queue (worker cards)

Cards are ordered; the coordinator hands out the next card when a slot frees. Each card names the
base branch, writable paths, the proof command and the reviewer.

| # | Card | Base | Writable | Proof |
|---|---|---|---|---|
| 1 | Recipes: Filament review, policy, factories, tests | backend | `app/Modules/GameRecipes/Filament`, `Policies`, `database/factories`, `tests/Feature/GameRecipes` | `pest tests/Feature/GameRecipes` |
| 2 | Recipes: SMB/git sync, parser edge cases, export | backend | `app/Modules/GameRecipes/Support`, `Actions`, `tests` | targeted Pest |
| 3 | MCP: device-flow security, command, revocation | backend | `app/Modules/Mcp`, `tests/Feature/Mcp` | targeted Pest |
| 4 | MCP: every tool contract + module gating | backend | `app/Modules/Mcp/Tools`, `tests/Feature/Mcp` | targeted Pest |
| 5 | Games page: N+1, indexes, empty states, tests | backend | `GamePageController`, `Games/*.vue`, `tests` | targeted + `types:check` |
| 6 | Agent: launch/deep-link/register hardening | agent | `clients/agent/src`, `tests` | `npm test && npm run build` |
| 7 | Agent: packaging, Docker, Node matrix, audit | agent | `clients/agent`, `.github/workflows/client-agent.yml` | `npm ci && npm test && docker build` |
| 8 | Agent: command runner quoting + config migration | agent | `clients/agent/src`, `tests` | targeted vitest |
| 9 | Recipes content: UT2004/Ridge fact-check | recipes | `resources/recipes/unreal-tournament-2004`, `ridge-racer-type-4` | `validate.mjs` |
| 10 | Recipes content: draft normalization + CS 1.6 | recipes | `resources/recipes/**` | `validate.mjs` |
| 11 | Avatars: catalog conventions + examples | recipes | `resources/recipes` | `validate.mjs` |
| 12 | Docs: accuracy pass vs code | docs | `docs-site/pages/**` | `check:links` |
| 13 | Docs: screenshots for new pages | docs | `docs-site/public/screens`, `scripts` | build + image checks |
| 14 | Docs: brand/a11y QA | docs | `docs-site/styles.css`, theme | build |
| 15 | Docs: deployment QA (`/docs`, nginx, Coolify) | docs | `docs-site/Dockerfile`, `nginx.conf`, `deploy/**` | `docker build` + curl |
| 16 | Security: prompt injection, tokens, SMB, IDOR | backend | report + minimal fixes | targeted Pest |
| 17 | CI/ops: workflows, gitleaks, env docs | main | `.github/workflows`, docs | `act`/dry-run, gitleaks |
| 18 | E2E: seeded app + agent pair/plan/avatar | backend | `tests/e2e` or script | script exit 0 |
| 19 | Games page E2E: filter, links, LFG | backend | `tests/Browser` or Playwright | Playwright exit 0 |

## 7. Reviewer runbook

- **R1 Architecture:** `git diff base...branch`; module imports vs baseline; `Modules::enabled`
  guards; policies registered; route matrix entries; `pint --test`, `phpstan` on changed paths.
- **R2 Security:** read the diff for secrets, raw SQL, shell interpolation, missing throttles,
  missing policy calls, `hash_equals` misuse, unbounded uploads/downloads, prompt-injection
  surfaces (tool text returned to the model), `Origin` handling on `/mcp`.
- **R3 Tests:** run the full Pest on a fresh `lanomat_review_<n>` DB, frontend checks, agent suite;
  flag tests that assert implementation details, sleep, or hit the network.
- **R4 Docs:** `npm run build`, `node scripts/check-links.mjs`, image size/alt check, palette
  scan (no off-brand hex), deep-link curl of `/docs/...`, compare claims with `routes/` and tools.
- **R5 Merge:** merge order above; after each merge run the full checks; keep a merge log in
  `docs-site/deploy/merge-log.md` (or the PR body).

## 8. Reporting and progress

- The coordinator posts a short progress block after each wave: merged, in review, blocked,
  test status.
- The docs preview (`/docs`) is kept running (port 4001) and updated as pages land, so progress is
  visible at a glance.
- A branch is only "done" when its reviewer approved **and** the merge captain's post-merge run is
  green. "Works on my machine" is not a status.
