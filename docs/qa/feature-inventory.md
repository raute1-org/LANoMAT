# LANoMAT feature inventory (QA)

What exists on `main`, per area and profile; the basis for the cases in [`test-plan.md`](test-plan.md). Derived from `routes/*.php`, `config/modules.php`, `app/Support/Modules/Module.php`, the Filament resources, console commands and policies; the expected behaviours follow [`rulings.md`](rulings.md). Verify the active module set of a deployment with `php artisan lanomat:modules` (`--json`).

Legend: **P** = active in the production `core` profile, **F** = `full` profile only (404 / hidden under `core`), **planned** = not merged yet.

## 0. Profiles

| Item | Value |
|------|-------|
| Production | `MODULES_PROFILE=core`, `APP_ENV=production`, `APP_URL=<APP_URL>`, Reverb behind its own TLS host (`wss://ws.<APP_HOST>`), display timezone `APP_DISPLAY_TIMEZONE` (default Europe/Berlin), storage and cache timestamps in UTC |
| Core (always on) | Events, Registration, Seating, Teams, Games, Tournaments, Identity, Notifications, Preflight |
| Full-only | Casting, Catering, CustomServers, Discord, Files, Friends, Gallery, GameServers, Hosts, Infoscreen, Jukebox, Lancache, Lfg, News, Presence, Recap, Schedule, Stats, Voice, Voting |
| Hard dependencies | CustomServers/Lancache -> Hosts; Recap -> Gallery, Jukebox, Voting, Presence; News -> Gallery; Gallery -> Files; Stats -> Voting; Friends -> Presence. An invalid toggle combination refuses to boot with an `InvalidModuleConfiguration` message |
| Local default | `MODULES_PROFILE=full`, `APP_ENV=local` (dev-login buttons), dev Postgres/Redis on non-default host ports |

Roles: guest < participant < helper < orga < admin (`Role` enum; `isHelper()` includes orga/admin; `isOrga()` includes admin). Panel access (`canAccessPanel`) is orga and above. There is no UI to change roles (assigned through `lanomat:install --admin-discord-id=<ADMIN_DISCORD_ID>`; the admin Users resource is read-only).

## 1. Auth, identity, guest rules (P)

| Feature | Route | Who | Rules |
|---------|-------|-----|-------|
| Guest landing | `GET /` | guest, all | event-free landing for guests; the participant home shows the current event and published news (F) |
| Login page | `GET /login` | guest | Discord button; dev-login buttons only with `APP_ENV=local` |
| Discord OAuth | `GET /auth/discord/redirect`, `/auth/discord/callback` | guest | optional guild-membership gate (`DISCORD_LOGIN_GUILD_ID`) fails closed (non-member, Discord down, no token); admins are exempt; `error` param and bad state return to `/login` with a German status, never 500 |
| Dev login | `POST /dev/login/{participant\|orga}` | any | 404 unless `APP_ENV=local`; unknown role 404 |
| Password login (Fortify) | `POST /login` | guest | throttle 5/min per email+ip; Discord users have no password; registration/reset/e-mail verification are disabled |
| 2FA / passkeys / password | `/settings/security`, `PUT /settings/password` | auth + password | passwordless (Discord-only) users are redirected away; password throttle 6/min; German failure messages |
| Logout | `POST /logout` | auth | session ends |
| Role gate | `role:helper` / `role:orga` middleware | | guest -> `/login`; wrong role -> German 403 page (admin link only for orga/admin) |
| Health | `GET /up` | public | |
| Error pages | 403, 404, 419, 429, 500, 503 | all | German Signalpult pages, no stack trace; JSON clients (`Accept: application/json`) get JSON errors |
| Guest `/admin*` | | guest | redirects to `/login` (there is no separate admin login) |

## 2. Dashboard, events, profile, shell (P)

