# QA run: S5 (other modules, full profile) + S7 (responsive and accessibility) + extras

Branch under test: current `main` plus a docs commit. Profile `MODULES_PROFILE=full`, local, throwaway DB `lanomat_qa` (dropped at the end). Env via shell overrides: `CACHE_STORE=file QUEUE_CONNECTION=database BROADCAST_CONNECTION=reverb SESSION_DRIVER=file`, Reverb on the host :8081, `artisan serve` (4 workers) :8000, `queue:work --tries=1`. All Discord/Mumble credentials were blanked via shell overrides after the first minutes (see Environment note). Browser: Chrome, `localhost:8000` = participant, `127.0.0.1:8000` = admin. 390px / 640px / 320px checks done with same-origin iframes of the live pages; HTTP-level checks with curl cookie jars. A third-party Chrome extension in the browser injects content and made a few form interactions (focusing a text field, then clicking) unusable ("Cannot access a chrome-extension:// URL"); this is environment, not the app.

## Counts

- S5 (34 cases): PASS 28, PARTIAL 2 (S5-07, S5-18), FAIL 3 (S5-22, S5-23, S5-25), N-A 1 (S5-32).
- S7 (14 cases): PASS 12, PARTIAL 2 (S7-01 dev-only overflow, S7-09 two unlabeled inputs).
- Extras: (1) navigation/page sweep of 26 participant pages + beamer, (2) Filament sweep of 49 full-profile pages (list/create/edit): all HTTP 200, German, no raw keys, dates `d.m.Y H:i` Berlin, Admin badge + "Zur App" on every page, no page overflow; (3) mobile 390px; (4) a11y basics; (5) regression of self check-in, camera scanner (UI), arrival plan form, tournament pages.
- Findings: 12 (0 Critical, 4 Important, 8 Minor).

## Environment note (not an app defect)

The dev `.env` contains real Discord credentials. The first `lanomat:send-schedule-reminders` run therefore reached the real Discord API once (HTTP 400 "Invalid Recipient(s)" for a fake recipient id; nothing was delivered). From then on `DISCORD_*` and `MUMBLE_*` secrets were overridden to empty in the QA shell. Recommendation: the local QA recipe in `test-plan.md` should export `DISCORD_BOT_TOKEN= DISCORD_GUILD_ID= DISCORD_ANNOUNCE_CHANNEL_ID= DISCORD_MATCH_CATEGORY_ID= DISCORD_CLIENT_ID= DISCORD_CLIENT_SECRET=` before any scheduler/notification test.

## Findings

| ID | Sev | Summary |
|----|-----|---------|
| F-S5-1 | Important | Notifications with a Discord channel are sent synchronously in three request paths. With Discord unreachable/unconfigured (full profile) "Orga rufen", "Check-in öffnen" and "Essen fertig" return HTTP 500, and the loop aborts at the first recipient. |
| F-S5-2 | Important | Gallery zip on a finished event without approved photos returns 500 (the "Alle Fotos herunterladen" button is shown for every finished/archived event). |
| F-S5-3 | Important | No navigation entry (sidebar or event page) for Essensbestellung, Abstimmungen, Mitspieler finden, Dateien, Spielserver. Participants can only reach them by typing the URL. |
| F-S5-4 | Important | OBS overlays are not transparent: `body` keeps `bg-background` (computed rgb(247,248,250), html white), only an inner wrapper is `bg-transparent`. In an OBS browser source the overlay renders on an opaque panel. |
| F-S5-5 | Minor | Remote-host form accepts any string as hostname/IP (e.g. "not a host;rm"); phpseclib is used (no shell injection), but no format validation. |
| F-S5-6 | Minor | Voice installer download returns 500 when the stored file is missing on disk (should be a friendly 404). |
| F-S5-7 | Minor | Screen-control validation messages use raw field names: "Component ist erforderlich.", "Das Level ist erforderlich." (missing German attribute names; API only, UI sends valid values). |
| F-S5-8 | Minor | Schedule items synced from tournaments have DTEND = DTSTART in the .ics (zero-length VEVENT); `QA QA Finale`-style double prefix is a seed artefact, not a finding. |
| F-S5-9 | Minor | Jukebox page shows no "nicht verfügbar" hint when Music Assistant is down on load ("Gerade läuft nichts" only); the message appears only after a search. A client-supplied title/artist is stored when MA cannot verify the URI. |
| F-S7-1 | Minor | Primary action buttons are 32-36px high (shadcn `h-8`/`h-9`), sidebar toggle 28x28, header icon buttons 36x36, back link 20px high: below the 40px target the brief asks for at 390px (all still above WCAG 2.2 AA 24px). Examples: "Anmelden", "Bestellen", "Merken", "Zurück zum Event". |
| F-S7-2 | Minor | English leftovers in accessibility/UI chrome: sr-only "Toggle sidebar", toast region `aria-label="Notifications alt+T"`, native file input "Choose Files / No file chosen" (browser locale) on Galerie/Dateien. |
| F-S7-3 | Minor | Two inputs without an accessible name (placeholder only): Essensbestellung "Anmerkung (optional)" and Jukebox "Track suchen ...". No `<nav>` landmark (sidebar is a div). |

