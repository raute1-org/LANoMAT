# QA round 2 (R2-B): seating, schedule, catering

Scope: deep tests of the Seating, Schedule and Catering modules on a throwaway database (`MODULES_PROFILE=full`, all external secrets blanked, queue on the database driver, one local dev server). Method: curl with session cookies (Fortify login), tinker, a few browser checks. Test data uses the `qa-`/`QA-` prefix. Findings are recorded, nothing was fixed.

Result legend: PASS / FAIL(F-R2B-n) / BLOCKED / MANUAL.

## Environment notes (read first)

- **Shared scratchpad collision.** Three QA agents used the same scratchpad directory; another agent's `env.sh` overwrote mine for a few minutes. My first seed script, six test registrations (qa-live) and two seat claims therefore briefly hit another agent's throwaway database (`lanomat_qa_r2a`). They are all `qa-` prefixed and idempotent (users), but the 6 registrations and 1 seat assignment I created there (registration ids 35-40, assignment id 1, created 07:07-07:09) should be ignored or deleted by that agent. Clean-up in that database was refused by the permission layer, so I left it. Everything reported below ran against my own database `lanomat_qa_r2b` (verified through the server's process environment), which is now dropped.
- Limitation: `php artisan serve` workers are few; the "parallel" seat race is real concurrency of 5 simultaneous curls, repeated 5 times, but not a stress test.
- Filament form E2E (schedule item create, grid dialog) was partly BLOCKED: the browser date-picker input and the searchable select would not take typed values after 3 attempts, and Livewire component tests inside tinker did not receive form data. Those cases were verified at the action/model level plus code reading instead.

## Case table

### Seating

| ID | Case | Result |
|----|------|--------|
| SE-01 | Grid action 1x1, repeat (dedupe), 2x2 over existing (only missing created) | PASS |
| SE-02 | Grid 0 rows / negative / non-numeric / fractional | PASS at form level by `minValue(1)`+numeric (read; action itself creates 0 seats for 0) |
| SE-03 | Grid very large (60x60 = 3600 seats) | PASS (2 s), but no upper bound, see F-R2B-6 |
| SE-04 | Grid prefix 300 chars through the admin dialog | FAIL(F-R2B-6) 500 |
| SE-05 | Duplicate labels | PASS (skipped; label unique per event) |
| SE-06 | Claim, switch, re-claim own seat, release, release twice | PASS |
| SE-07 | Claim a taken seat: error toast text German, previous seat kept (transaction rollback) | PASS |
| SE-08 | Five users claim the same seat in parallel, 5 rounds: exactly one holder, no 500 | PASS |
| SE-09 | Claim without registration (403), cancelled registration (403), guest (redirect/419) | PASS |
| SE-10 | Claim with pending registration / without check-in | PASS technically allowed; see Q-1 |
| SE-11 | Seat of another event through the wrong event slug | PASS (route accepts it, action refuses: `wrong_event`; note the redirect still reads as success) |
| SE-12 | Nonexistent / non-numeric seat id | PASS (404) |
| SE-13 | Cancel registration releases seat; checked-in registration cannot self-cancel (Ruling C) | PASS |
| SE-14 | Claim on a finished event | FAIL(F-R2B-9) allowed |
| SE-15 | Network metadata (ip, switch port) not in any participant/guest/orga-facing app page or beamer page (seating, dashboard, event, register, presence, screen) | PASS; `/admin/seats` 403 for participant, 200 for orga/admin |
| SE-16 | SVG at 1440 px, focus ring, Enter claims by keyboard, `role=button` + German `aria-label` per seat ("QA1-1 - Frei") | PASS |
| SE-17 | SVG at 390 px, 4 columns | PASS (70 px tiles) |
| SE-18 | SVG at 390 px, realistic 10x8 grid | FAIL(F-R2B-1) |
| SE-19 | Focus after claim | FAIL(F-R2B-8) focus lost |
| SE-20 | Performance 3605 seats | PASS (page ready in ~3 s) but unusable at 390 px (4.5 px tiles) |

### Schedule

| ID | Case | Result |
|----|------|--------|
| SC-01 | Item over midnight (22:00-03:00 Berlin): page shows local time; ICS DTSTART/DTEND in UTC (`...Z`) correct | PASS (page shows "21:00 - 04:00" under the start day only, see F-R2B-10) |
| SC-02 | Item without end: ICS estimated end +1 h (tournament +2 h), page shows start only | PASS |
| SC-03 | DST change night 25.10. (02:30 and 04:00 Berlin): page and ICS instants consistent (display zone Europe/Berlin, ICS UTC) | PASS |
| SC-04 | End before start accepted and exported (DTEND < DTSTART), page shows "12:00 - 11:00" | FAIL(F-R2B-3) |
| SC-05 | ICS structure: CRLF endings, VERSION/PRODID/UID/DTSTAMP/DTSTART/DTEND/SUMMARY, comma/semicolon/newline escaping, umlauts/emoji, LOCATION | PASS (manual review; no validator available offline) |
| SC-06 | ICS UID stable across downloads | FAIL(F-R2B-2) |
| SC-07 | ICS reachable from the UI | FAIL(F-R2B-4) |
| SC-08 | Favorite / unfavorite, double favorite, 4 parallel favorites (1 row), nonexistent item 404 | PASS |
| SC-09 | Personal schedule view from favorites | FAIL(F-R2B-4) star only, no view/filter/ICS of favorites |
| SC-10 | Reminder command: favorited item starting in 10 min: one bell per favoriter, second run sends nothing (dedupe via `reminded_at`), body time in Berlin | PASS |
| SC-11 | Reminder after the item is moved later | FAIL(F-R2B-5) |
| SC-12 | Change alarm after `starts_at` change reaches favoriters (queued); title/end/location edits send nothing | PASS for start; see F-R2B-7 for the rest |
| SC-13 | Change alarm text for a move to another day | FAIL(F-R2B-10) time only, no date |
| SC-14 | Tournament items appear in the schedule (auto-synced, type "Turnier", +2 h in ICS); drafts excluded | PASS |
| SC-15 | Favorite an item of a non-public (draft) event | FAIL(F-R2B-11) allowed |
| SC-16 | Admin create/edit pages load (200), German labels | PASS |
| SC-17 | Admin create form typed in Berlin time | BLOCKED (picker input) / covered by Ruling D |

### Catering

| ID | Case | Result |
|----|------|--------|
| CA-01 | Order, cancel own item, cancel twice (404), foreign item (403), orga deletes foreign item | PASS |
| CA-02 | Draft order, closed order, `closes_at` in the past, `opens_at` in the future, nonexistent/non-numeric order id: nothing placed | PASS |
| CA-03 | Unknown / missing / array option key, 300-char note: validation, nothing placed | PASS |
| CA-04 | Note containing a NUL byte | FAIL(F-R2B-12) 500 |
| CA-05 | Quantity limits: 40 parallel clicks gave 41 items for one user | FAIL(F-R2B-13) unlimited |
| CA-06 | Price 0 EUR option, 8.99 EUR, rounding (integer cents, no float) | PASS |
| CA-07 | Menu price above 2^31 cents places with 500; negative price (not reachable through the form) is stored | FAIL(F-R2B-14) |
| CA-08 | Duplicate menu keys within one order accepted (first wins) | FAIL(F-R2B-14) |
| CA-09 | Ordering without event registration / cancelled registration / not checked in | PASS technically allowed; see Q-2 |
| CA-10 | "Essen ist da" trigger: participant 403, helper/orga 302; bells for distinct buyers via queue; Discord down only produces `failed_jobs` (Ruling T) | PASS |
| CA-11 | Trigger twice sends two bells per buyer | PASS (documented as intended, see Q-4) |
| CA-12 | Orga totals modal (per participant + grand total) | PASS; formatting FAIL(F-R2B-15) |
| CA-13 | Orga export / per-option counts | FAIL(F-R2B-15) none |
| CA-14 | Participant page: open/closed indicator, note field, own list, own total in EUR (de-DE) | PASS (note text stays in the field after ordering, F-R2B-15) |
| CA-15 | Admin pages load (200); status open/close actions locked and idempotent | PASS (read) |

### Cross-cutting

| ID | Case | Result |
|----|------|--------|
| X-01 | `MODULES_PROFILE=core`: schedule, ics, catering, polls pages 404; favorite/food POST 404; admin schedule/food resources 404; seating (core module) 200; event page buttons gated by `has()` | PASS |
| X-02 | Sidebar group "Mehr" with all active modules, event group has Zeitplan/Sitzplan | PASS (full profile, screenshot) |
| X-03 | German UI, no translation keys seen on the three modules' pages, notifications, errors | PASS |
| X-04 | Console errors on catering/seating/schedule pages | PASS (none) |
| X-05 | Four states per list: empty copy exists for seats, schedule and catering (read from code and labels); no loading state needed (Inertia) | PASS (read) |
| X-06 | Guest access: event, schedule, ics, seating, catering all redirect to login | PASS (note Q-3) |
| X-07 | Unconfigured Discord: queued DM jobs still call the real Discord API with an empty token and receive 401 | FAIL(F-R2B-16) |

Totals: 36 PASS, 14 FAIL (distinct findings 16), 1 BLOCKED partly, 0 MANUAL.

## Findings

### F-R2B-1 (mittel) Seat map unusable on a phone for a realistic grid

Repro: event with a 10x8 grid, open the seating page at 390 px width. Expected: seats of at least ~40 px with legible labels (Ruling V and F-S7-1 set 40 px under coarse pointer). Actual: tiles are 28 px, label text about 6 px high; with 60 columns 4.5 px. The SVG scales to the container with no minimum size and no horizontal scroll or zoom. Guests choose seats on phones at a LAN. Cause: `resources/js/pages/Event/Seating.vue` (svg `w-full h-auto`, viewBox scaling, `overflow-auto` never triggers). Suggest a `min-width` on the SVG (cols x ~44 px) so the container scrolls.

### F-R2B-2 (mittel) ICS UID changes on every download

Repro: download `schedule.ics` twice, compare `UID`. Actual: random per export (`6ae594964dcc3`, then `6ae5949e37117`). Expected: stable UID per schedule item. Effect: re-importing or subscribing duplicates every event in the calendar app and changed items are never updated. Cause: `ScheduleCalendar::toCalendarEvent()` does not call `->uniqueIdentifier(...)` (spatie default is random). Use e.g. `lanomat-schedule-{item id}@{host}`.

### F-R2B-3 (mittel) End before start is accepted

Repro: schedule item with end 11:00, start 12:00 (admin form has no rule; created through the action). Actual: saved, shown as "12:00 - 11:00", ICS has DTEND < DTSTART (invalid, apps drop or mis-render it), "Jetzt" never matches. Expected: validation like Ruling N for events. Cause: `ScheduleItemForm` `ends_at` has no `after:starts_at`.

### F-R2B-4 (mittel) ICS export and personal schedule are not reachable

There is no link to `schedule.ics` anywhere in the UI (only the generated route helper), the schedule page has no "my favorites" filter, and the ICS always contains all items, never only the favorites. The star is stored and used for reminders only. Expected per test plan: favorite star leads to a personal schedule. Cause: `resources/js/pages/Schedule/Index.vue`, `ScheduleCalendar` (no favorites variant).

### F-R2B-5 (mittel) No start reminder after an item is rescheduled

Repro: favorited item, reminder sent (`reminded_at` set), then move the item to 5 min from now (or later). Actual: the "Programmpunkt verschoben" bell arrives, but no new "Gleich geht es los" reminder at the new time. Expected: `reminded_at` reset when `starts_at` changes. Cause: `AlarmScheduleItemChanged` / `ScheduleItem::booted()` never clear `schedule_item_favorites.reminded_at`. Realistic during a LAN (delays).

### F-R2B-6 (gering) Grid dialog: no upper bound, prefix length unchecked (500)

Repro: admin seats, "Raster anlegen", prefix of 300 characters. Actual: 500 (`value too long for type character varying(255)`), whole batch rolled back. Rows/columns have `minValue(1)` but no max; 1000x1000 would run for minutes inside one request. Cause: `ListSeats` schema (prefix without `maxLength`, rows/cols without `maxValue`), `GenerateSeatGrid`. A blank-only prefix (spaces) is also accepted and yields labels like " 1-1".

### F-R2B-7 (gering) Only a start-time change alarms favoriters

Changing title, end, location or deleting a favorited item sends nothing; a deleted item silently disappears from a user's plan. Cause: `ScheduleItem::booted()` listens for `starts_at` only; `DeleteScheduleItem` dispatches nothing.

### F-R2B-8 (gering) Keyboard focus lost after claiming a seat

After Enter on a seat the claimed tile is no longer focusable (`isClaimable` false) and focus drops to `body`; there is also no announcement (no live region for the result). The focus ring at the top row is clipped by the SVG bounds. Cause: `Seating.vue`.

### F-R2B-9 (gering) Seats can be claimed and changed on finished events

A registered user can claim a seat on a `finished` event (no status check in `SeatingController::claim` / `ClaimSeat`). Ruling C blocks self-cancel for finished events, so claiming should probably be blocked as well.

### F-R2B-10 (gering) Change alarm and page show times without date

"X wurde auf 21:00 Uhr verschoben." gives no day, so a move to the next day reads like a no-op. The schedule page lists an item that ends after midnight ("21:00 - 04:00") under its start day with no hint that the end is the next day. Cause: `ScheduleItemChanged` formats `H:i` only; `Schedule/Index.vue`.

### F-R2B-11 (gering) Favorite works on items of non-public events

`ScheduleItemPolicy::favorite` is always true and the item route is not scoped to a visible event; a user can favorite an item of a draft event by id (reveals existence only, no content). Cause: `ScheduleController::favorite`.

### F-R2B-12 (gering) NUL byte in a food note returns 500

Repro: `POST /food-orders/{id}/items` with note containing `\u0000`. Actual: 500 (PostgreSQL jsonb rejects the escape). Expected: validation error. Cause: `PlaceFoodOrderItemRequest` note rule; the same applies to any other free-text field stored as jsonb. Needs crafted input; not reachable from the keyboard.

### F-R2B-13 (mittel) Unlimited items per user and order

There is no quantity and no cap: a single user (or script) can place hundreds of items (41 in the test); the only guard is the open window. Cancelling is one click per item. For a LAN, one accidental double-click orders twice with no confirmation. Suggest a per-user cap or a quantity field with maximum, and an undo hint.

### F-R2B-14 (gering) Menu input validation

Menu price has no `maxValue` (a price above 2^31 cents saves in the form, then every order of it is a 500); negative prices are blocked by the form but not by `MenuOption`/the cast; duplicate `key` values in one menu are accepted (first wins, counts merge); the price is entered in cents ("Preis (Cent)"), which is error-prone for orga. Cause: `FoodOrderForm`, `MenuOption::fromArray`.

### F-R2B-15 (mittel) Orga summary lacks per-option counts and export; number format

For ordering food the kitchen needs "12x Pizza, 8x Cola"; `CostSplit::byOption` computes it but nothing displays it (no use outside the class), the totals modal shows per participant only, and there is no CSV/print export. The modal formats `number_format(..., 2)` as "12.50 EUR" (English decimal point) while the participant page uses de-DE. The participant note field keeps its text after ordering, so the next order reuses the note (the Map in `Catering/Show.vue` is never cleared).

### F-R2B-16 (gering, dev only) Unconfigured Discord still reaches the real API

With an empty bot token the queued `DiscordChannel` jobs send HTTP requests to `discord.com` and fail with 401 (landed in `failed_jobs`). Expected: no outbound call when the client is unconfigured (null client), as for Mumble/Pelican. Harmless in production, but it breaks the "no real external call in local QA" assumption (this run blocked it with a dead proxy after noticing). Cause: `HttpDiscordClient` hard-codes `https://discord.com/api/v10` and is bound without a configuration guard.

## Questions for the product owner (recommended default first)

- **Q-1** Should seat claiming require a confirmed registration and/or check-in? Currently pending and not-checked-in users can take seats (cancelled and unregistered cannot). Default: allow pending/confirmed, require nothing else, but block on finished/archived events (F-R2B-9).
- **Q-2** Should catering require an event registration (or check-in)? Currently any logged-in account, even without a registration, can order and receives "Essen ist da". Default: require a non-cancelled registration (same as Ruling A for tournaments).
- **Q-3** The test plan and code comments call seating and schedule "public", but every event page (including schedule, ICS, seating) redirects guests to login. Default: keep login required, correct the comments.
- **Q-4** "Essen ist da" can be triggered repeatedly (two bells per buyer). Default: keep, but show the orga a confirm dialog on the second trigger within 10 minutes.
- **Q-5** Should changes to end time, location or deletion notify favoriters (F-R2B-7)? Default: notify for start/end/location changes and deletion, as "Programmpunkt geändert/entfällt".
- **Q-6** Should a "my schedule" page (favorites only, with its own ICS) exist before the LAN (F-R2B-4)? Default: add a filter toggle on the schedule page and an "Zeitplan exportieren" link, with an option for favorites only.