| Feature | Route | Who | Notes |
|---------|-------|-----|-------|
| Unified shell | all authenticated pages | auth | one sidebar/header on every page, current-event group, user menu (admin link for orga+), page layout rules per `docs/design.md`; admin panel mirrors it (admin marker, "Zur App" back link) |
| Dashboard | `GET /dashboard` | auth | current event, own registration, seat, team(s), next steps |
| Events | `GET /events`, `GET /events/{slug}` | auth | "Aktuell und kommend" section (announced, registration, live) above the archive; Draft events are 404 for everyone but orga |
| Public profile | `GET /users/{user}` | **public** | exposes name, avatar, bio, profile colour, stream/Steam links only; never e-mail/role/Discord id |
| Profile edit / delete | `GET/PATCH/DELETE /settings/profile` | auth | `stream_url` https only; German validation messages; mass-assignment of role/discord id ignored |
| Appearance | `/settings/appearance` | auth | light/dark/system, server-rendered (no flash) |
| Connections | `/settings/connections`, `.../{provider}/redirect\|callback`, `DELETE .../{provider}` | auth | only configured providers offered (Steam OpenID, Twitch OAuth2); unconfigured/unknown provider 404; existing links stay listable/unlinkable |
| Notifications bell | `POST /notifications/{uuid}/read` | auth | list + unread count are shared props on every response (also after redirects); category preferences |
| Timezone | everywhere | | one display/input zone (`config('lanomat.timezone')`) over UTC storage: Vue dates, Filament pickers/columns, arrival days, notification times |

## 3. Events lifecycle (P, admin)

| Feature | Where | Rules |
|---------|-------|-------|
| Event CRUD | `/admin/events` | orga+; end after start, capacity integer >= 1 or empty (= unlimited); slug generated and unique |
| Lifecycle | EditEvent header actions | strictly linear Draft -> Announced -> Registration -> Live -> Finished -> Archived; header refreshes after each transition; opening registration sends the in-app "Anmeldung offen" bell (core listener) to every user without an active registration, minus those who disabled the category |
| Check-in QR | modal, print page `/orga/events/{slug}/checkin-qr`, rotate | URL built from `APP_URL`; rotating invalidates the old token (404) |
| Arrival overview | `/admin/events/{id}/arrival` | tiles, per-day/window chart, expected-present chart with peak, filterable table; orga+ |
| Registrations manager | relation manager on the event | ticket label, arrival plan, status filter, "Stornieren" action (modal names the participant), CSV export |
| Ticket types | `event.settings.tickets` | empty means a single `standard` ticket; no admin form field (accepted, DB/tinker only) |

## 4. Registration, ticket, self check-in, arrival plan (P)

| Feature | Route | Who | Rules |
|---------|-------|-----|-------|
| Register page | `GET /events/{slug}/register` | auth | availability Upcoming/Open/Full/Closed; ticket QR when registered |
| Register | `POST /events/{slug}/register` | auth | possible in `registration` and in `live` while spots remain (walk-in); capacity row-locked; ticket type from the allowed set; arrival plan validated |
| Arrival plan | `PATCH /events/{slug}/register/arrival` | owner | own non-cancelled registration; not on finished/archived events; empty plan = "weiss ich noch nicht"; departure-only counts as unknown (`docs/arrival-planning.md`) |
| Cancel | `DELETE /events/{slug}/register` | owner or orga | owner only before check-in and while the event is not over (German explanation instead of the button; direct DELETE flashes it); orga can always cancel; cancelling clears check-in and releases the seat |
| Self check-in | `GET/POST /checkin/{token}` | auth | throttle 30/min/user; unknown or rotated token 404; window = event live, or registration phase and now >= start - 12 h; idempotent; not registered -> "Jetzt anmelden" (walk-in); full -> "Ausgebucht"; finished -> "event is over" (`docs/self-checkin.md`) |
| Orga scanner | `GET/POST /orga/events/{slug}/checkin` | helper+ | camera scanner (continuous, scans a held ticket once) plus a manual token field |
| Notifications | `RegistrationConfirmed`, `CheckinOpened`, `RegistrationOpened` | | bell; Discord DM mirror only with the Discord module |

## 5. Seating (P)

| Feature | Route | Rules |
|---------|-------|-------|
| Seat map | `GET /events/{slug}/seating` | SVG grid with free/taken/mine states, 40 px touch targets on coarse pointers |
| Claim / release | `POST /events/{slug}/seating/{seat}`, `DELETE .../seating` | needs a non-cancelled registration of the same event; one seat per user; one winner under concurrency |
| Admin | `/admin/seats`, grid generator | per-seat network metadata; label unique per event (German message) |

## 6. Teams (P)

| Feature | Route | Rules |
|---------|-------|-------|
| List / show | `GET /teams`, `/teams/{team}` | public |
| Create / edit | `POST /teams`, `PATCH /teams/{team}` | name <= 64 and unique case-insensitively, tag <= 16; logo to Storage; owner/orga only |
| Join / respond / leave | `POST /teams/{team}/join`, `.../requests/{request}`, `DELETE .../leave` | owner is notified of requests, requester of the answer (bell); owner cannot leave without transferring |
| Admin | `/admin/teams` | orga+ |

