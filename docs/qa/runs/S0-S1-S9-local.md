# QA run: S0 + S1 + S9 (local, core profile)

Round 1 (before any fix). Profile `core`, local, throwaway database, `php artisan serve` with several workers, queue worker NOT running (state needed for S9-28), shell-variable overrides for the database. HTTP checks used curl cookie jars (login via the Fortify endpoint), browser checks used a separate tab on `localhost` and `127.0.0.1`. Not done in this round: repeating the key S1 rows under `full`, S9-22 (full only), S9-26 in the browser UI. Status of every finding below: fixed in PR #43 or accepted (see `../findings.md`).

## Findings

| ID | Severity | Summary |
|----|----------|---------|
| F-S9-1 | Important | Non-numeric route ids return HTTP 500 instead of 404 (also for guests) |
| F-S9-2 | Important | Double-click / stale confirm of a match result returns 500 (StaleMatchException not caught) |
| F-S9-3 | Minor | Score above int4 max (`2147483648`) in match report returns 500 (no `max` rule) |
| F-S9-4 | Minor | Framework validation messages are English although locale is `de` (no `lang/de/validation.php`); mixed-language errors |
| F-S9-5 | Minor | `stream_url` accepts `http://`, `ftp://`, `data://` URLs (docblock says https only); rendered as `href` in full-profile Presence page |
| F-S9-6 | Minor | Redis down: `POST /login` and `GET /checkin/{token}` return 500 (rate limiter on redis cache); landing/dashboard/register still OK |
| F-S9-7 | Minor | Default Laravel English "Not Found" error page (unstyled, `lang=en`), not the app design / German |
| F-S9-8 | Minor | App timezone is UTC: arrival-day list and Filament inputs are in UTC, wrong around local midnight for German events |
| F-S1-1 | Minor | `X-Powered-By: PHP/8.5.12` and no app-level security headers (X-Frame-Options, nosniff, CSP, Referrer-Policy) locally; prod proxy must supply them (S8 to verify) |
| F-S1-2 | Minor | Plan deviation: guest `/admin*` redirects to `/login` (not `/admin/login`); probably intended (Discord-only), plan text outdated |
| F-S0-1 | Minor (INFO) | `npm run types:check` failed once (TS6053 `resources/js/routes/boost/index.ts` not found) when run concurrently with pest; passes on rerun (generated file race) |
| F-S9-9 | Minor (INFO) | Local `compose.yml` `reverb` service (php:8.4-cli) crashes at start: undefined `SIGINT` (no pcntl). S6 needs Reverb started on host: `php artisan reverb:start --port=8081` |

### F-S9-1 Non-numeric ids give 500 (Important)
Repro (guest or logged in): `GET /users/abc`, `/teams/abc`, `/teams/abc/edit`, `/tournaments/abc`, `/users/1.5`, `/users/99999999999999999999`; `POST /matches/abc/report`, `/notifications/abc/read`, `/events/qa-reg/seating/abc`, `/teams/abc/requests/abc`, `/tournaments/abc/checkin`.
Expected 404. Actual 500 `SQLSTATE[22P02] invalid input syntax for type bigint: "abc"` (Postgres). Cause: routes `routes/web.php:47,50,66,82,83,84-87,91` bind `{user}/{team}/{tournament}/{match}/{notification?}` without `->whereNumber()` / global `Route::pattern`. Public (unauthenticated) 500 trigger, log noise / alert noise in prod. (`/users/-1` correctly 404.)

### F-S9-2 Stale confirm gives 500 (Important)
Repro: p4 reports match 2 (3:1), the opponent sends `POST /matches/2/confirm {"lock_version":0}` 3x in parallel (double click), or once with wrong value (`lock_version:99`).
Expected: handled toast ("already updated, reload"). Actual: 500 `StaleMatchException: This match was already updated by someone else`. Cause: `ConfirmMatchReport.php:87` throws `StaleMatchException extends DomainException`; `TournamentPageController::confirm` (`:216-240`) only catches `TournamentException`. One confirm wins and the match completes correctly (data integrity OK), the others 500.