Copy/layout nits (Minor, no separate IDs): sidebar says "Zeitplan" but page title is "Programm"; admin says "Umfragen" but participant UI says "Abstimmungen"; poll show page badge "ABSTIMMUNG" vs list badge "OFFEN"; "1 Fotos" (no singular); quota "500.0 MB" with decimal point; Bestenliste repeats title+subtitle twice; Freunde copy "adden" (Denglisch); bell text "QA-P1 (Platz: kein Platz) ruft die Orga: –"; `/login` at 390px overflows (scrollWidth 686) only because the dev-only demo-login buttons sit in a nowrap row (not present in production); voted option in a poll still shows an active-looking "Abstimmen" button (only the text "Du hast abgestimmt." signals the vote).

### Repro and cause

- **F-S5-1.** Repro (full profile, Discord not reachable, e.g. blank token): as helper `POST /screen/qa-live/control/triggers/checkin-open` (or `.../food-ready/{order}`), or as participant `POST /events/qa-live/ping-orga`. Expected: bell notification for everybody, Discord failure isolated/queued, toast "gesendet". Actual: HTTP 500 (`RequestException ... 401`), only the first recipient has the bell row (1 of 6 for check-in), later recipients get nothing; `ping-orga` created the bell rows but still 500 (5 of 5 first calls). Cause: `Notification::send()` in `app/Modules/Infoscreen/Actions/{PingOrga,TriggerCheckinOpen,TriggerFoodReady}.php` uses a notification that is not queued, so `Discord\Channels\DiscordChannel::send` -> `HttpDiscordClient::sendDm` runs inside the request (`HttpDiscordClient.php:46` `->throw()`); other notifications (ScheduleItem*, MatchReady) are queued and only land in `failed_jobs`. Fix idea: make these notifications `ShouldQueue` (or catch per channel/recipient).
- **F-S5-2.** Repro: finished event without approved photos (`qa-finished`), p1 `GET /events/qa-finished/gallery/zip` -> 500 `FileNotFoundException` for `storage/app/private/tmp/event-7-photos-*.zip`. Cause: `Gallery/Actions/BuildEventPhotoZip.php` — `ZipArchive::close()` writes no file when no entry was added; `canDownloadZip` in `GalleryPageController.php:60` only checks the status. Expected: button hidden or friendly message when there are no photos.
- **F-S5-3.** Repro: log in, open the sidebar and `/events/qa-live`; links exist for Übersicht, Zeitplan, Anmeldung, Turniere, Sitzplan, Präsenz, Jukebox, Galerie only. `resources/js/components/AppSidebar.vue` has no entries for `catering.show`, `polls.index`, `lfg.index`, `files.index`, `servers.index`; `Event/Show.vue` only links the recap (past events). Expected: nav entries gated by the module toggle.
- **F-S5-4.** Repro: `GET /overlay/tournament/1/bracket` (guest), `getComputedStyle(document.body).backgroundColor` = `rgb(247, 248, 250)`; screenshot shows an opaque light page. Cause: `resources/css/app.css` `@layer base { body { @apply bg-background … } }`; `Overlay/Bracket.vue` / `Scoreboard.vue` only make the inner wrapper transparent. Fix idea: overlay layout sets `html, body { background: transparent }`.
- **F-S5-5.** `app/Modules/Hosts/Filament/Resources/RemoteHosts/Schemas/RemoteHostForm.php:25-27` hostname only `required()->maxLength(255)`.
- **F-S5-6.** `GET /voice/installers/1/download` with the file absent from the disk -> 500 (file present -> 200).