## 7. Games and tournaments (P)

| Feature | Route / where | Rules |
|---------|---------------|-------|
| Games | `/admin/games` | a game used by a tournament cannot be deleted; Pelican/preset fields only with the GameServers module |
| Tournament admin | `/admin/tournaments` | created as `draft`; no status field; `rules` text; lifecycle through actions |
| Lifecycle | draft -> announced -> enrollment -> check_in -> live -> finished | actions "Ankündigen", "Anmeldung öffnen" (also from draft; German list of missing setup), "Zur Check-in-Phase" (pulls `checkin_opens_at` forward, refuses after close), "Turnier starten", "Zurück zu Entwurf" (only with zero non-withdrawn entries); the scheduler tick opens check-in and auto-starts, skipping tournaments with fewer than 2 participating entries |
| Visibility | index, show, overlays, Discord, schedule, recap | draft is invisible to non-orga everywhere; announced is visible (game, mode, slots, rules) with sign-up closed |
| Formats | single elimination 2..64, double elimination 2..64 (visible byes "Freilos/Entfällt"), round robin | bracket engine is pure domain code; round robin has standings; n=2 finishes |
| Enroll / check in | `POST /tournaments/{t}/enroll`, `.../checkin` | requires `enrollment` / check-in window and a non-cancelled event registration (orga bypass); team tournaments use a team picker (eligible = roster size equals team size) |
| Withdraw | `tournaments.withdraw` | entry owner (solo user or team owner) in enrollment/check_in; slot freed; re-enroll possible |
| Match flow | `POST /matches/{m}/report`, `/confirm`, `/dispute`, `/go` (helper+), report withdraw/correct | report "own : opponent" stored by the reporter's side; pending report visible to both sides; optimistic `lock_version` with a friendly stale toast; reporter can withdraw/correct while pending; orga override via the disputes page |
| Realtime | public channel `tournament.{id}` and `match.report_updated` | broadcasts retry (3 tries); client resyncs on reconnect and when a hidden tab returns; calm "Verbindung unterbrochen" indicator |
| Notifications | `MatchReadyBell` | queued |

## 8. Admin panel (`/admin`, orga+)

| Page | Notes |
|------|-------|
| Dashboard + widgets | account, framework info, preflight ampel (database, redis, queue worker, scheduler heartbeat, storage, failed jobs, reverb, backup freshness; optional-module probes only when the module is on) |
| Events, Seats, Teams, Games, Tournaments (+ Disputes) | see above; German copy ("du"), `d.m.Y H:i`, labelled relation managers with empty states |
| Users | read-only list/detail with linked accounts |
| F-only resources | FoodOrders, CustomServers, SharedFiles, EventPhotos (moderation), ServerLinks, RemoteHosts, InfoscreenScenes, TombolaPrizes, NewsPosts, ScheduleItems, VoiceClientInstallers, Polls |
| Guards | guest -> `/login`; participant/helper -> 403 |

## 9. Preflight, scheduler, queue, ops (P)

| Item | Detail |
|------|--------|
| Preflight | `php artisan lanomat:preflight` (exit non-zero on a failing row); same rows in the panel widget |
| Backup | versioned backup script and cron installer under `scripts/ops/`; `BackupFreshnessCheck` reads a success marker and fails when stale (see `docs/deploy-lan-raute1.md`) |
| Scheduler | `lanomat:heartbeat` every minute, `lanomat:tournament-tick` every minute, `lanomat:health-watch` every 5 minutes (`SystemUnhealthy` to staff, de-duplicated), token refresh hourly; F-only: `lanomat:send-reminders`, `lanomat:sweep-discord-outbox`, `lanomat:prune-lfg`, `lanomat:send-schedule-reminders`, `lanomat:jukebox-tick` |
| Rate limiters | backed by the database (not Redis), so login and self check-in keep working when Redis is down |
| Other commands | `lanomat:install`, `lanomat:modules`, `discord:register-commands` (F), `gameservers:pelican-check` (F, live contract check against a test panel) |

## 10. Security and authorization expectations