### F-S9-3 Oversize score 500 (Minor)
`POST /matches/2/report {"score1":2147483648,"score2":1}` as a match participant -> 500 `SQLSTATE[22003] out of range for type integer`. `SubmitReportRequest.php:22-24` has no `max`. (1e9 is accepted; 1e20 correctly 422.) Also note scores like 1000000000 are accepted as valid results.

### F-S9-4 English validation messages (Minor)
`PATCH /settings/profile` with bad data -> "The name field is required.", "The steam url field must be a valid URL.", arrival: "The arrival day field must match the format Y-m-d.", "The selected arrival window is invalid.", "The score1 field must be at least 0." `.env` has `APP_LOCALE=de`, but `lang/` contains no `validation.php`/auth/passwords (only module files). Custom messages are German, so the UI mixes languages.

### F-S9-5 stream_url scheme (Minor)
`PATCH /settings/profile` `stream_url`: `http://x.y`, `ftp://x.y/z`, `data://text/html,x` all saved (200/302). `javascript:` and `file://` rejected. `ProfileValidationRules.php:84` uses only `url`; comment says https. Rendered as `:href="participant.streamUrl"` in `resources/js/pages/Presence/Index.vue:280` (full profile only). Suggest `url:https` / `starts_with:https://`.

### F-S9-6 Redis down (Minor)
Stop the Redis container: `/`, `/events`, `/dashboard`, `/events/qa-reg/register`, register POST (302) and `/up` stay 200/302 (fast). But `POST /login` (throttle) -> 500 and `GET /checkin/{token}` (throttle:self-checkin) -> 500. Sessions are DB-backed so existing sessions keep working. Expected per plan: degrade; a 500 on login/check-in during a Redis outage is a real LAN-day risk (consider `database`/`array` fallback for the limiter or health note in preflight).

### F-S9-7 404 page (Minor)
`GET /nonexistent` (guest and user): default Laravel "Not Found" page, English, unstyled, vs. German Signalpult UI. No stack trace (OK).

### F-S9-8 Timezone (Minor, see OQ-N2)
`config/app.php:68` `'timezone' => 'UTC'`; `EventDays::between` uses app tz, so an event ending Sunday 00:30 CET (Sat 23:30 UTC) gets no Sunday in arrival/departure options; Inertia times are ISO UTC and rendered in browser tz (consistent for display), but Filament input is interpreted as UTC (not verified in the browser).

### F-S1-1 Headers
`curl -sI http://localhost:8000/`: `X-Powered-By: PHP/8.5.12`, `Cache-Control: no-cache, private`, cookies `XSRF-TOKEN` (not HttpOnly, as intended) and `lanomat-session` (HttpOnly, SameSite=Lax; no `Secure` locally as expected). No X-Frame-Options / X-Content-Type-Options / CSP / Referrer-Policy / HSTS from the app. Must be provided by Traefik in prod: verify in S8.

## S0 Automated gate

| ID | Result | Evidence |
|----|--------|----------|
| S0-01 | PASS | `composer check`: pint passed, phpstan level 8 0 errors, pest 1918 passed (14985 assertions, 2 risky, 142s) |
| S0-02 | PASS (F-S0-1) | lint:check 0, format:check 0, build 0 (2.3s); types:check failed once concurrently (TS6053 boost route file missing), rerun alone: exit 0 |
| S0-03 | PASS | core: events, games, identity, notifications, preflight, registration, seating, teams, tournaments (9); full = 29 enabled, 0 disabled |
| S0-04 | PASS | core `route:list --except-vendor`: 67 routes; none of /screen /jukebox /friends /discord /overlay /stats /schedule. Note `POST dev/login/{role}` is in the list (local only, gated) |

## S1 Auth, guest, headers