## S5 results

| ID | Result | Evidence |
|----|--------|----------|
| S5-01 | PASS | Filament schedule list/create/edit load (German, d.m.Y H:i Berlin); items seeded via factory (UI create not typed through, form loads); `/events/qa-live/schedule` groups by day, "JETZT"/"Gleich" highlight cards. |
| S5-02 | PASS | `POST /schedule/{i}/favorite` creates one row, a second POST keeps one row, `DELETE` removes, second DELETE harmless; unknown item 404. |
| S5-03 | PASS | `text/calendar; charset=utf-8`, attachment `schedule.ics`, VEVENT per item (UTC), guest -> 302 login. See F-S5-8 (zero-length tournament events). |
| S5-04 | PASS | Item starting in 10 min + favorite: `lanomat:send-schedule-reminders` twice -> exactly one `ScheduleItemStartingSoon` bell row, `reminded_at` set. |
| S5-05 | PASS | Editing the start time of a favorited item -> `ScheduleItemChanged` ("Programmpunkt verschoben ... 20:57 Uhr", Berlin time). |
| S5-06 | PASS | Tournaments seeded -> schedule items of type Tournament exist; changing `starts_at` moved the item (+2 days). |
| S5-07 | PARTIAL | Add/remove own item, unknown option and empty option rejected (422 German "Die Option ist erforderlich."), other user's delete 403, closed order rejects add and delete, totals shown ("Meine Summe: 9,50 €", "BESTELLUNG GEÖFFNET"). `FoodOrderReady` trigger returns 500 (F-S5-1). |
| S5-08 | PASS | Poll with 3 options, `closes_at` future, listed on `/events/qa-live/polls` ("QA Lieblingsspiel?", "OFFEN", "Stimmen gesamt"). |
| S5-09 | PASS | One vote per user (re-vote on another option ignored, row unchanged), vote on closed poll rejected (count unchanged), unknown option 404. |
| S5-10 | PASS | Poll page open as p1, vote by another user via HTTP -> page updated to "Stimmen gesamt: 5" and 60/20/20 % without reload (`PollUpdated` on the queue log). |
| S5-11 | PASS | Valid post created; empty title 422 "Der Titel ist erforderlich."; slots 99 -> "höchstens 64"; 0 -> "mindestens 1"; delete own OK, delete other's 403; expired posts removed by `lanomat:prune-lfg` ("Pruned 1"). |
| S5-12 | PASS | Upload OK (pending); pending file downloadable by owner and orga, 403 for other user; after approval 200 for all; delete own OK, other's 403; no-file 422 German. Oversize (>8M) hits the local PHP limit (413 JSON) — the 200 MB app cap is not reachable locally (prod php.ini 1G). `.exe` with text content accepted (allowlist includes octet-stream/text) — see Q-S5-1. |
| S5-13 | PASS | JPEG with GPS EXIF: stored file has no EXIF/GPS (checked with `strings`); pending visible to owner/orga (200), other user 403; after approval other user 200. Zip: see F-S5-2. |
| S5-14 | PASS | Guest `/gallery/public/photos/{pending}` and `/thumb` -> 404; approved on a Live event -> 200 `image/jpeg`. |
| S5-15 | PASS | Presence page: "Eingecheckt: 4", names with LIVE + match, free slots per tournament, running matches, filters "Nur freie Slots / Spielt gerade / Nur Streams / Nur Freunde". |
| S5-16 | PASS | request -> pending; reverse request auto-accepts; duplicate/self/unknown user -> 422; remove tears down; block removes the friendship; re-request by either side leaves no row; both directions show the same neutral message ("Einer der beiden Nutzer hat den anderen blockiert"), so no blocked-by oracle. |
| S5-17 | PASS | Suggestions list "Gemeinsames Event · Gemeinsames Turnier · Überschneidungen: 3"; blocked user not suggested; no e-mail/Discord ids in the page. |
| S5-18 | PARTIAL | `/stats/leaderboard` lists entrants with Siege/Turniersiege/Teilnahmen/Podestplätze (all wins 0, no finished match in this run); heading+subtitle duplicated. |
| S5-19 | PASS | `qa-finished/recap` 200 (stats tiles, "Turniersieger", "Highlights" with German empty states); `qa-live/recap` 404; guest 302. |
| S5-20 | PASS | Published post on participant home and event page; draft post hidden; guest landing has no news (event-free by design). |
| S5-21 | PASS | `/screen/qa-live` as guest: no nav/sidebar, scenes cycle Willkommen -> Programm -> Sitzplan (seat labels only, no names), no console errors, no CSP events; draft event 404, announced 200. |
| S5-22 | FAIL (F-S5-1, F-S5-7) | helper 200 on control page, participant 403, guest 302; tombola draw OK (1 draw row); check-in open and food-ready -> 500 with Discord down; status POST with bogus body -> 422 with raw field names. |
| S5-23 | FAIL (F-S5-1) | 6th request within a minute -> 429 as expected; but requests 1-5 return 500 (bell rows `OrgaPinged` x5 were written first). Bell text "(Platz: kein Platz) ruft die Orga: –". |
| S5-24 | PASS | Page loads with empty states; search -> JSON `error: "Music Assistant ist gerade nicht erreichbar. Bitte versuche es später erneut."`; add by non-checked-in user rejected, checked-in user adds (1 row), second add by same user rejected (anti-flood). See F-S5-9. |
| S5-25 | FAIL (F-S5-4) | Guest `/overlay/tournament/1/bracket` 200, render-only (no buttons/nav), draft tournament 404, bogus match 404; background is opaque. |
| S5-26 | PASS | `/voice/setup` shows Mumble + "Verbinden" + per-provider installer empty state; with `VOICE_PROVIDERS=` empty provider list and "Aktuell ist kein Voice-Anbieter aktiv." state exists; seeded installer downloads (200), unknown id 404; missing file -> F-S5-6. |
| S5-27 | PASS | Start of a tournament with Mumble down: tournament goes `live` with 3 matches; `ProvisionTournamentVoiceJob`/`ProvisionMatchVoiceJob` and `CreateMatchChannelJob` FAIL in the queue (failed_jobs), no HTTP error. |
| S5-28 | PASS | `GET /discord/voice` -> `{"channels":[]}`; interactions unsigned -> 401 JSON; gateway without/with wrong secret -> 401. |
| S5-29 | PASS | `lanomat:send-reminders`, `lanomat:sweep-discord-outbox`, `lanomat:jukebox-tick` exit cleanly without a token; `lanomat:preflight` shows Discord/Gateway/TeamSpeak/Pelican/MA "SKIP nicht konfiguriert", Mumble DOWN with reason. |
| S5-30 | PASS | `/events/qa-live/servers` empty state "Noch keine Spielserver bereit ..."; telemetry POST without bearer 401, wrong bearer 403, right bearer + bogus body 422. |
| S5-31 | PASS | Hosts, Custom-Server, Server-Links, Remote-Host create/edit pages load, German, empty/validation labels; required fields marked; see F-S5-5. |
| S5-32 | N-A | covered by the Filament sweep below. |
| S5-33 | PASS | `MODULE_GALLERY=false` -> `InvalidModuleConfiguration`: 'Module "news" requires "gallery"' and 'Module "recap" requires "gallery"'. |
| S5-34 | PASS | `MODULE_JUKEBOX=false` alone refuses to boot ('recap requires jukebox'); with `MODULE_RECAP=false` too: no jukebox/recap routes (404), module list omits them, no nav entry. |

