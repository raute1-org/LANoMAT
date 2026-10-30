# LANoMAT QA handbook

A repeatable way to test LANoMAT before an event and after every deploy. It grew out of the pre-LAN QA programme (four rounds, suites S0-S9); everything in this folder is written to be **public-safe** (placeholders only, no production values), because the repository is public.

| File | Purpose |
|------|---------|
| [`test-plan.md`](test-plan.md) | The cases, per suite, with expected results; the smoke-minimal list and the pre-LAN full-run list |
| [`feature-inventory.md`](feature-inventory.md) | What exists per module and profile (the basis for the cases) |
| [`rulings.md`](rulings.md) | Product decisions that define the expected behaviour |
| [`findings.md`](findings.md) | Consolidated findings with severity and fix status |
| [`open-questions.md`](open-questions.md) | Open questions and the owner's recorded answers |
| [`qa-seed.php`](qa-seed.php) | Sanitised, idempotent seed data for a throwaway local database |
| [`runs/`](runs) | Reports of executed rounds |

## Suites

| Suite | Scope | Where it runs |
|-------|-------|---------------|
| S0 | Automated gate: `composer check`, frontend gates, module and route sanity | local, core and full |
| S1 | Auth, guest rules, role gates, security headers, error pages | local, core |
| S2 | Participant journey: registration, tickets, self check-in, arrival plan, seating, profile, teams | local, core |
| S3 | Orga/admin journey: events lifecycle, QR, scanner, arrival overview, seats, games, tournament admin, preflight | local, core (admin sweep under full) |
| S4 | Tournaments end to end: enroll, check-in, start, results, disputes, formats, standings | local, core |
| S5 | Optional modules (schedule, catering, voting, LFG, files, gallery, presence, friends, infoscreen, jukebox, casting, voice, Discord surface, game servers, hosts) | local, full |
| S6 | Realtime (Reverb): live updates, reconnect and resync, origin lock, channel auth | local |
| S7 | Responsive and accessibility basics (390 px, focus, labels, contrast, motion) | local |
| S8 | Production smoke, READ-ONLY (plus the optional, separately approved write test S8-W) | production |
| S9 | Failure and edge cases: invalid ids, IDOR, races, throttles, odd input, outages | local, core |