| ID | Result | Evidence |
|----|--------|----------|
| S1-01 | PASS | browser `/`: "Die LANoMAT-Party-Zentrale", only "Mit Discord anmelden", no event data |
| S1-02 | PASS | `data-test`: login-discord-button, dev-login-participant, dev-login-orga |
| S1-03 | N/A | S8 |
| S1-04 | PASS | all 11 URLs 302 -> /login |
| S1-05 | PASS | all 4 guest POSTs 419 (CSRF first) |
| S1-06 | PASS (F-S1-2) | /admin, /admin/events, /admin/users 302 -> `/login` (not `/admin/login`) |
| S1-07 | PASS | both 302 /login |
| S1-08 | PASS | /teams, /users/{id}, /up, /.well-known/passkey-endpoints 200 for guest. /users/5 exposes only: name, avatarUrl, bio, steamUrl, profileColor, userId; no email/role/discord_id. Sequential ids enumerable (user 1 "Test User" visible) |
| S1-09 | PASS | `/dev/login/admin` 404, `/dev/login/xyz` 404 (participant 302) |
| S1-10 | PASS | click dev-login-participant -> `/`, user Demo-Teilnehmer, role participant |
| S1-11 | PASS | p1: /admin 403, checkin 403, checkin-qr 403 |
| S1-12 | PASS | helper: checkin 200, checkin-qr 403, /admin 403 |
| S1-13 | PASS | orga and admin: /admin, checkin, checkin-qr all 200 |
| S1-14 | PASS | all 4 draft URLs 404 |
| S1-15 | PASS | 422 x5 then 429 x2 |
| S1-16 | PASS | no token / bad token POST: 419 |
| S1-17 | PASS (F-S1-1) | headers listed above |
| S1-18 | FAIL (F-S9-7) | 404 works, no trace, but default English unstyled page |
| S1-19 | PASS | `Accept: application/json` -> 401 `{"message":"Unauthenticated."}` |
| S1-20 | PASS | all 7 module-off URLs 404 for p1; browser nav links only: /dashboard /events /teams /events/qa-live{,/register,/tournaments,/seating} |
| S1-21 | BLOCKED (OQ-01) | Discord OAuth manual; Pest identity tests green in S0-01 |
| S1-22 | PASS | `?error=access_denied` -> /login, flash "Die Anmeldung mit Discord wurde abgebrochen."; `?code=x&state=bad` -> /login "Die Discord-Anmeldung ist abgelaufen. Bitte versuche es erneut." (302, no 500) |
| S1-23 | PASS | guest 302 to discord.com/api/oauth2/authorize with client_id, redirect_uri=.../auth/discord/callback, scope identify+email, state; logged-in -> /dashboard |
| S1-24 | PASS | POST /logout 204; /dashboard then 302 /login |
| S1-25 | PASS | session identifier changes across login |

## S9 Failure and edge cases

