# LANoMAT test plan

A repeatable end-to-end test handbook. It was written during the pre-LAN QA programme and is kept current with the product (state: see `feature-inventory.md`). Companion docs:

- [`README.md`](README.md): how to run the suites and the safety rules (read this first).
- [`feature-inventory.md`](feature-inventory.md): what exists, per module and profile.
- [`rulings.md`](rulings.md): product decisions that define the expected results below.
- [`findings.md`](findings.md), [`open-questions.md`](open-questions.md): consolidated outcome of the programme.
- [`qa-seed.php`](qa-seed.php): sanitised, idempotent local seed data.
- [`runs/`](runs): reports of executed rounds.

Result convention when executing: `PASS` / `FAIL(F-<suite>-<n>)` / `BLOCKED(OQ-<n>)` / `MANUAL` / `N-A`. Record screenshots only for FAIL or key evidence. Placeholders used below: `<APP_HOST>` (production host name), `<APP_URL>`, `<APP_SERVER>`, `<ssh-user>`, `<DEPLOY_DIR>`, `<BACKUP_DIR>`, `<ADMIN_DISCORD_ID>`; seeded identities use `example.test`.

Expected results follow `rulings.md`. Where a case was changed by a fix round, the current behaviour is stated here, not the original one.

## Quick index