## Filament sweep (full profile, admin)

49 pages: custom-servers, event-photos, events (+create, edit, arrival), food-orders, games, infoscreen-scenes, news-posts, polls, remote-hosts, schedule-items, seats, server-links, shared-files, teams, tombola-prizes, tournaments (+disputes), users, voice-client-installers (list + create + edit where records exist). Result: all HTTP 200 (edit URL uses the slug for events), page title and h1 German, no ISO dates, no English words (Create/Delete/Save/Cancel/Loading/undefined/NaN), "ADMIN" badge and "Zur App" on each page, no page-level horizontal overflow at 1280px. Tables use German empty states ("Noch keine ..."). Dashboard: Preflight-Ampel tile with "Neu prüfen", failed jobs warning. At 390px `/admin*` has no page overflow, sidebar collapses off-canvas, tables scroll inside `overflow-x:auto`. Not exercised: typing into Filament forms (extension interference), so create-form validation messages were verified for forms by code only.

## Navigation / page-anatomy sweep (participant, full profile)

26 pages checked at 390px and read as text: one `<h1>`, `lang="de"`, unique German `<title>` per page, no `Invalid Date`/`undefined`/`null`/raw keys, no images without `alt`, no page overflow. Pages without sidebar link: see F-S5-3. Back links ("Zurück zum Event", "Zurück zu allen Abstimmungen/Turnieren") exist on sub pages.