| ID | Result | Evidence |
|----|--------|----------|
| S9-01 | PASS | all numeric-missing ids 404 (tournaments, teams, users, event slug, matches report/confirm, enroll, team join) |
| S9-02 | FAIL (F-S9-1) | `/tournaments/abc`, `/teams/abc`, `/users/abc` 500 |
| S9-03 | PASS | PATCH /settings/profile with role=admin, discord_id, password, id, avatar_url, email_verified_at (JSON and form): role stays participant, discord_id/password hash unchanged |
| S9-04 | PASS | POST /teams with role/owner_id/user_id: owner = caller; non-owner PATCH /teams/1 403; owner PATCH with owner_id=6 ignored |
| S9-05 | PASS | DELETE register has no user param; each user only cancels own (p1 cancel did not affect p3/p2) |
| S9-06 | PASS | other user marking p1's notification: 404, stays unread; owner 302 and read |
| S9-07 | PASS | unregistered user claim/delete seat 403; DELETE seating by p2 only removed p2's assignment |
| S9-08 | PASS | uninvolved p5 and wrong-match user: report 403, go 403, confirm 403, dispute 403 (with valid lock_version). Note confirm without lock_version gives 422 before authz (harmless) |
| S9-09 | PASS | non-owner accept 403; owner of ANOTHER team using `/teams/2/requests/1` 404 (scoped); owner accepts OK |
| S9-10 | PASS | participant GET checkin-qr 403 (S1-11) |
| S9-11 | PASS | 3 parallel registers -> 1 row, 1 notification; 2 parallel enroll -> 1 entry; 3 parallel self check-in -> checked in once; report: one match_report row (F-S9-2 for confirm) |
| S9-12 | PASS | two users POST same seat in parallel: exactly one `seat_assignments` row; loser got 302 (no seat) |
| S9-13 | PASS | qa-small (max 2): 4 parallel register attempts (3 valid) -> exactly 2 confirmed rows |
| S9-14 | PASS / observation | cancelled user: self check-in page state `not_registered`, POST does nothing, seat claim 403. Observation: cancelled registration keeps `checked_in_at`; tournament check-in works for users WITHOUT event registration (see OQ-07, confirmed: user without registration enrolled in QA-SE Solo and checked in to QA-DE Solo) |
| S9-15 | PASS | cancelling a seat-holder frees the seat (assignment row removed); re-register after cancel reuses the row |
| S9-16 | PASS | finished event: register 403 (availability closed), self check-in state `over` and POST ignored. Observation: DELETE register on a finished event is allowed (cancelled) |
| S9-17 | PASS | arrival PATCH on finished event 403 |
| S9-18 | PASS | after `RotateEventCheckinToken`, old token GET and POST 404 |
| S9-19 | PASS | 30 GETs 200, 31st-33rd 429 (limit 30/min per user); other user unaffected |
| S9-20 | PASS | login 429 after 5 |
| S9-21 | PASS | PUT /settings/password: 6x 422, 7th 429 |
| S9-22 | N/A | full only |
| S9-23 | PASS (F-S9-3) | 10k chars: team name max 64 and profile name max 255 (422), bio max 1000; scores: tie 422, negative 422, "1e9"/"abc"/1.5 422, 1e20 422; 2147483648 -> 500 (F-S9-3); emoji/unicode saved; NUL char in team name accepted without 500; tokens tampered (suffix, truncated, uppercase, SQLi, traversal) 404 |
| S9-24 | PASS | name `<img src=x onerror=...>`, bio `<script>`, team `<svg onload=...>`: rendered as text on dashboard, sidebar user menu, /teams/1, /users/5; no handler executed (`window.__xss*` unset, no console errors); Filament /admin/teams and /admin/users/5 HTML escaped (`&lt;`). Bell notification and bracket not exercised with payload |
| S9-25 | PASS | `/login?intended=https://evil...`, `redirect`/`intended` fields in POST /login, `/auth/discord/redirect?redirect=...`: no off-site Location |
| S9-26 | PARTIAL | no cookie POST -> 419 JSON; garbage session cookie GET /dashboard -> 302 login. Browser UI modal not checked |
| S9-27 | PASS | GET on /matches/1/report, /tournaments/1/enroll, /teams/1/join and PUT register: 405 |
| S9-28 | PASS / partial | worker was down the whole time: register POST succeeded (302), notification row written (database channel, not queued); `lanomat:preflight` shows Queue-Worker DOWN, Scheduler DOWN, Reverb DOWN. Delivery-after-restart of queued items not verified |
| S9-29 | FAIL (F-S9-6) | see finding |
| S9-30 | PASS | two events "QA-Dup" -> slugs `qa-dup`, `qa-dup-2` |
| S9-31 | PASS / FAIL(F-S9-8 minor) | consistent UTC storage and ISO UTC to browser; see F-S9-8 |
| S9-32 | PASS | 64-entry SE (63 matches) started in 0.08s, 64-entry DE (127 matches) in 0.14s; page render 0.09s; DE bracket renders in browser, no console errors |

Extra observations (no ID): participants have no route to withdraw from a tournament (`WithdrawEntry` only used from Filament relation manager); `auth.user` Inertia share contains discord_id/email but only for the own user; `POST /dev/login/participant` while logged in as another user silently switches identity (local only).

## Open questions raised

All questions raised by this round (OQ-N1 to OQ-N8) are answered; see [`../open-questions.md`](../open-questions.md).