| Expectation | Where |
|-------------|-------|
| Guests reach only `/`, `/login`, `/teams`, `/teams/{id}`, `/users/{id}`, `/up`, passkey well-known, OAuth routes (and F: beamer, overlays, gallery public routes, `/discord/voice`); every event page redirects to `/login` | routes |
| Draft events (and draft tournaments) are invisible to non-orga | policies / scopes |
| `/dev/login/*` is 404 outside local | DevLoginController |
| Helper routes 403 for participants, orga routes 403 for helpers, `/admin` 403 for participants | role middleware, `canAccessPanel` |
| CSRF enforced except the signed/secret-authenticated machine endpoints (Discord interactions, gateway, telemetry) and `/csp-report` | bootstrap |
| Throttles: login 5/min, 2FA 5/min, passkeys 10/min, password 6/min, self check-in 30/min, ping-orga 5/min | providers/routes |
| Headers: HSTS, framing/sniffing protection, referrer and permissions policy, no `X-Powered-By`; **Content-Security-Policy in report-only mode** with a nonce and `Reporting-Endpoints: csp-endpoint="/csp-report"` (enforcement is a later decision) | proxy config, middleware |
| Route ids are numeric (or UUID for notifications); malformed ids are 404, never 500 | route patterns |
| Uploads go to Storage; gallery uploads are moderated and EXIF-stripped; file uploads pass a moderation gate and quota | modules |

## 11. Full-profile participant features (F)

| Module | Routes | Rules |
|--------|--------|-------|
| Schedule | `/events/{slug}/schedule`, `.ics`, favorites | reminders once; change notification; tournaments synced (not drafts); ICS items without an end get an estimated end |
| Catering | `/events/{slug}/catering`, `POST /food-orders/{o}/items`, `DELETE /food-order-items/{i}` | closed order rejects; `FoodOrderReady` queued |
| Voting | `/events/{slug}/polls`, `POST /polls/{poll}/vote` | one vote per user; closed rejected; live results |
| LFG | `/events/{slug}/lfg`, `DELETE /lfg/{post}` | validation; own delete; prune command |
| GameServers | `/events/{slug}/servers`, `POST /api/telemetry/cs2/{serverLink}` | bearer token; Pelican client rebuilt against the real panel API; presets (**planned**: seeded presets with egg reference and port pool, start-from-preset, install verification) |
| Files | `/events/{slug}/files`, download, delete | moderation gate, quota |
| Gallery | auth `/events/{slug}/gallery` (+zip), public `/gallery/public/photos/{p}` | approved + public-event only for guests; zip button only with approved photos |
| Presence | `/events/{slug}/presence` | checked-in users, free slots, filters |
| Friends, Stats, Recap, News | `/friends`, `/stats/leaderboard`, `/events/{slug}/recap`, home block | see inventory of rulings; recap only for finished/archived |
| Infoscreen | public `/screen/{slug}` (beamer), `/screen/{slug}/control` (helper+), `POST /events/{slug}/ping-orga` (5/min) | no PII on the beamer; Discord-mirrored notifications are queued |
| Jukebox | `/events/{slug}/jukebox` | needs a checked-in registration; calm availability hint when Music Assistant is down |
| Casting | public `/overlay/tournament/{t}/bracket`, `/overlay/match/{m}/scoreboard` | transparent document background; draft -> 404 |
| Voice | `/voice/setup`, installers | missing file -> German 404 |
| Discord | `GET /discord/voice` (public, no PII), `POST /api/discord/interactions` (signed), `POST /internal/discord/gateway` (shared secret) | |
| Hosts / CustomServers / Lancache | Filament only | hostname or IP literal validation |
| Secondary navigation | sidebar group "Mehr" | Essensbestellung, Abstimmungen, Mitspieler finden, Dateien, Spielserver, each gated by its module |

## 12. External systems and test doubles

| System | Contract | Fake | Automatable? |
|--------|----------|------|--------------|
| Discord OAuth | Socialite | none locally | NO (manual) |
| Discord REST/guild check | `DiscordClient`, `GuildMembershipChecker` | `FakeDiscordClient` (Pest only) | feature tests only |
| Steam / Twitch link | `LinkedAccountConnector` | Fake in Pest | NO (manual) |
| Mumble/TeamSpeak, Pelican, Music Assistant, SSH hosts | `VoiceClient`, `PelicanClient`, `MusicClient`, `RemoteExecutor` | Fakes in Pest | local UI shows graceful-down behaviour only |

## 13. Known gaps

1. Game server presets (seeded presets, start-from-preset, port pool, install verification) are in flight; no QA cases beyond S5-37 yet.
2. Ticket types have no admin form field (accepted).
3. Real browser CSP report delivery is unverified (the policy stays report-only for now).
4. Realtime over the production websocket host can only be verified on a page with live channels (needs a running tournament or an approved write test).