## S7 results

| ID | Result | Evidence |
|----|--------|----------|
| S7-01 | PARTIAL (Minor) | `/` fine (CTA 203x40, no overflow). `/login` overflows at 390 (scrollWidth 686 vs 375) only because of the local-only demo buttons row; production has the Discord button only. |
| S7-02 | PASS | Dashboard cards stack, no overflow; sidebar toggle opens a dialog "Sidebar" with 16 links and Escape closes it. |
| S7-03 | PASS | `/events/qa-live/register` at 390: QR fully visible, ticket rows, arrival plan card; `qa-reg/register`: ticket radios, arrival/departure selects and "Jetzt anmelden" fit. |
| S7-04 | PASS | Seating grid: seat tiles about 70px, legend visible, no overflow. |
| S7-05 | PASS | Tournament page: bracket scrolls inside its container (horizontal scrollbar), page scrollWidth equals width. |
| S7-06 | PASS | `/checkin/{token}`: full-width "Einchecken" (300px x 54px), success state card with time in Berlin zone. |
| S7-07 | PASS | `/admin` at 390: sidebar off-canvas, tables scroll in container, no page overflow. |
| S7-08 | PASS | First Tab shows the visible skip link "Zum Inhalt springen" (amber, readable), Enter jumps to `#main`; all 33 tabbable controls on `/settings/profile` carry an outline or ring on focus (script check; the real Tab walk beyond the first stops was blocked by the extension). Dropdown (bell) moves focus into the menu, Escape closes it and returns focus to the trigger. |
| S7-09 | PARTIAL (F-S7-3) | All form inputs on register, profile, teams, LFG, files, gallery have labels; exceptions: Essensbestellung note and Jukebox search (placeholder only). |
| S7-10 | PASS | Computed contrast: body text 16.8:1 light / 15.8:1 dark, muted text 5.7:1 light / 6.1:1 dark, primary button (white on amber 168,90,0) 5.09:1 in both themes, destructive button 4.83:1, red badge 4.83:1. |
| S7-11 | PASS | Global `@media (prefers-reduced-motion: reduce)` rule in `resources/css/app.css:246` plus `motion-reduce:` on LiveIndicator, bracket card, photo grid, now-playing; could not toggle the OS setting in the tool, verified by code. |
| S7-12 | PASS | Every `<img>` has `alt` (empty alt for decorative thumbs), gallery thumbs have width/height and `loading="lazy"`. |
| S7-13 | PASS | `<html lang="de">`, 26 unique titles; error pages (404/403) German with matching titles. |
| S7-14 | PASS | Theme is rendered server side (`html class="dark"` from the `appearance` cookie, inline script for system mode), so no flash on reload; contrast verified in both modes. |