Two shortcuts live in the test plan: **smoke-minimal** (20 cases, 15 minutes, after every deploy, production read-only) and the **pre-LAN full run** (all suites in a fixed order plus the owner's manual items).

## Safety rules (learned the hard way)

1. **Throwaway database, selected by environment variables.** Export `DB_DATABASE=lanomat_qa` (and `APP_ENV=local`) in every shell that runs artisan or composer. Never run QA against the dev database or the pest test database. `migrate:fresh` is only safe because of that variable.
2. **Blank every external secret.** A developer `.env` may contain real Discord (and other) credentials; one QA run once reached the real Discord API this way. Before anything that can send, blank all `DISCORD_*`, `MUMBLE_*`, `PELICAN_*`, `STEAM_*`, `TWITCH_*` (also `TEAMSPEAK_*`, `MUSIC_*`) values with shell overrides (the loop in the test plan recipe does it for every variable present in `.env`), and check the effective value is empty. Shell variables win over `.env`.
3. **Never run the browser suites against production data-changing.** Production gets S8 only: GET/HEAD, read-only commands, viewing pages without saving.
4. **Production writes only with an explicitly named throwaway event and mandatory cleanup.** Approval is per run. Record baseline counts first, use only the `QA-Prod-Smoke` style event, delete everything afterwards and compare counts with the baseline (S8-W in the test plan). Never touch real participants' data.
5. **Parallel agents get separate resources:** their own test database name (`lanomat_qa_<n>`; the seed accepts any `lanomat_qa*` name), their own app and Reverb ports, and exactly one dev server per port.
6. **Leave the shared dev stack as you found it.** Do not stop the owner's running dev session or shared containers; stop only what you started; drop your throwaway database at the end.
7. **Public repository hygiene.** Reports and handbook files contain placeholders (`<APP_HOST>`, `<APP_SERVER>`, `<ssh-user>`, `<ADMIN_DISCORD_ID>`, `example.test`), never real IPs, host names, IDs, e-mail addresses, tokens or production data. Production hardening checks are phrased generically ("verify password SSH login is disabled", "verify only the intended ports are reachable"), without the actual settings. Before committing run `scripts/ops/check-public-safe.sh` and the gitleaks scan from `CONTRIBUTING.md` ("Public repository rules").
8. **Never commit local secret backups.** The untracked prod-test env backup (`.env.prodtest.bak`) and any `.env*` copies, dumps or key material stay out of git (they are gitignored; check `git status` before committing).
9. **Record, do not fix.** A QA run writes findings and open questions; fixes go through normal pull requests and are re-tested in the next round.

## How to run

All commands from the repository root; details and the full recipe are in the test plan, section 1.

**S0 gate (once, before the browser suites)**

```bash
composer check                                   # pint --test, phpstan level 8, pest
npm run types:check && npm run lint:check && npm run format:check && npm run build   # run alone, not concurrently with pest
MODULES_PROFILE=core php artisan lanomat:modules      # and again with full
```

**Local suites (S1-S7, S9)**

```bash
docker compose up -d postgres redis
export DB_DATABASE=lanomat_qa APP_ENV=local MODULES_PROFILE=core     # full for S5
# blank DISCORD_*/MUMBLE_*/PELICAN_*/STEAM_*/TWITCH_* (see test plan, section 1)
php artisan migrate:fresh --seed --force
php artisan tinker --execute="$(cat docs/qa/qa-seed.php)"
php artisan serve --port=8000                        # + queue worker where a case needs it
php artisan reverb:start --port=8081                 # S6 only
```

Run S1-S4, S6, S7 and S9 under `core` first (production parity), then S5 under `full`. Log in with the seeded users (password `password`) through the login helper in the test plan; the Discord login itself is a manual owner step.

**S8 production smoke**

Read-only, from a machine with the intended access: `curl` for the public checks, `ssh <ssh-user>@<APP_SERVER>` for read-only commands in `<DEPLOY_DIR>` (`lanomat:modules`, `lanomat:preflight`, `schedule:list`, `queue:failed`, `docker compose --profile prod ps/logs`). The 20-case smoke-minimal list is the part to run after every deploy.

## QA seed

`qa-seed.php` creates placeholder-only data: users `qa-admin@`, `qa-orga@`, `qa-helper@`, `qa-p1@` to `qa-p10@` (`@example.test`, password `password`, synthetic Discord ids), one event per lifecycle state (`qa-draft`, `qa-announced`, `qa-reg`, `qa-small`, `qa-near`, `qa-live`, `qa-finished`), seat grids, and four tournaments on `qa-live`. It is idempotent, refuses to run unless `APP_ENV=local` and the database name starts with `lanomat_qa`, and has a cleanup mode (`QA_SEED_CLEANUP=1`). The dependable cleanup is dropping the throwaway database.

## Results format

Each executed case gets one of:

| Result | Meaning |
|--------|---------|
| `PASS` | Expected result observed |
| `FAIL(F-<suite>-<n>)` | Deviation; a finding was written |
| `BLOCKED(OQ-<n>)` | Could not be run; an open question explains why |
| `MANUAL` | Needs a person or real external infrastructure (OAuth, phones, real voice/panel) |
| `N-A` | Not applicable in this profile or environment |

Findings use the ID `F-<suite>-<n>` (for example `F-S4-2`) and a severity: **Critical** (data loss, security, production down), **Important** (feature broken for many, 500 on a common path), **Minor** (workaround exists, copy, a11y nit), **Info**. Each finding records repro steps with the environment (local core/full or production), expected, actual, evidence and, once handled, the status (`fixed` with the pull request, `accepted`, `open`). Open questions are written self-contained with a recommended default so the run can continue.

A run report goes to `runs/<suites>-<topic>.md` with: branch/profile/environment in one paragraph, counts per result, the findings table, per-case results with short evidence, and new open questions. Keep it free of raw environment chatter and real values.