| Block | Where | Cases |
|-------|-------|-------|
| [Smoke-minimal](#smoke-minimal-the-20-cases-after-every-deploy-15-minutes) | production, read-only | 20 |
| [Pre-LAN full run](#pre-lan-full-run) | local + production + owner manual | all suites |
| S0 Automated gate | local | 4 |
| S1 Auth, guest rules, headers | local core | 26 |
| S2 Participant journey | local core | 53 |
| S3 Orga/admin journey | local core | 45 |
| S4 Tournaments end to end | local core | 35 |
| S5 Other modules | local full | 37 |
| S6 Realtime | local | 9 |
| S7 Responsive and accessibility | local | 15 |
| S8 Production smoke | production, read-only | 32 |
| S9 Failure and edge cases | local | 32 |

## 0. Rules for all runs

1. **Production is READ-ONLY** in S8: GET/HEAD, read-only artisan (`lanomat:preflight`, `lanomat:modules`, `route:list`, `schedule:list`, `queue:failed`), `docker compose ps/logs`, viewing pages in the already logged-in browser without saving anything. No POST/PATCH/DELETE, no form submits, no state-changing admin actions, no seeding. A production write test exists only as the explicitly approved, throwaway-event procedure in [S8-W](#s8-w-production-write-test-throwaway-event-optional).
2. Local runs use a **throwaway database** selected by shell environment variables (never the dev DB, never the pest test DB). All test data is prefixed `QA-` / `qa-`.
3. **Never reach a real external service.** Blank every `DISCORD_*`, `MUMBLE_*`, `PELICAN_*`, `STEAM_*`, `TWITCH_*` secret in the shell (recipe below). OAuth and real-voice/real-panel steps are MANUAL and belong to the owner's acceptance run.
4. On unexpected behaviour write a finding (`F-<suite>-<n>`) and continue. On ambiguity about intended behaviour write an open question with a recommended default and continue with the default.
5. Do not fix code during a QA run unless asked; suites record only. Fixes land in separate branches and are re-tested in the next round.
6. Parallel agents: one database name, one app port and one Reverb port per agent; exactly one dev server per port.

## 1. Local environment recipe

```bash
cd <repo>
docker compose up -d postgres redis                     # dev ports: Postgres 5434, Redis 6380
docker exec <postgres-container> psql -U lanomat -d postgres -c "CREATE DATABASE lanomat_qa"   # once per agent/DB name

# 1. throwaway DB + profile. Shell env wins over .env (dotenv is immutable), so the dev DB is untouched.
export DB_DATABASE=lanomat_qa APP_ENV=local MODULES_PROFILE=full     # or core for the production-like pass

# 2. BLANK every external secret present in .env (a dev .env may hold REAL credentials).
while IFS='=' read -r k _; do
  case "$k" in DISCORD_*|MUMBLE_*|PELICAN_*|STEAM_*|TWITCH_*|TEAMSPEAK_*|MUSIC_*) export "$k=";; esac
done < .env
php artisan tinker --execute='var_dump(env("DISCORD_BOT_TOKEN"));'   # must print string(0) ""

# 3. optional isolation of shared services (recommended: nothing of the dev stack is touched)
export CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=database BROADCAST_CONNECTION=log   # BROADCAST_CONNECTION=reverb for S6

php artisan migrate:fresh --seed --force                # safe ONLY because DB_DATABASE=lanomat_qa
php artisan tinker --execute="$(cat docs/qa/qa-seed.php)"   # idempotent QA data, prints counts
php artisan lanomat:modules                              # confirm profile
php artisan serve --port=8000                            # app (plus `php artisan queue:work --tries=1` when queues matter)
php artisan reverb:start --port=8081                     # S6 only; the compose reverb service is not usable for this
```

- Run the same exports in EVERY shell that runs artisan/composer for the QA run.
- `REVERB_ALLOWED_ORIGINS` must contain every origin you use (`localhost:8000,localhost,127.0.0.1:8000,127.0.0.1`); S6-06 restricts it on purpose.
- The scheduler is not running locally: run `lanomat:tournament-tick`, `lanomat:heartbeat`, `lanomat:send-schedule-reminders` and similar by hand.
- **Two parallel logins in one Chrome profile:** cookie jars are per host, so use `http://localhost:8000` for user A and `http://127.0.0.1:8000` for user B.
- Run S1-S4, S6, S7, S9 under `core` first (production parity), then `full` for S5 plus a regression smoke. Switching profile means restarting the server with the other `MODULES_PROFILE`.
- **Fakes** (`FakeDiscordClient`, `FakeMumbleClient`, ...) are bound only inside Pest. Locally the real HTTP clients are bound with blanked credentials; external calls must fail gracefully (never 500). Automated coverage of the fakes: `composer check` (S0).
- Reset between suites: `DROP DATABASE lanomat_qa` + `CREATE DATABASE lanomat_qa`, then repeat migrate + seed. Final cleanup: drop the database, stop every process you started, remove temp files, leave the shared dev stack as you found it.

### LOGIN(email) helper (participant OAuth is not automatable locally)

The login page has only the Discord button plus `data-test="dev-login-participant|dev-login-orga"` (`APP_ENV=local`). For the seeded users use the Fortify endpoint (password for every seeded user is `password`, rate limit 5/min per email+ip):

1. Navigate `/login` (to get the XSRF cookie).
2. In the page (`javascript_tool`):
```js
const x=decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
const r=await fetch('/login',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-XSRF-TOKEN':x},body:JSON.stringify({email:'qa-p1@example.test',password:'password'})});
r.status
```
Expect 200/204/302, then navigate `/dashboard`. Beware: `POST /dev/login/participant` while logged in silently swaps the identity of the session (local only); do not mix it into IDOR runs.

Seeded identities: `qa-admin@`, `qa-orga@`, `qa-helper@`, `qa-p1@ ... qa-p10@` (all `@example.test`). Seeded events: `qa-draft`, `qa-announced`, `qa-reg` (Registration, 20 spots, 2x4 seats, tickets standard/vip), `qa-small` (Registration, max 2), `qa-near` (Registration, starts in 6h), `qa-live` (Live, 3x4 seats, tournaments below), `qa-finished`. Tournaments on `qa-live`: `QA-SE Solo`, `QA-DE Solo`, `QA-RR Solo` (Enrollment, max 16) and `QA-SE Draft (no enrollment)` (Draft).

Helper ENROLL via tinker:
```bash
php artisan tinker --execute='$t=App\Modules\Tournaments\Models\Tournament::firstWhere("name","QA-SE Solo"); foreach(App\Models\User::where("email","like","qa-p%")->orderBy("id")->take(8)->get() as $u){ app(App\Modules\Tournaments\Actions\EnrollSolo::class)->handle($t,$u);}'
```
(The action bypasses the policy. For the UI/HTTP path, non-orga users must hold an event registration first; see S4-05.)

### Browser tooling notes (claude-in-chrome)

Load the tools first via ToolSearch. Use `tabs_context_mcp`, `navigate`, `read_page`/`get_page_text` (DOM text), `computer` (screenshot), `form_input`, `find`, `javascript_tool` (fetch for statuses/headers), `read_console_messages`, `read_network_requests` (4xx/5xx and websocket). HTTP-only checks may use `curl -si`. Open your own tab; do not navigate the owner's existing tabs. A browser extension that injects content can make form interactions fail; that is environment, not an app defect.

## Smoke-minimal: the 20 cases after every deploy (15 minutes)

Production, read-only (S8 rules). Stop and investigate on the first FAIL; a red smoke run blocks announcing the deploy as done. Each row points to the full case.

| ID | Check | Expected | Full case |
|----|-------|----------|-----------|
| SM-01 | `GET /up` | 200 | S8-07 |
| SM-02 | TLS certificate and HTTP -> HTTPS redirect | valid public certificate with comfortable remaining validity; plain HTTP redirects to HTTPS | S8-01, S8-02 |
| SM-03 | Security headers on `/` | the documented header set is present (see `docs/deploy-lan-raute1.md`); no version leak | S8-03 |
| SM-04 | Guest landing and guest redirects | landing 200 without event data; protected pages redirect to `/login` | S8-04, S8-05 |
| SM-05 | Login page | Discord button only, no dev-login buttons; Discord redirect URL has the correct callback (not followed) | S8-08 |
| SM-06 | Module-off and unknown URLs | 404 with the German error page, no stack trace | S8-06 |
| SM-07 | Active profile | `lanomat:modules` shows the intended profile and module set | S8-15 |
| SM-08 | Preflight CLI | exit 0, no red row (including backup freshness) | S8-16 |
| SM-09 | Containers | all services up, application healthy, no restart loop | S8-17 |
| SM-10 | Failed jobs and error logs | no failed jobs; no unexplained error lines since the deploy | S8-18, S8-19 |
| SM-11 | Scheduler | scheduled commands listed, heartbeat fresh | S8-20 |
| SM-12 | Backup | newest database dump and storage archive exist, are fresh and verify | S8-21, S8-22 |
| SM-13 | Admin panel | `/admin` loads for an admin, preflight widget green, no console errors | S8-10, S8-11 |
| SM-14 | Core admin pages | events, users, seats, tournaments, games, teams list pages return 200 | S8-10 |
| SM-15 | Event and registration pages | current event page and register page show the expected status/availability (view only) | S8-14 |
| SM-16 | Check-in QR base URL | the printed URL starts with the production base URL (read the token row, do not create one) | S8-12 |
| SM-17 | Hardening | password SSH login is disabled; only the intended ports are reachable from outside; firewall active | S8-23, S8-24 |
| SM-18 | Deploy state | running revision equals `origin/main`, working tree clean, no pending migrations | S8-27 |
| SM-19 | Realtime | Reverb container healthy and preflight Reverb OK; WSS connects on a page with live channels when one is available | S8-25 |
| SM-20 | Data sanity | no `QA-` / demo users or events in production; counts plausible | S8-31 |

## Pre-LAN full run

Run once, a few days before the event, in this order. Record everything in a new `runs/` report.

1. **Gate:** S0 (`composer check`, frontend gates, module and route checks).
2. **Local, `core` profile (production parity):** S1, S2, S3, S4, S6, S7, S9 on a fresh throwaway database and the QA seed.
3. **Local, `full` profile:** S5, S3-41 (admin sweep), plus a regression smoke of S2/S4 key rows.
4. **Production, read-only:** all of S8 (the smoke-minimal list is its subset).
5. **Production write test** (only if the owner approves it for this run): the S8-W procedure with baseline counts and mandatory cleanup.
6. **Owner manual items** (cannot be automated; use `docs/pre-lan-acceptance-checklist.md` for the real-infrastructure details):
   - Discord login through the real OAuth flow: member, non-member, admin (S1-21).
   - Steam/Twitch account linking and unlinking (S2-34).
   - Camera QR scanning of a printed ticket and of the event QR on real phones (S3-16..19, S2-21..27).
   - CSP reports: provoke one violation in DevTools and confirm it reaches the CSP log channel (the policy is still report-only).
   - Real Discord/Mumble/Pelican/Music Assistant behaviour per the acceptance checklist.
   - Venue network: Wi-Fi/LAN reachability of the app, the beamer URL and the voice server from a participant device.
7. **Sign-off:** open findings reviewed, every `FAIL`/`BLOCKED` either fixed, accepted (with a ruling) or an explicit owner decision.

## S0. Automated gate (once, before browser suites)

| ID | Area | Steps | Expected | Verify |
|----|------|-------|----------|--------|
| S0-01 | Static/unit | `composer check` | pint, phpstan (level 8), pest all green | exit code, last lines; record the test count |
| S0-02 | Frontend | `npm run types:check && npm run lint:check && npm run format:check && npm run build` | all green | exit codes |
| S0-03 | Modules | `MODULES_PROFILE=core php artisan lanomat:modules`, same with `full` | core = Events, Registration, Seating, Teams, Games, Tournaments, Identity, Notifications, Preflight; full = every module enabled, none disabled | stdout |
| S0-04 | Routes | `MODULES_PROFILE=core php artisan route:list --except-vendor` | No route of an optional module (`/screen`, `/jukebox`, `/friends`, `/discord/*`, `/overlay`, `/stats`, `/schedule` ...); record the route count | count, grep |

## S1. Auth, guest rules, security headers (run under `core`, repeat key rows under `full`)

Pre: dev server up, no session. All HTTP checks via `curl -si http://localhost:8000<path>` unless "browser".

| ID | Area | Steps | Expected | Verify |
|----|------|-------|----------|--------|
| S1-01 | Guest landing | browser: open `/` | 200, event-free landing (Welcome), Discord login CTA, no event names/dates of non-public data | screenshot + text |
| S1-02 | Login page | open `/login` | Discord button; locally (`APP_ENV=local`) also the dev-login participant/orga buttons | DOM `data-test` |
| S1-03 | Login page prod parity | not testable locally (`APP_ENV=production` is not used locally) | see S8-08 (no dev buttons in production) |  |
| S1-04 | Guest redirects | `curl -si` each: `/dashboard /events /events/qa-live /events/qa-live/register /events/qa-live/seating /events/qa-live/tournaments /tournaments/1 /settings/profile /settings/connections /settings/appearance /settings/security` | every one: 302 to `/login` | status + Location |
| S1-05 | Guest POSTs | `curl -si -X POST` `/events/qa-live/register`, `/tournaments/1/enroll`, `/matches/1/report`, `/teams` | 302 `/login` or 419 (CSRF); never 200/500 | status |
| S1-06 | Guest admin | `/admin`, `/admin/events`, `/admin/users` | 302 to `/login` (login is Discord-only; there is no separate `/admin/login` page) | Location |
| S1-07 | Guest orga routes | `/orga/events/qa-live/checkin`, `/orga/events/qa-live/checkin-qr` | 302 `/login` | status |
| S1-08 | Public pages | guest `GET /teams`, `/teams/{id}` (after S2 creates one), `/users/{id}`, `/up`, `/.well-known/passkey-endpoints` | 200; `/users/{id}` and teams exposure noted: list exactly which user fields are shown to a guest (name, avatar, bio, steam/stream URL, email?) | text; EMAIL must NOT appear (else finding HIGH) |
| S1-09 | Dev login prod guard | local: `POST /dev/login/admin`, `/dev/login/xyz` (with CSRF) | 404 both (admin not an allowed demo role) | status |
| S1-10 | Dev login ok | click `dev-login-participant` | redirect `/`, logged in as Demo-Teilnehmer; role participant | header/user menu |
| S1-11 | Role gate: participant | LOGIN qa-p1; GET `/admin`, `/orga/events/qa-live/checkin`, `/orga/events/qa-live/checkin-qr` | `/admin` 403; orga routes 403 | status |
| S1-12 | Role gate: helper | LOGIN qa-helper; GET `/orga/events/qa-live/checkin` -> 200; `/orga/events/qa-live/checkin-qr` -> 403; `/admin` -> 403 | as stated | status |
| S1-13 | Role gate: orga/admin | LOGIN qa-orga; `/admin` 200, `/orga/.../checkin-qr` 200; same for qa-admin | 200 | status |
| S1-14 | Draft event 404 | LOGIN qa-p1; GET `/events/qa-draft`, `/events/qa-draft/register`, `/events/qa-draft/seating`, `/events/qa-draft/tournaments` | 404 each for a participant; the same pages load for orga/admin | status |
| S1-15 | Login throttle | POST `/login` wrong password 6x for same email (JSON) | 422 x5 then 429 | status sequence; wait 60s afterwards |
| S1-16 | CSRF | LOGIN qa-p1; `fetch('/events/qa-reg/register',{method:'POST'})` without X-XSRF-TOKEN | 419 | status |
| S1-17 | Security headers (local) | `curl -sI http://localhost:8000/` | No `X-Powered-By` version leak; `Content-Security-Policy-Report-Only` with a per-request nonce and `Reporting-Endpoints` pointing at `/csp-report`; `Referrer-Policy` / `Permissions-Policy` present (or provided by the proxy: then verify in S8-03); cookies: session HttpOnly + SameSite=Lax, XSRF readable by JS by design | headers |
| S1-18 | Error pages | GET `/nonexistent` as guest and as user; provoke 403 (participant on `/admin`), 419 (POST without token from a browser form), 429 (S1-15) | German Signalpult error pages (403, 404, 419, 429, 500, 503), correct titles, no stack trace; the 403 page offers the admin link only to orga/admin | screenshot |
| S1-19 | JSON error shape | `curl -si -H 'Accept: application/json' /events/qa-live` | 401 JSON (not HTML redirect) | body |
| S1-20 | Module-off 404s (core run) | LOGIN qa-p1 under `MODULES_PROFILE=core`: GET `/events/qa-live/schedule`, `/events/qa-live/jukebox`, `/friends`, `/stats/leaderboard`, `/screen/qa-live`, `/discord/voice`, `/overlay/tournament/1/bracket` | 404 each; UI nav shows no links to these | status + nav text |
| S1-21 | Discord guild gate | MANUAL: needs real Discord OAuth. See OQ-01. Automated stand-in: Pest tests in `tests/Feature/Identity` pass (S0-01) | non-member -> /login with "Zugang nur fuer Mitglieder..." status; Discord outage -> "try again" message; admin exempt | owner confirms |
| S1-22 | Discord callback edge | `curl -si 'http://localhost:8000/auth/discord/callback?error=access_denied'` | 302 `/login` (status flash "abgebrochen") ; `?code=x&state=bad` -> 302 `/login` ("abgelaufen"), never 500 | status/Location |
| S1-23 | Discord redirect | as guest GET `/auth/discord/redirect` | 302 to the Discord authorize URL with `client_id`, `redirect_uri=<APP_URL>/auth/discord/callback`, scopes (+ the guild-membership scope when a login guild is configured); a logged-in user is redirected away. Do NOT follow the redirect | Location |
| S1-24 | Logout | logged in: user menu -> Abmelden (or `POST /logout`) | session ends; `/dashboard` redirects to `/login` | status |
| S1-25 | Session regeneration | note session cookie before/after LOGIN | cookie value changes after login | cookie diff |
| S1-26 | CSP report endpoint | `POST /csp-report` with a sample `application/csp-report` JSON body (no CSRF token); `GET /csp-report` | 204 and one line in the dedicated CSP log channel; GET 405; no 419; oversized/garbage body does not 500 | status, log file |

## S2. Participant journey (core, local; LOGIN as qa-p1, second user qa-p2 on 127.0.0.1)

| ID | Area | Pre | Steps | Expected | Verify |
|----|------|-----|-------|----------|--------|
| S2-01 | Dashboard (no reg) | qa-p1 logged in, no registration | `/dashboard` | cards render; current event shown or empty state; no JS errors | screenshot, console |
| S2-02 | Event list |  | `/events` | Section "Aktuell und kommend" (announced, registration, live; soonest first, with a link to the registration) above the archive; no draft event | text |
| S2-03 | Event page | | `/events/qa-reg` | name, location, dates, status label, availability, register CTA | text |
| S2-04 | Register: announced event |  | `/events/qa-announced/register`; try POST via fetch | page says registration is not open yet; POST 403 | status |
| S2-05 | Register: happy path | event qa-reg Registration | `/events/qa-reg/register` -> choose ticket `standard`, no arrival plan -> submit | redirect back; shows ticket with QR + status registered; toast | screenshot; DB `event_registrations` row; `notifications` row `RegistrationConfirmed` |
| S2-06 | Ticket QR token | after S2-05 | read ticket token from DB: `select qr_token from event_registrations ...` | non-empty, unguessable length (>=32 chars) | DB |
| S2-07 | Bell notification | after S2-05 | open bell in header | "Anmeldung bestaetigt" unread, mark as read works (`POST /notifications/{id}/read`) | screenshot, DB read_at |
| S2-08 | Register with arrival plan | qa-p2 on 127.0.0.1, event qa-reg | choose ticket `vip`, arrival day = first event day, window `evening`, departure = last day `afternoon` | success; plan displayed | DB arrival_day/window columns |
| S2-09 | Arrival plan validation | qa-p3 | arrival = day outside event; arrival after departure; day without window | 422 field errors for each | DOM errors / status |
| S2-10 | Update arrival plan | qa-p1 registered | on register page change plan (PATCH) -> save; then clear all (all empty) | saves; empty = "weiss ich noch nicht" | DB |
| S2-11 | Invalid ticket type | | fetch POST register with `ticket_type=hacker` | 422 | status |
| S2-12 | Double register | qa-p1 | POST register again | no duplicate row; friendly error/redirect | DB count = 1 |
| S2-13 | Cancel registration | qa-p3 registered, NOT checked in | click cancel (DELETE) | status cancelled; seat (if any) released; `checked_in_at` null; arrival plan cleared on re-register | DB |
| S2-14 | Re-register after cancel | after S2-13 | register again | works, empty plan | DB |
| S2-15 | Full event | qa-small max 2 | qa-p1, qa-p2 register; qa-p3 tries | p3 sees Full/"ausgebucht"; POST rejected 403/422 | status, DB count=2 |
| S2-16 | Seating view | qa-reg has seats, p1 registered | `/events/qa-reg/seating` | SVG grid of 8 seats, free/taken states, legend | screenshot |
| S2-17 | Claim seat | p1 | click free seat -> confirm | seat marked mine; dashboard shows seat | DB `seat_assignments` |
| S2-18 | Claim taken seat | p2 on 127.0.0.1 | claim seat held by p1 | error toast, no change | DB |
| S2-19 | Claim w/o registration | qa-p4 (not registered) | POST claim | rejected (error/403), no assignment | DB |
| S2-20 | Move/release seat | p1 | release (`DELETE .../seating`), claim another | works, old seat free | DB |
| S2-21 | Self check-in: too early | event qa-reg (starts in 48h), p1 registered; token from `select token from event_checkin_tokens` (create by opening admin QR modal in S3-12 first) | open `/checkin/{token}` | "Einchecken noch nicht moeglich" with opening time | text |
| S2-22 | Self check-in window | event qa-near (starts in 6h => inside the 12h window), p1 registered | open its `/checkin/{token}`, click Einchecken | "Du bist eingecheckt seit HH:MM"; DB `checked_in_at` set | DB |
| S2-23 | Self check-in idempotent | after S2-22 | reload / click again | still checked in, same timestamp | DB |
| S2-24 | Self check-in unknown token | | `/checkin/not-a-token` | 404 | status |
| S2-25 | Self check-in unregistered | qa-p5 not registered on live event | open live event's token URL | shows "Jetzt anmelden"; click -> registered -> back to check-in -> check in works | DB |
| S2-26 | Self check-in full event | live event filled to max (use small live variant via tinker) | unregistered user opens token | "Ausgebucht, bitte wende dich an die Orga." | text |
| S2-27 | Self check-in finished | `qa-finished` token | open | "event is over" message, no check-in | text |
| S2-28 | Availability rule | one event per status: announced, registration, live (spots free), live (full), finished, archived | open the register page of each and POST register | Registration is possible only in `registration` and in `live` while spots remain; closed in announced/finished/archived and when full; the register page and the check-in page show the matching state (Upcoming/Open/Full/Closed, "Jetzt anmelden" for walk-ins) | text, status |
| S2-29 | Public profile | p1 | `/users/{id}` of p1 | profile with name/bio/color; no email | text |
| S2-30 | Profile edit | p1 | `/settings/profile`: set name `QA-P1 Renamed`, bio, steam_url (`https://steamcommunity.com/id/qa`), stream_url (`https://twitch.tv/qa`), profile_color; save | success toast; values persisted after reload; shown on public profile | DB + screenshot |
| S2-31 | Profile validation | p1 | invalid email, 5000-char bio, `javascript:` stream_url, invalid color | 422 per field, nothing saved | DOM errors |
| S2-32 | Appearance | | `/settings/appearance` toggle light/dark/system | theme changes immediately; persists after reload (cookie `appearance`) | screenshot |
| S2-33 | Connections page |  | `/settings/connections` | Providers shown only when configured (Steam needs its API key, Twitch its client credentials); state "nicht verbunden" | screenshot |
| S2-34 | Connections link | MANUAL | click Steam/Twitch "Verbinden" | needs real OAuth; ask owner (OQ-02) | owner |
| S2-35 | Connections unlink | seed a `linked_accounts` row via factory (`LinkedAccountFactory`) | click Trennen (`DELETE /settings/connections/steam`) | row deleted; page updates | DB |
| S2-36 | Connections bad/unconfigured provider |  | `GET /settings/connections/battlenet/redirect`, `/foo/redirect`, and the redirect of a provider that is not configured | 404 (not offered), no 500; an existing linked row of an unconfigured provider is still listed and can be unlinked | status |
| S2-37 | Security settings (passwordless) | Discord-like user without password (set `password=null` via tinker) | `/settings/security` | redirected away (no dead end) | Location |
| S2-38 | Security settings (with password) | qa-p1 (has password) | `/settings/security`, confirm-password prompt; password change form with a wrong current password | confirm-password flow; wrong password -> 422 with a GERMAN message | status, text |
| S2-39 | Delete account | throwaway user qa-p10 | `/settings/profile` delete flow with confirmation | user removed (or soft?), logged out; registrations handled | DB |
| S2-40 | Teams: create | p1 | `/teams` -> create `QA-Team Alpha` tag `QAA` | team page; p1 owner | DB |
| S2-41 | Teams: validation |  | empty name; 65-char name; 17-char tag; a name that equals an existing team name in other casing | 422 each; team names are unique case-insensitively | DOM |
| S2-42 | Teams: join request | p2 (127.0.0.1) | open team, "Beitrittsanfrage" | pending request; the owner gets a bell notification | DB `team_join_requests`, bell |
| S2-43 | Teams: respond | p1 | accept p2's request (`POST /teams/{t}/requests/{r}`) | p2 member; p2 gets an answer notification in the bell | DB, bell |
| S2-44 | Teams: non-owner respond | p2 | POST respond for another request | 403 | status |
| S2-45 | Teams: edit + logo | p1 | `/teams/{id}/edit` change tag; upload PNG logo; non-image upload | tag saved; logo served via Storage URL (not base64); non-image rejected | DOM, `<img src>` |
| S2-46 | Teams: non-owner edit | p2 | GET `/teams/{id}/edit`; PATCH | 403 | status |
| S2-47 | Teams: leave | p2 | leave | removed | DB |
| S2-48 | Teams: owner leave blocked | p1 | leave while members exist | blocked with message (must transfer ownership) | text |
| S2-49 | Dashboard (full) | p1 registered, seat, team | `/dashboard` | shows event, registration status, seat, team(s), steam link state | screenshot |
| S2-50 | Navigation | p1 | click every sidebar link of the unified shell (core: Dashboard, Events, Teams, current-event group ...) and the user menu | no 404/500; active states; German copy; admin link only for orga/admin | network log |
| S2-51 | Cancel after check-in | qa-p1 checked in (S2-22) | registration page; direct `DELETE /events/qa-near/register` | cancel button replaced by a short German explanation; the DELETE flashes the same message instead of a 403; orga can still cancel | text, DB |
| S2-52 | Cancel on finished event | registered user on a finished event | registration page / DELETE | self-cancel refused with the "event over" explanation | text |
| S2-53 | Unified shell and dashboard | p1 registered, seat, team | `/dashboard`, resize to 390px | shell/sidebar identical on all pages, dashboard shows event, registration, seat, team, next steps; no horizontal overflow | screenshot |

## S3. Orga / admin journey (core, local; LOGIN qa-orga on localhost, qa-admin when role-specific)

| ID | Area | Pre | Steps | Expected | Verify |
|----|------|-----|-------|----------|--------|
| S3-01 | Panel entry | qa-orga | `/admin` | dashboard with AccountWidget, FilamentInfo, **Preflight status widget** (ampel) | screenshot |
| S3-02 | Nav groups | | inspect sidebar | core: Events, Seats, Teams, Games, Tournaments (+Disputes), Users. Full: plus Schedule, Catering, Polls, Infoscreen, ... | text |
| S3-03 | Event create | | Events -> Create: `QA-Created Event`, location, start +10d, end +12d, max 30 | created as Draft; slug generated | DB |
| S3-04 | Event validation |  | create with empty name; end before start; max_participants 0 / negative | validation errors: end must be after start; capacity an integer >= 1 or empty (= unlimited, hint shown) | DOM |
| S3-05 | Lifecycle step 1 | event from S3-03 | header action Announce | status announced; toast | DB, label |
| S3-06 | Draft invisibility |  | as qa-p1 (127.0.0.1) `/events/qa-created-event` before announce -> 404; after announce -> 200; orga can open it as draft | as stated | status |
| S3-07 | Lifecycle step 2 |  | Open registration (Registration) | status registration; the in-app "Anmeldung offen" bell reaches every user without an active registration (minus those who disabled the `registration` category), independent of the Discord module | DB notifications |
| S3-08 | Lifecycle forward only |  | on the status header note the available actions at each step; the header refreshes right after a transition (no stale buttons) | only the next transition offered; no status jump possible | DOM |
| S3-09 | Lifecycle live | | Live, then Finished, then Archived | each succeeds; Archived has no further actions; finished event register page closed | DB |
| S3-10 | Event edit | | change name/location; save | persisted | DB |
| S3-11 | Event delete | QA-Created Event | Delete with confirmation | removed; participants' pages 404 | DB |
| S3-12 | Check-in QR modal | event qa-reg | EditEvent -> "Check-in-QR" | modal with SVG QR + URL built from `APP_URL` (`<APP_URL>/checkin/<token>`, not from the request host); token row created | DOM, DB |
| S3-13 | QR print page | | "Drucken" opens `/orga/events/qa-reg/checkin-qr` (new tab) | clean sheet: big QR, event name, "QR-Code scannen und einchecken" | screenshot |
| S3-14 | QR rotate | | Token erneuern + confirm | new token; old `/checkin/<old>` -> 404 (generic); new -> 200 | status |
| S3-15 | QR URL base | | compare printed URL host with `APP_URL` | equal (prod check in S8-10) | DOM |
| S3-16 | Orga scanner page | qa-helper | `/orga/events/qa-live/checkin` | scan/paste input renders | screenshot |
| S3-17 | Scanner check-in | p1 registered on qa-live; token from DB | paste `qr_token`, submit | success toast with name; DB `checked_in_at` | DB |
| S3-18 | Scanner invalid | | paste garbage; paste token from other event; paste cancelled registration's token | error toasts each; no check-in | DB |
| S3-19 | Scanner double | | scan same token twice | second: already checked in message | text |
| S3-20 | Registrations manager | event qa-reg with several registrations | Events -> edit -> Registrations relation manager | table with participant, ticket label, status, arrival plan; status filter; sort/search; "Stornieren" row action whose modal names the participant; export action works (CSV, columns correct) | file content |
| S3-21 | Arrival overview | seed 6 regs with mixed plans (S2-08 + tinker) | Event edit -> "Anreise-Uebersicht" -> `/admin/events/{id}/arrival` | tiles (placed/unknown/checked-in), stacked chart per day/window, expected-present chart with peak, table | screenshot |
| S3-22 | Arrival table filters | | filter day, window, "ohne Plan"; sort; search | rows match | DOM |
| S3-23 | Arrival numbers | | compute by hand from DB plans per `docs/arrival-planning.md` rules (placed vs unknown, departure-only = unknown, cancelled excluded) | tiles/chart values equal hand computation | calc vs UI |
| S3-24 | Arrival access | | participant GET `/admin/events/{id}/arrival` | 403 | status |
| S3-25 | Seats admin: grid | | Seats -> Generate grid: event qa-small, rows 2, cols 3, prefix `S` | 6 seats created; re-run same prefix -> no duplicates or clear error | DB |
| S3-26 | Seat edit | | edit a seat: network metadata (switch/port/IP) | saved; visible where designed | DB |
| S3-27 | Seat CRUD guard |  | create/edit a seat with a duplicate label in the same event | German validation message (label unique per event), no 500 | DOM |
| S3-28 | Seat release by orga | | (if UI) free a participant's seat; else note absence | record | note |
| S3-29 | Teams admin | | Teams list/create/edit/delete (logo), search | works; delete team with members handled | DB |
| S3-30 | Games admin |  | Games -> create `QA-Game`, edit, delete; delete a game used by a tournament | CRUD ok; deleting a game in use is blocked with a German notification; Pelican/preset fields only visible when the GameServers module is on | DB |
| S3-31 | Tournaments admin create |  | create `QA-Admin SE` on qa-live (game, format SE, team size 1, max 8, starts_at +1h, check-in window, rules text); there is no status field | created as `draft`; status shown as a badge on the edit page | DB |
| S3-32 | Tournament lifecycle actions | QA-Admin SE just created | use the header/row actions: "Ankündigen" (draft -> announced), "Anmeldung öffnen" (announced or draft -> enrollment), "Zur Check-in-Phase", "Turnier starten", "Zurück zu Entwurf" | "Anmeldung öffnen"/"Ankündigen" refuse with a German list of what is missing (game, max entries >= 2, check-in window ordering); "Zurück zu Entwurf" only with zero non-withdrawn entries (button hidden otherwise); participants can enroll only in `enrollment` | DB status, toasts |
| S3-33 | Entries manager | tournament in enrollment with 3 entries | EntriesRelationManager: check_in, withdraw | statuses change; withdrawn not counted | DB |
| S3-34 | Users list | | `/admin/users`: search, sort by role; open one (`/admin/users/{id}`) | read-only; linked accounts relation; NO edit/delete controls | screenshot |
| S3-35 | Users: roles | | look for role change control | none (by design: role via `lanomat:install`); record | note |
| S3-36 | Disputes queue | dispute exists (S4-14) | `/admin/tournaments/disputes` | disputed matches listed with entries/scores; Override modal sets final scores | DB |
| S3-37 | Preflight widget |  | view the ampel; compare with `php artisan lanomat:preflight` | rows equal (database, redis, queue worker, scheduler heartbeat, storage, failed jobs, reverb, backup freshness; optional-module rows only when the module is on); locally Reverb/Queue/Scheduler are green only if running | output diff |
| S3-38 | Preflight CLI | | `php artisan lanomat:preflight` ; stop queue worker 2 min and re-run; stop scheduler heartbeat | exit code non-zero with failing row named; recover afterward | stdout |
| S3-39 | Health watch | | `php artisan lanomat:health-watch` with a failing check | SystemUnhealthy notification to staff bell, de-duplicated on re-run | DB |
| S3-40 | Admin session | | log in as qa-p1 in another host/tab: `/admin` 403; panel logout; user-menu "Zur App" link | works | status |
| S3-41 | Admin full-profile pages | `MODULES_PROFILE=full` | open every Filament nav entry (see inventory 8) and its create page | each loads 200 with no console errors | network |
| S3-42 | Orga override reg cancel | | orga cancels another user's registration (via relation manager or `DELETE`) | policy allows orga; participant cannot cancel others (S9) | DB |
| S3-43 | Audit: notifications to staff | | `lanomat:install` idempotent run | prints "Admin ready" | stdout |
| S3-44 | Panel chrome and copy |  | browse the panel at 1440px and 390px | "ADMIN" marker and "Zur App" back link on every page; navigation matches the app; all copy German and informal ("du"), dates `d.m.Y H:i` in the display timezone, German empty states and labels on relation managers | screenshot |
| S3-45 | Backup freshness probe | set the backup marker file to a fresh / stale mtime (see `docs/deploy-lan-raute1.md`) | `lanomat:preflight` and the widget | fresh -> OK; stale/missing -> failing row naming the backup; recovers when the marker is touched | stdout |

## S4. Tournaments end to end (core, local; qa-live event; 2 sessions p1 + p2; orga on third host alias `localhost` vs `127.0.0.1` rotation)

Pre for all: seed + fresh tournaments in Enrollment. Reset DB between sub-flows if needed.

| ID | Area | Steps | Expected | Verify |
|----|------|-------|----------|--------|
| S4-01 | Draft not visible / announced not enrollable | `QA-SE Draft (no enrollment)` (draft) and an announced tournament: LOGIN p1, open index, show page, POST enroll; also the overlay URL and Discord list as guest/participant | draft tournament invisible to non-orga everywhere (index, show 404, overlay 404, no Discord list entry, no schedule item); announced is visible with game/mode/slots/rules and "Anmeldung öffnet bald" but no enroll (POST 403) | status |
| S4-02 | Tournament index | `/events/qa-live/tournaments` | visible tournaments with status labels and "x / max Anmeldungen" (or Teams), "ausgebucht" while enrollment is open and full; order: live, check-in, enrollment, then finished; withdrawn entries not counted | text |
| S4-03 | Solo enroll | p1 on `QA-SE Solo`: Anmelden | enrolled, toast, entry in DB | DB |
| S4-04 | Double enroll | p1 again (fetch POST) | rejected "already enrolled"; one entry | DB |
| S4-05 | Enroll w/o event registration | p6 not registered for qa-live enrolls (web and Discord `/tournament checkin`) | rejected with "Melde dich zuerst zum Event an" and a link to the registration page while registration is open; orga bypasses; only the acting owner's registration counts for team entries | toast, DB |
| S4-06 | Full tournament | set `max_entries=2` via tinker; third user enrolls | "full" error | toast |
| S4-07 | Withdraw | owner withdraws own entry (UI button with confirm) in `enrollment` and `check_in`; a plain team member tries | entry Withdrawn, `checked_in_at` cleared, slot freed immediately; withdraw then re-enroll works; plain member refused; refused once the tournament started | DB |
| S4-08 | Team tournament | tournament team_size 3 (tinker); owner of a team of 3, owner of a team of 2, user without a team | team picker with only eligible teams selectable ("[TAG] Name"); hint + link to create a team when none; foreign team id via crafted POST -> 403; missing `team_id` -> German flash, no 404 | status |
| S4-09 | Start too few | tournament with fewer than 2 participating entries (checked-in ones when check-in is required); Admin Start; scheduler tick | danger notification "not enough entries", status unchanged; the tick skips it and logs one warning per 6 h; no 500, no retry loop | toast, log |
| S4-10 | Check-in phase | (a) set `checkin_opens_at` = now-1m, run `php artisan lanomat:tournament-tick`; (b) manual "Zur Check-in-Phase" with a future `checkin_opens_at`; (c) with `checkin_closes_at` already passed | (a) status CheckIn, participants see Einchecken; (b) sets `checkin_opens_at` to now so participants can really check in; (c) refused with a German message | DB, UI |
| S4-11 | Participant check-in | p1..p8 click Einchecken (use ENROLL snippet + check in via manager for most; p1/p2 via UI) | checked_in true; outside window rejected | DB |
| S4-12 | SE start n=8 | Admin: Turnier starten + confirm | status Live; bracket 3 rounds, 7 matches, seeds placed; page shows bracket with connectors | DB match count, screenshot |
| S4-13 | Report result | p1 (entrant of a Ready match, as entry 1 AND as entry 2 in two matches) reports "own : opponent" 2:1 | score stored by the reporter's side (an entry-2 reporter does not credit the opponent); both sides see the pending report (who, when, score from their own perspective); the reporter sees "Warte auf Bestätigung durch <Gegner>" instead of Bestätigen/Anfechten; match numbers start at #1 | DB `match_reports`, screenshot |
| S4-14 | Dispute | p2 disputes p1's report | match Disputed; appears in `/admin/tournaments/disputes` | UI |
| S4-15 | Orga override | orga overrides score 2:0 | match Completed; winner advances to next round; broadcast | DB + bracket |
| S4-16 | Confirm | new match: p3 reports, p4 confirms | Completed, winner advances | DB |
| S4-17 | Report validation | tied score 2:2; negative; letters; 2147483648 and larger | 422 each, shown INLINE on the form (tie message `tied_score`); no 500 | status/DOM |
| S4-18 | Report authz | p5 (not in match) report/confirm/dispute; reporter confirms own report | 403 each | status |
| S4-19 | Optimistic lock | two sessions submit/confirm conflicting at once, repeated confirm click, stale `lock_version` | one wins; the others get a friendly "already updated, reload" toast/flash, never a 500/404 page; no corrupt state | DB |
| S4-20 | Helper `go` | match in Warmup: helper `POST /matches/{m}/go`; participant tries | helper ok -> Live; participant 403 | status |
| S4-21 | Complete SE | finish all matches; also n=2 (single match) | TournamentCompleted; winner ("Sieger") shown; bracket final; finished badge; n=2 finishes when its only match is decided | UI |
| S4-22 | SE odd sizes | fresh tournaments with n=2,3,5,7,16 entries; start each | bracket with byes visible/handled, no stall; spot-check n=5 completes | DB |
| S4-23 | DE n=8 | `QA-DE Solo` 8 entries start; play WB final/LB path | winners + losers brackets, grand final (+ reset if LB winner wins GF); completes | UI, DB |
| S4-24 | DE odd n=5 and n=33 | start; progress all matches | no stall (regression of the double-elimination stall), byes labelled "Freilos/Entfaellt" | UI |
| S4-25 | Bounds | n=1 and n=65 (tinker), SE and DE | start rejected with the unsupported-size / not-enough-entries error (module supports 2..64) | toast |
| S4-26 | Round robin | `QA-RR Solo` n=4 -> 6 matches; play all | standings table (wins, score difference, head-to-head for an exact two-way tie, points, stable order) correct; tournament finishes when every match is decided; winner = first row | UI |
| S4-27 | Autostart | tournament with `starts_at` in past, status Enrollment w/ 4 entries; run tick | StartTournamentJob runs (queue) -> Live | DB |
| S4-28 | Tick idempotent | run tick 3x | no double events/matches | DB |
| S4-29 | Double start | click Start twice quickly / second tab | single bracket; second -> already started | DB |
| S4-30 | Draft/finished visibility | participant opens a tournament of a Draft event | 404 | status |
| S4-31 | Match page | click a match | shows entries, scores, actions per role | screenshot |
| S4-32 | Voice/Discord absent (core) | match page in core | no voice join link, no Discord buttons, no errors | DOM |
| S4-33 | Withdraw / correct a pending report | p1 reported; before the opponent acts: "Zurückziehen" and "Korrigieren"; then the opponent clicks Bestätigen on the old page | withdraw returns the match to Ready; correct = withdraw + resubmit in one step (one broadcast); the stale opponent page gets the stale toast; the opponent confirming a withdrawn report gets "Die Meldung wurde zurückgezogen"; after confirm/dispute withdrawal is refused (only the orga override remains) | DB, toasts |
| S4-34 | Orga override on any match state | orga overrides a ready, reported and disputed match | match completed, winner advances, broadcast | DB |
| S4-35 | Admin lifecycle round trip | see S3-32 plus participant view at each step | every step visible to participants as specified (announced: info only; enrollment: enroll; check_in: Einchecken; live: bracket) | UI |

## S5. Other modules (only under `MODULES_PROFILE=full`; local only)

Pre: full profile, QA seed. Add per-module data via factories (listed per row). External services are down/unconfigured: graceful failure expected (no 500).

| ID | Module | Steps | Expected | Verify |
|----|--------|-------|----------|--------|
| S5-01 | Schedule | Filament ScheduleItems create (title, start/end, type); `/events/qa-live/schedule` | item listed, grouped by day | UI |
| S5-02 | Schedule favorite | star item (`POST /schedule/{i}/favorite`), unstar | toggles, persists | DB |
| S5-03 | Schedule .ics | GET `/events/qa-live/schedule.ics` | `text/calendar`, VEVENTs match items; items without an end get an estimated DTEND (tournament 2 h, others 1 h), never DTEND = DTSTART | body |
| S5-04 | Schedule reminders | run `lanomat:send-schedule-reminders` with item starting in 10m, favorited | bell `ScheduleItemStartingSoon`, once only | DB |
| S5-05 | Schedule change notif | edit favorited item time | `ScheduleItemChanged` notification | DB |
| S5-06 | Tournament -> schedule sync | create/edit tournament | corresponding schedule item auto created/updated | DB |
| S5-07 | Catering | FoodOrders admin create order with menu options; participant adds item (`POST /food-orders/{o}/items`), removes (`DELETE`) | totals; closed order rejects; `FoodOrderReady` bell on trigger | UI |
| S5-08 | Voting create | Polls admin create poll with 3 options, closes_at future | listed on `/events/qa-live/polls` | UI |
| S5-09 | Voting vote | p1 votes; p1 votes again; vote after close | one vote per user (change/reject per design), closed rejected | DB |
| S5-10 | Voting realtime | open poll in two sessions, vote in one | results update in other without reload (`PollUpdated`) | screenshot |
| S5-11 | LFG | post (`POST /events/qa-live/lfg`), validation, delete own, delete other's (403), prune command after expiry (`lanomat:prune-lfg`) | as stated | DB |
| S5-12 | Files | upload small file (allowed), disallowed/oversize; unapproved visible only to owner; orga approves in Filament; download (`/files/{id}/download`); delete own/other | moderation gate + quota advisory | UI/DB |
| S5-13 | Gallery | upload JPG with EXIF GPS; pending only visible to owner/orga; orga approves; EXIF stripped (`exiftool` on stored file); zip download; delete | as stated; public route only serves Approved+public-event photos | exiftool, status |
| S5-14 | Gallery public gating | guest GET `/gallery/public/photos/{pendingId}` -> 404; approved on non-draft event -> 200 | | status |
| S5-15 | Presence | `/events/qa-live/presence` with checked-in users | list who is checked in, free tournament slots, "live now" | UI |
| S5-16 | Friends | p1 requests p2 (`/friends`), p2 accepts; p2 requests p1 when pending (auto-accept); remove; block (tears down friendship); blocked user cannot re-request; no "blocked-by" oracle | per memory design | UI/DB |
| S5-17 | Friends suggestions | shared event/team/tournament | suggestions list; no PII leak | UI |
| S5-18 | Stats | `/stats/leaderboard` after S4 tournaments | rows with wins | UI |
| S5-19 | Recap | `/events/qa-finished/recap` ok; `/events/qa-live/recap` -> 404/blocked (not finished) | | status |
| S5-20 | News | NewsPosts create/publish; homepage block; guest view | published only | UI |
| S5-21 | Infoscreen beamer | `/screen/qa-live` as guest (no auth, bare shell); create scenes in Filament (clock, announcement, ...) | cycles scenes; no nav/PII | screenshot |
| S5-22 | Screen control | helper `/screen/qa-live/control`: show-now scene, food-ready trigger, check-in-open trigger (`CheckinOpened` bell), tombola draw, status set; participant -> 403 | beamer updates live; bells created for every recipient; with Discord unreachable the requests still succeed (Discord mirrors are queued; failures land in `failed_jobs`); validation messages use German attribute names | UI, DB |
| S5-23 | Orga ping | participant `POST /events/qa-live/ping-orga` x6 within 1 min | 5 ok (no 500, even with Discord down), 6th 429; orga/helper bell `OrgaPinged` with sane text | status |
| S5-24 | Jukebox | `/events/qa-live/jukebox` with Music Assistant down | page loads with a calm "nicht verfügbar" hint on load (deferred, cached probe); search answers a German error; add/vote/skip only when checked in; one unplayed track per user; no 500 | status |
| S5-25 | Casting overlays | guest GET `/overlay/tournament/{id}/bracket`, `/overlay/match/{id}/scoreboard` | document background transparent (`html.overlay`), render-only, updates live; draft tournament or draft event -> 404 | screenshot |
| S5-26 | Voice setup | `/voice/setup` with no providers; installer download | empty state; installer download with a seeded `VoiceClientInstaller`; a missing file is a 404 with a German message | UI |
| S5-27 | Voice orchestration | tournament start with Mumble down | tournament still starts; no 500; failed job logged | queue logs |
| S5-28 | Discord surface | `GET /discord/voice` (public) returns No-PII JSON; `POST /api/discord/interactions` unsigned -> 401; `POST /internal/discord/gateway` without secret -> 401/403, with secret and bogus body -> 4xx | | status |
| S5-29 | Discord notifications | run `lanomat:send-reminders` and `lanomat:sweep-discord-outbox` with no token | no crash; outbox consistent | stdout |
| S5-30 | GameServers | `/events/qa-live/servers` empty state; `POST /api/telemetry/cs2/{id}` without bearer -> 401, wrong bearer -> 403; `php artisan gameservers:pelican-check` ONLY against a throwaway test panel | empty state; auth as stated; the contract check validates the Pelican client against the real API (never against production) | status |
| S5-31 | Hosts/CustomServers/Lancache | Filament pages load; create host form validation (hostname or IP literal only); no real SSH | "not a host;x" is rejected; pages German with empty states | UI |
| S5-32 | Admin pages full | covered by S3-41 | | |
| S5-33 | Module deps | start with `MODULE_GALLERY=false` while Recap on | app refuses to boot with clear `InvalidModuleConfiguration` message | stderr |
| S5-34 | Module toggle UI | `MODULE_JUKEBOX=false` on full | no Jukebox nav link; route 404 | UI |
| S5-35 | Secondary navigation | full profile, then toggle modules off | sidebar group "Mehr" (Essensbestellung, Abstimmungen, Mitspieler finden, Dateien, Spielserver) gated per module; event overview mirrors them; staff also get "Infoscreen-Steuerung"; vocabulary "Zeitplan"/"Abstimmungen" everywhere | UI |
| S5-36 | Gallery zip | finished event without and with approved photos | button shown only with approved photos; an empty or failed build answers with a German toast, never a 500 | UI, status |
| S5-37 | Presets (PLANNED, not merged yet) | game server presets with egg reference, port pool, install verification, start-from-preset | planned: define cases when the feature lands (see feature-inventory "Planned") | - |

## S6. Realtime / Reverb

Pre: Reverb up on :8081, `BROADCAST_CONNECTION=reverb`, queue worker running, two browser sessions (localhost + 127.0.0.1 with origin allowed).

| ID | Area | Steps | Expected | Verify |
|----|------|-------|----------|--------|
| S6-01 | WS connect | open `/tournaments/{id}` | websocket to `ws://localhost:8081` established, subscribed to `tournament.{id}` | `read_network_requests` / console |
| S6-02 | Start broadcast | session B watching; orga starts tournament | B bracket appears without reload (TournamentStarted) | screenshot |
| S6-03 | Match result broadcast | report+confirm in A | B updates match and winner advance live (MatchReportUpdated on submit/dispute, MatchCompleted/MatchReady on confirm) | screenshot |
| S6-04 | Completion broadcast | final match | B shows finished state | screenshot |
| S6-05 | Reconnect | stop Reverb for ~20-40 s while a match completes, restart; also hide/show the tab | client reconnects and RESYNCS the live props without reload (also when a hidden tab becomes visible, throttled); a calm "Verbindung unterbrochen" indicator shows only while the socket is down; broadcasts retry (3 tries) across a short restart; no console error loop | console, screenshot |
| S6-06 | Origin lock | WS handshake with disallowed Origin (`wscat`/JS from other origin) | rejected | result |
| S6-07 | Channel auth | subscribe to `private-App.Models.User.{otherId}` from p1 via `/broadcasting/auth` | 403 | status |
| S6-08 | Presence/poll live (full) | covered in S5-10 and presence page with check-in in other session | updates live | screenshot |
| S6-09 | Realtime smoke script | `node scripts/smoke/realtime-smoke.mjs` (see `scripts/smoke/README.md`) | passes | exit code |

## S7. Responsive / mobile and accessibility basics

Method: `resize_window` to 390x844 (and 768x1024, 1440x900), or iframe-embed the page in a 390px-wide container via `javascript_tool` if resize is refused. Check no horizontal scroll (`document.documentElement.scrollWidth <= innerWidth`).

| ID | Page | Check | Verify |
|----|------|-------|--------|
| S7-01 | `/` and `/login` at 390px | no overflow, CTA reachable (the local-only dev-login row may overflow; production shows the Discord button only) | scrollWidth + screenshot |
| S7-02 | `/dashboard` 390px | cards stack; nav collapses to menu/sheet that opens and closes | screenshot |
| S7-03 | `/events/qa-reg/register` 390px | form usable; QR visible and not clipped | screenshot |
| S7-04 | `/events/qa-live/seating` 390px | SVG grid scrolls/zooms; seat tap targets >= 40px (coarse pointer) | screenshot |
| S7-05 | `/tournaments/{id}` 390px | bracket horizontally scrollable inside its container, page itself no overflow | scrollWidth |
| S7-06 | `/checkin/{token}` 390px | primary button full width, readable | screenshot |
| S7-07 | `/admin` 390px | Filament sidebar collapses; tables scroll | screenshot |
| S7-08 | Keyboard focus | tab through login, register, settings forms: visible focus ring on every control, logical order, no traps | screenshot after each Tab |
| S7-09 | Form labels | `read_page` accessibility tree on register/profile/team/food/jukebox forms: every input has an accessible name (also search/note fields), a `<nav>` landmark exists, file inputs use the German shared picker | tree |
| S7-10 | Contrast spot checks | light + dark: body text, muted text, amber accent buttons, status badges: AA (>= 4.5:1 text) via computed styles or screenshot sampling | numbers |
| S7-11 | Reduced motion | emulate via JS `matchMedia` check/CSS or note: animations disabled/minimal under `prefers-reduced-motion` | note |
| S7-12 | Images | team logo/avatars: `alt`, width/height, `loading=lazy` | DOM |
| S7-13 | Language/titles | `<html lang>`, unique `<title>` per page | DOM |
| S7-14 | Dark mode persistence | toggle theme, hard reload, no flash of wrong theme | screenshot |
| S7-15 | German chrome | sidebar toggle sr-only text, toast region label, file pickers: no English leftovers regardless of browser locale | DOM |

## S8. Production smoke (READ-ONLY, `<APP_URL>`)

Allowed: GET/HEAD requests, browser viewing as the already logged-in admin, read-only commands on `<APP_SERVER>` via `ssh <ssh-user>@<APP_SERVER>`. Forbidden: any POST/PATCH/DELETE, clicking panel actions that save/transition/rotate/delete (viewing lists and edit pages is fine if you do not press Save), seeding, `lanomat:install`, restarting or recreating containers, editing files. In doubt: do not click; write an open question.

| ID | Area | Steps | Expected | Verify |
|----|------|-------|----------|--------|
| S8-01 | TLS | `curl -sIv https://<APP_HOST>`; `openssl s_client -connect <APP_HOST>:443 -servername <APP_HOST>` piped to `openssl x509 -noout -dates -issuer` | valid public certificate (not a default/self-signed proxy certificate), comfortable remaining validity (> 14 days), TLS 1.2 or newer | output |
| S8-02 | HTTP -> HTTPS | `curl -sI http://<APP_HOST>/` | permanent redirect to https | Location |
| S8-03 | Security headers | `curl -sI https://<APP_URL>/` | HSTS (long max-age, includeSubDomains), `x-frame-options`, `x-content-type-options`, `referrer-policy`, `permissions-policy`, report-only CSP + `reporting-endpoints`; no `x-powered-by` version leak | headers |
| S8-04 | Guest landing | `curl -s <APP_URL>/` | 200, landing page, no event data | text |
| S8-05 | Guest redirects | `curl -sI` `/dashboard /events /events/<slug> /settings/profile /admin /orga/events/x/checkin /checkin/xxx` | 302 to `/login` | status |
| S8-06 | 404s | `/nonexistent`, `/dev/login/participant` (GET), `/screen/x`, `/jukebox`, `/friends`, `/discord/voice` | 404 (module off) or 405; German error page; no stack trace, no debug page | status + body |
| S8-07 | Health | `curl -si <APP_URL>/up` | 200 | status |
| S8-08 | Login page | browser guest `/login`; `curl -sI <APP_URL>/auth/discord/redirect` | Discord button only, no dev-login buttons (Inertia prop `devLoginEnabled:false`); redirect to the Discord authorize URL with `redirect_uri=<APP_URL>/auth/discord/callback` and the guild-membership scope when a login guild is configured (do NOT follow it) | Location |
| S8-09 | Public data exposure | `/teams` and one `/users/{id}` as guest | no e-mail, no internal data beyond URLs | text |
| S8-10 | Admin browser (logged in) | open `/dashboard`, `/events`, the current event page, `/admin`, `/admin/events`, an event edit page (do not save), `/admin/users`, `/admin/seats`, `/admin/tournaments`, `/admin/games`, `/admin/teams` | all 200, no console errors, German copy, widgets render | screenshots + console |
| S8-11 | Preflight widget | `/admin` | no red rows for the enabled modules (do not press "Neu prüfen") | screenshot |
| S8-12 | Check-in QR base URL | read the token row (`select token from event_checkin_tokens`) through the application console; do NOT open the QR modal if no token exists (it would create one: a write) | when a token exists the printed URL starts with `<APP_URL>/checkin/`; otherwise BLOCKED until first real use | output |
| S8-13 | Arrival overview | `/admin/events/{id}/arrival` (read) | renders, no errors | screenshot |
| S8-14 | Event page as admin | current event page and register page (view only) | status/availability consistent with the admin edit page | text |
| S8-15 | Module profile | `docker compose --profile prod exec -T app php artisan lanomat:modules` in `<DEPLOY_DIR>` | intended profile and module set | stdout |
| S8-16 | Preflight CLI | `... exec -T app php artisan lanomat:preflight` | no red rows, exit 0 | stdout |
| S8-17 | Containers | `docker compose --profile prod ps` | traefik/proxy, app (healthy), queue, scheduler, reverb, postgres, redis all up; no restart loop | stdout |
| S8-18 | Logs | `docker compose --profile prod logs --since 24h app queue scheduler reverb` filtered for the words error, exception, critical and failed | none unexplained (list any with counts) | stdout |
| S8-19 | Failed jobs | `... exec -T app php artisan queue:failed` | empty | stdout |
| S8-20 | Scheduler | `... exec -T app php artisan schedule:list`; scheduler log of the last minutes | scheduled commands listed (tournament tick, heartbeat, health watch, token refresh); heartbeat fresh | stdout |
| S8-21 | Backups | list the backup directory `<BACKUP_DIR>` and the cron definition | database dump and storage archive jobs present; newest dump is fresh (< 26 h) and non-trivial in size; retention working; the backup preflight row is green | stdout |
| S8-22 | Backup validity | `gzip -t` the newest dump and read its first lines; verify the storage archive lists | valid archive, SQL header | stdout |
| S8-23 | SSH hardening | verify the SSH daemon configuration through the read-only effective-config query | password SSH login is disabled; key authentication only | stdout |
| S8-24 | Exposure | compare the host's listening sockets with the intended public set; probe the internal service ports from outside | only the intended ports are reachable from outside; internal services (application server, websocket server, database, cache) are not; host firewall active | output |
| S8-25 | Reverb/WSS | browser console on a page with live channels; `curl -sI https://ws.<APP_HOST>` | secure websocket connects; the websocket host answers over TLS (a plain GET may legitimately 404) | network |
| S8-26 | .env sanity | print only the non-secret keys (`APP_ENV`, `APP_DEBUG`, `MODULES_PROFILE`, `LOG_LEVEL`, `APP_URL`, `APP_DISPLAY_TIMEZONE`) | production, debug false, intended profile, `warning`, https URL (mask everything else) | stdout |
| S8-27 | Deploy state | in `<DEPLOY_DIR>`: `git rev-parse --short HEAD`, `git status --short`, pending migrations | equals `origin/main`; clean; none pending | stdout |
| S8-28 | Disk/memory | `df -h /; docker system df; free -m` | > 20% disk free | stdout |
| S8-29 | Updates/uptime | `uptime`; reboot-required marker | note only | stdout |
| S8-30 | DNS | resolve the application host and the websocket host | both resolve to the application server | stdout |
| S8-31 | Prod data sanity | read-only counts through the application console (events, users); count of demo/QA identities | plausible counts; zero `QA-`/demo/`example.test` accounts or events | stdout |
| S8-32 | Prod interactive tests | everything needing writes (register, check-in, tournament run, profile save, lifecycle) | NOT executed here; see S8-W | |

### S8-W. Production write test (throwaway event, optional)

Only when the owner approves it for this specific run (approval is per run, not standing). Purpose: verify the production write path (register, QR check-in, lifecycle, optionally one small tournament) that is otherwise tested only locally.

1. **Baseline first:** record row counts (events, users, registrations, tournaments, entries, matches, notifications, seat assignments, check-in tokens) and the current backup state. Take or confirm a fresh backup.
2. Create ONE clearly named throwaway event (`QA-Prod-Smoke`, a name no real event can share) and use only it. Use only dedicated test accounts or the owner's own account; never touch real participants' data.
3. Run the minimal write path: Announce -> open registration -> register -> self check-in/scan -> (optional) a 2-entry tournament through completion -> finish.
4. **Cleanup is mandatory:** delete the throwaway event and everything hanging off it (registrations, tournaments, entries, matches, notifications created by the test, check-in token), then re-count and compare with the baseline. Any difference is a finding.
5. Report the result without echoing production values (counts and PASS/FAIL only).

## S9. Failure and edge cases (local, core unless noted)

| ID | Area | Steps | Expected | Verify |
|----|------|-------|----------|--------|
| S9-01 | Invalid ids | LOGIN p1; GET `/tournaments/999999`, `/teams/999999`, `/users/999999`, `/events/no-such-slug`, `/matches/999999/report` (POST) | 404 | status |
| S9-02 | Non-numeric ids | `/tournaments/abc`, `/teams/abc`, `/users/abc`, `/users/1.5`, `/users/99999999999999999999`; POST `/matches/abc/report`, `/events/qa-reg/seating/abc` | 404 (never 500), also for guests; notification ids are UUIDs | status |
| S9-03 | Mass assignment | PATCH `/settings/profile` with extra `role=admin`, `discord_id`, `password` fields | ignored; role unchanged | DB |
| S9-04 | Role escalation via teams | POST `/teams` with `role` field; PATCH team with `owner_id` of another user | ignored/403 | DB |
| S9-05 | IDOR: registration | p2 sends `DELETE /events/qa-reg/register` (own) fine; attempt to cancel p1's by crafting? (route has no user param) | cannot affect others | DB |
| S9-06 | IDOR: notifications | p2 `POST /notifications/{p1NotificationId}/read` | 403/404; p1's stays unread | DB |
| S9-07 | IDOR: seats | p2 `DELETE /events/qa-reg/seating` | only own seat released | DB |
| S9-08 | IDOR: matches | p5 (uninvolved) report/confirm/dispute/go | 403 | status |
| S9-09 | IDOR: team requests | non-owner `POST /teams/{t}/requests/{r}` | 403 | status |
| S9-10 | IDOR: check-in token | participant without orga GET `/orga/events/{slug}/checkin-qr` | 403 | status |
| S9-11 | Double submit | double-click Register / Enroll / Report (rapid duplicate fetch x2) | exactly one row each; second -> handled error | DB |
| S9-12 | Concurrent last seat | two sessions claim the same seat simultaneously | one wins; other error | DB |
| S9-13 | Concurrent last spot | qa-small with 1 free spot; two users register in parallel (two `fetch` in one `Promise.all` from two sessions or `curl &`) | exactly 2 registrations total | DB count |
| S9-14 | Cancelled registration | cancelled user: self check-in token page, claim seat, tournament check-in | refused / "not registered" paths | UI |
| S9-15 | Cancelled + seat | cancel registration holding a seat | seat freed (or documented) | DB |
| S9-16 | Event state transitions affect pages | move qa-reg to Finished: register -> closed; arrival update -> 403; self check-in -> "over" | | status |
| S9-17 | Arrival update after finish | PATCH arrival on finished/archived event | 403 | status |
| S9-18 | Rotated token | use old token after rotate (S3-14) | 404 generic | status |
| S9-19 | Self check-in throttle | 31 GETs within a minute to `/checkin/{token}` as one user | 31st returns 429 | status |
| S9-20 | Login throttle | S1-15 | 429 | status |
| S9-21 | Password update throttle | PUT `/settings/password` x7 | 7th 429 | status |
| S9-22 | Orga ping throttle (full) | S5-23 | 429 | status |
| S9-23 | Oversize/odd input | 10k-char strings into team name, bio; report scores `1e9`, `2147483648`; unicode/emoji, NUL, HTML `<script>` | validation (422) or safe escaped output; scores bounded; no XSS; no 500 | DOM, console |
| S9-24 | XSS stored | set profile name `<img src=x onerror=alert(1)>`; view on dashboard, team page, bracket, bell, admin lists | rendered as text everywhere | DOM |
| S9-25 | Open redirect | `/login?intended=https://evil.example` / `intended` session abuse; `/checkin` login return | no off-site redirect | Location |
| S9-26 | Expired session | delete session cookie mid-flow then submit a form | redirect to login (or 419 handled by Inertia modal), no raw error | UI |
| S9-27 | Method not allowed | GET on POST-only routes (`/events/qa-reg/register` is GET ok; `/matches/1/report` GET) | 405 | status |
| S9-28 | Queue down | stop the queue worker, register for the event, then restart the worker | request still succeeds; queued notifications are delivered after restart; preflight shows the queue worker red meanwhile | UI, preflight |
| S9-29 | Redis down | stop the Redis container briefly | login and self check-in still work (rate limiters live on the database); landing/dashboard/register OK; record anything that 500s; restart afterwards | status |
| S9-30 | DB constraint races | create two events with same name -> slugs unique | `-2` suffix or validation | DB |
| S9-31 | Time zone | event starts_at display vs admin input vs DB (UTC) | one display/input zone (`APP_DISPLAY_TIMEZONE`, default Europe/Berlin) in Vue, Filament, arrival days and notification times; DB stays UTC; an event starting 00:30 Berlin still offers its first day | UI vs DB |
| S9-32 | Large field | 64-entry SE and 64-entry DE start (tinker enroll) | completes within a few seconds, bracket renders and scrolls | timing, screenshot |