Extra a11y/zoom: 200% (640px viewport) and 400% (320px) reflow on 12 pages: no page overflow. `aria-live="polite"` toast region present. Headings: exactly one `<h1>` on all checked pages.

## Extras: regression on participant side

- Self check-in at 390px: unchecked user sees greeting, hint and full-width button; checked user sees success card with "Weiter" links.
- Camera scanner (orga): idle state with amber "Kamera scannen" + manual token field; after click "Kamera stoppen" and "Kamera startet ..." (stays until the browser permission prompt is answered, which cannot happen in this environment); stop returns to idle. Real decode/permission-denied states not exercised.
- Arrival plan form at 390px: selects and time fields stack, "Jetzt anmelden" button fully reachable.
- Tournament index/show at 390px: counts "4 / 16 Anmeldungen", status badges, "Anmelden" buttons; bracket container scrolls.

## CSP report-only

No `securitypolicyviolation` events on the beamer (25 s of scene cycling), on SPA navigation across dashboard, seating, gallery (private image route), jukebox, presence, friends, teams, schedule, nor on the poll realtime page. The header carries `Reporting-Endpoints: csp-endpoint="/csp-report"`; the endpoint works (a hand-posted report is logged to `storage/logs/csp-*.log` with 204), but deliberate violations produced in the browser (inline script, external image) were seen by the page listener and never arrived in the log within about 10 minutes (browser reporting delay, extension or localhost behaviour unknown) — delivery of real browser reports is therefore unverified, see Q-S7-1.

## New open questions

- **Q-S5-1 (file allowlist).** `config/files.php` allows `application/octet-stream` and `text/plain`, so executables with a neutral MIME can be shared (after moderation). Recommended default: keep, because LAN parties exchange mods/installers and every upload passes the moderation gate; add the extension denylist only if orga asks.
- **Q-S5-2 (nav for secondary modules).** Where should Essen, Abstimmungen, Mitspieler finden, Dateien and Spielserver live? Recommended default: one sidebar group "Mehr" under the current event, each entry gated by its module toggle.
- **Q-S5-3 (Discord failures in the request path).** Should staff notifications with a Discord mirror always be queued? Recommended default: yes, make `OrgaPinged`, `CheckinOpened`, `FoodOrderReady` `ShouldQueue` so Discord outages never reach the user.
- **Q-S7-1 (CSP reports).** Can someone confirm on the production host that browser CSP reports arrive in the `csp` log channel? Recommended default: check once after deploy with a deliberate violation via DevTools; if nothing arrives, add `report-to` group header `Report-To` fallback.

## Environment changes made and removed

DB `lanomat_qa` created, seeded (QA seed plus extra factory data) and dropped at the end; processes started by this run (`artisan serve` :8000 and temporary :8002 servers, `reverb:start` :8081, `queue:work`) stopped; temp files under `/tmp/qaj`, `/tmp/qalogs`, `/tmp/qa-*` deleted; the extra log files `storage/logs/csp-*.log` created by the run and the QA records in `storage/app/private` (uploaded test file/photo, installer placeholder, zip temp) were removed. The pre-existing Chrome tab 1873207112 (not created by this run) was navigated while testing and left on a QA page; the tab created by this run was closed.
