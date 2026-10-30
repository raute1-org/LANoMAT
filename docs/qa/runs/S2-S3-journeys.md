# QA run: S2 (participant journey) + S3 (orga/admin journey) + fix-round behaviour

Branch under test: `fix/qa-round1-ops`. Profile `MODULES_PROFILE=core`, local, DB `lanomat_qa` (throwaway). App: `php artisan serve` on 127.0.0.1:8000 (4 workers) with shell overrides `CACHE_STORE=file QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log` (so the shared dev Redis/queue were not touched; Reverb therefore reports SKIP). Users `qa-*@lanomat.local` / `password`. Browser: Chrome (own tab), `localhost:8000` = participant, `127.0.0.1:8000` = orga. HTTP-level logic tests via curl with cookie jars (JSON login), UI checks via Chrome. Mobile = 390px iframe of the live page (page-level horizontal overflow check + text scan); desktop 1416px. Dark default theme; light theme spot-checked (seating, tournament, check-in pages).

Counts
- S2: 49 cases (S2-28 does not exist in the plan): PASS 45, FAIL 3 (S2-07, S2-38, S2-42), MANUAL 1 (S2-34).
- S3: 43 cases: PASS 33, PARTIAL 4 (S3-20, S3-26, S3-29, S3-33), FAIL 3 (S3-04, S3-07, S3-27), BLOCKED 1 (S3-36), N-A 2 (S3-28, S3-41).

## Findings

| ID | Sev | Summary |
|----|-----|---------|
| F-S2-1 | Minor | `/user/confirm-password` wrong password returns English "The provided password was incorrect." (Fortify default, not translated). |
| F-S2-2 | Important | Notification bell: after "Als gelesen markieren" (or any Inertia POST/redirect) the dropdown shows "Keine neuen Benachrichtigungen" and the badge disappears although other notifications are still unread (DB: 3 unread; badge returns after full reload). |
| F-S2-3 | Minor | Team join request creates no bell notification for the owner (plan S2-42 expects one; only `RegistrationConfirmed`, `LinkedAccountReauthRequired`, `SystemUnhealthy` etc. exist). |
| F-S2-4 | Minor | Teams may have duplicate names (two "QA-Team Alpha"); the team picker for tournament enrollment then lists two identical entries. |
| F-S2-5 | Minor | `/events` lists only the current event (via nav/dashboard) plus "Vergangene LANs"; other registrable events (qa-reg, qa-small, qa-near, qa-announced) are reachable only by URL. Plan S2-02 expected all non-draft events. |
| F-S2-6 | Minor | 403 page for a participant offers "Zum Admin-Bereich" (leads to another 403). |
| F-S2-7 | Minor | `/settings/connections/steam|twitch/redirect` return 302 to the provider although `providers=[]` (not offered/unconfigured); a linked row of an unconfigured provider is not shown and cannot be unlinked in the UI (DELETE route works). |
| F-S2-8 | Minor | Plain team members see no enrollment state ("Angemeldet") on the tournament page of a team entry (`myEntry=null`); only the owner does. |
| F-S3-1 | Important | Event create/edit has no validation: end before start (11.11. start, 09.11. end) and `max_participants=0` were saved. |
| F-S3-2 | Minor | Event edit page: after a lifecycle transition the header action row stays stale (still "Ankündigen"/"Anmeldung öffnen") until the next click, which is swallowed (it only re-renders). Tournament edit refreshes correctly. Cause: `EditEvent::getHeaderActions()` builds actions from the mount-time `$event`; `refreshFormData(['status'])` does not rebuild them. |
| F-S3-3 | Minor | English/raw empty states: "Keine event registrations", "Keine linked accounts", "Keine game matches". |
| F-S3-4 | Important | Seat create with duplicate label in the same event -> HTTP 500 `UniqueConstraintViolationException` (`seats_event_id_label_unique`); no validation message. Same likely for duplicate position. |
| F-S3-5 | Minor | Under `core` there is no in-app "Anmeldung offen" notification: `RegistrationOpened` is sent only by `Discord\Listeners\AnnounceRegistrationOpen` (Discord module off). Plan S3-07 expects bell fan-out. |
| F-S3-6 | Minor | Deleting a game that is used by tournaments succeeds silently and nulls `tournaments.game_id` (pages render, but game gone; no guard/warning). |
| F-S3-7 | Minor | Check-in QR URL (modal + print page) is built from the request host (`route()`), not `APP_URL` (127.0.0.1 vs configured localhost). Fine behind a correct proxy; wrong if admin is opened via another hostname. |
| F-S3-8 | Minor | Filament dates render US-style "Okt 31, 2026 17:40:18" (German month, US order) while the app uses 31.10.2026; DateTimePickers use browser-locale (US) format. Zone is correct (Europe/Berlin). |
| F-S3-9 | Minor | Registrations relation manager: ticket column shows raw key "standard", no arrival/plan column, no status filter; cancel confirm modal does not name the participant. Tournament entries manager offers "Einchecken" while check-in is closed (action then errors "Der Check-in ist derzeit nicht geöffnet."). |
| F-S3-10 | Minor | Game form shows Pelican egg id / server presets / Steam deeplink under `core` (module gating leak). |
| F-S3-11 | Minor | Copy register: Filament uses formal "Sie" ("Wählen Sie eine Option", "Sind Sie sicher") while the app uses "du". |

## S2 results

| ID | Result | Evidence |
|----|--------|----------|
| S2-01 | PASS | Dashboard renders (German, Berlin times), no console errors. Mobile 390: no overflow. |
| S2-02 | PASS (deviation F-S2-5) | `/events` = archive only (qa-finished); draft not listed; others by URL. |
| S2-03 | PASS | `/events/qa-reg` Event/Show props ok, 200. |
| S2-04 | PASS | announced: availability `upcoming`; POST 403. |
| S2-05 | PASS | reg row `confirmed`, QR shown, `RegistrationConfirmed` notification row. |
| S2-06 | PASS | `qr_token` length 40. |
| S2-07 | FAIL (F-S2-2) | Bell lists "Anmeldung bestätigt"; mark-read sets `read_at` (DB) but list/badge then wrongly empty. |
| S2-08 | PASS | p2 vip with plan 01.11. evening -> 04.11. afternoon stored. |
| S2-09 | PASS | 5 variants all 422 with German messages (outside event, order, day-w/o-window, window-w/o-day, bad window). |
| S2-10 | PASS | PATCH arrival saves; all-empty clears ("Weiß ich noch nicht"); `{}` also clears. |
| S2-11 | PASS | 422 "Der Tickettyp ist ungültig." |
| S2-12 | PASS | one row; redirect back. |
| S2-13 | PASS | `cancelled`; plan retained on row but cleared on re-register (S2-14). |
| S2-14 | PASS | `confirmed`, empty plan. |
| S2-15 | PASS | 3rd user: availability `full`, flash "Das Event hat die maximale Teilnehmerzahl erreicht.", count 2. |
| S2-16 | PASS | 8 seats SVG grid, legend; light/dark/mobile ok (light legend swatches faint). |
| S2-17 | PASS | assignment row; UI "Mein Platz" + "Platz freigeben". |
| S2-18 | PASS | toast "Dieser Platz ist bereits vergeben."; no change. |
| S2-19 | PASS | unregistered user POST claim -> 403 (UI hides claim: canClaim false). |
| S2-20 | PASS | release + claim other seat ok. |
| S2-21 | PASS | state `not_open`, "Check-in öffnet am So., 01.11., 05:40 Uhr" (= 12h before 17:40 Berlin). |
| S2-22 | PASS | qa-near: check in sets `checked_in_at`; UI "Du bist eingecheckt. Seit 17:43 Uhr." |
| S2-23 | PASS | second POST keeps timestamp. |
| S2-24 | PASS | 404 German page. |
| S2-25 | PASS | `not_registered` -> register via event POST (redirect back to check-in) -> `ready` -> check-in ok. |
| S2-26 | PASS | max=1 filled: state not_registered/availability `full`, register POST rejected (count 1). UI text not screenshotted. |
| S2-27 | PASS (note) | Registered user on finished event: state `over`, POST does not check in. Unregistered: "Du bist noch nicht angemeldet / keine Anmeldung möglich, wende dich an die Orga" (not the "event is over" text). |
| S2-29 | PASS | public profile name/bio/color, no email in HTML. |
| S2-30 | PASS | all fields saved + shown publicly. |
| S2-31 | PASS | 7 invalid variants -> 422 German (email, bio 1000, stream https-only, color, name, duplicate email, steam host); nothing saved. Duplicate-email message reveals account existence (accepted LAN risk). |
| S2-32 | PASS | Hell/Dunkel/System; light persists across navigation. |
| S2-33 | PASS | `providers=[]` -> empty copy "Aktuell ist kein Anbieter ... verfügbar" (creds unset). |
| S2-34 | MANUAL | Needs real Steam/Twitch OAuth. Owner: link + unlink Steam and Twitch once, confirm `needs_reauth` banner copy. |
| S2-35 | PASS (note F-S2-7) | factory row; DELETE removes it. |
| S2-36 | PASS | battlenet/foo -> 404; steam/twitch -> 302 to provider even when not offered (F-S2-7). |
| S2-37 | PASS | passwordless -> 302 /settings/profile. |
| S2-38 | FAIL (F-S2-1) | Flow ok (423/302 confirm-password, 201 on correct, wrong current pw 422 German), but confirm-password error is English. |
| S2-39 | PASS | wrong pw 422; correct -> user + registrations gone, session ended (401). |
| S2-40 | PASS | team + owner member row. |
| S2-41 | PASS | empty/65/17 chars 422 German. Duplicate name accepted (F-S2-4). Label "Tag" vs message "Kürzel". |
| S2-42 | FAIL (F-S2-3) | Pending request created; no notification for owner. |
| S2-43 | PASS | `accept=true` -> member (param is `accept`, not `decision`). |
| S2-44 | PASS | non-owner respond 403. |
| S2-45 | PASS | tag saved; logo stored `team-logos/..png`, served as `/storage/...` URL (200 after `storage:link`); text file rejected "Das Logo muss ein Bild sein." |
| S2-46 | PASS | non-owner edit/patch 403. |
| S2-47 | PASS | member leaves. |
| S2-48 | PASS | owner leave blocked: "Der Kapitän muss die Teamleitung übergeben, bevor er das Team verlassen kann." |
| S2-49 | PASS | dashboard shows registration (status/ticket/checked-in/arrival), seat state, teams with role, Steam link prompt. |
| S2-50 | PASS | all nav/user-menu links 200; menu: Einstellungen, Abmelden (+ Admin-Bereich for orga). |

### Fix-round behaviours (participant side)
- Tournament enrollment needs event registration (Ruling A): unregistered p1 sees "Melde dich zuerst zum Event an" + "Zur Event-Anmeldung" on all open tournaments. PASS.
- Withdraw (Ruling B): withdraw -> `withdrawn`; re-enroll creates new entry; non-entrant withdraw -> 404. PASS (UI shows "Angemeldet" + "Abmelden").
- Team picker (Ruling F): own teams listed, ineligible disabled "(1 von 2 Mitgliedern)", missing `team_id` -> "Wähle ein Team, ...", wrong size -> "Die Teamgröße stimmt nicht ...", non-owner 403, owner enrollment via UI success. PASS (F-S2-4, F-S2-8 notes).
- Cancel block (Ruling C): after check-in `canCancel=false, reason checked_in`; DELETE flashes "Du bist bereits eingecheckt — eine Stornierung ist nur noch über die Orga möglich."; finished event reason `event_over`. PASS.
- Arrival plan (new): covered in S2-08..10; Berlin day options "So, 01.11." etc. PASS.
- Self check-in QR + walk-in: S2-21..27. PASS.
- German error pages: 404 "Seite nicht gefunden", 403 "Kein Zugriff", 419 "Sitzung abgelaufen" styled (light+dark). PASS (F-S2-6).
- Europe/Berlin: dashboard "30.10.2026, 15:40" for 14:40 UTC; check-in times Berlin; Filament inputs Berlin. PASS.

## S3 results

| ID | Result | Evidence |
|----|--------|----------|
| S3-01 | PASS | `/admin`: "Admin" badge, "Zur App", AccountWidget, Filament info, Preflight-Ampel. |
| S3-02 | PASS | Sidebar: Event/Events; Anmeldung & Sitzplan/Sitzplätze; Turniere & Teams/Turniere, Spiele, Teams, Strittige Matches; Nutzer. |
| S3-03 | PASS | `QA-Created Event` created as Draft, slug `qa-created-event`; times stored UTC (18:00 Berlin = 17:00). |
| S3-04 | FAIL (F-S3-1) | Empty name = browser-native "Please fill out this field." (English, browser locale; no `novalidate`). End<start and max 0 saved. |
| S3-05 | PASS | Ankündigen + confirm; toast "Status geändert auf: Angekündigt". |
| S3-06 | PASS | draft 404, after announce 200 availability `upcoming`. |
| S3-07 | FAIL (F-S3-5) | Status registration reached; no notifications created under core. |
| S3-08 | PASS | Status select disabled; only next transition offered each step. |
| S3-09 | PASS | live -> finished -> archived; archived has no transition; finished: register availability `closed`. (F-S3-2 stale header seen.) |
| S3-10 | PASS | name/location persisted; toast "Gespeichert". |
| S3-11 | PASS | Löschen + confirm; row gone; public 404. |
| S3-12 | PASS | modal with SVG QR + `http://127.0.0.1:8000/checkin/<token>`, token row created (F-S3-7). |
| S3-13 | PASS | print sheet: event name, large QR, "QR-Code scannen und einchecken", URL, Drucken. |
| S3-14 | PASS | Token erneuern confirm modal (German); old token 404, new 200. |
| S3-15 | PASS (note) | host = request host, not APP_URL (F-S3-7). |
| S3-16 | PASS | helper: scanner 200 (camera box blank without camera, manual token input present); helper blocked from QR print (403) and `/admin` (403); participant 403. |
| S3-17 | PASS | success toast "QA-P1 Renamed eingecheckt."; DB set. |
| S3-18 | PASS | garbage/other-event token "Kein Ticket mit diesem QR-Code ..."; cancelled "Diese Anmeldung ist nicht aktiv."; empty 422; >64 chars 422. |
| S3-19 | PASS | "Dieser Teilnehmer ist bereits eingecheckt." |
| S3-20 | PARTIAL | Table: Teilnehmer/Ticket/Status/Bezahlt/Eingecheckt, search, row actions Stornieren + Bezahlt umschalten; CSV export not downloaded (no download permission); see F-S3-9. |
| S3-21 | PASS | tiles 7 / 5 / 2 / 0 / 13 free; stacked chart per day/window + "Unbekannt" block; expected-present chart peak 5 at Mo 02.11. nach 22 Uhr; table. |
| S3-22 | PASS | filters present (day, window, "Ohne Ankunft"); "Ohne Ankunft" -> exactly QA-P3, QA-P7; sortable columns, search. |
| S3-23 | PASS | hand calc (active 7; placed 5 incl. P4,P6,P1,P2,P5; P3 unknown, P7 departure-only unknown; cancelled P8 excluded; peak 5; end-of-event 2) equals UI. |
| S3-24 | PASS | participant 403, helper 403, orga 200. |
| S3-25 | PASS | Raster anlegen qa-small 2x3 prefix S -> 6 seats ("6 Plätze angelegt."); re-run no duplicates. |
| S3-26 | PARTIAL | Create form: Switch-Port/IP fields, IP validated ("IP-Adresse muss eine gültige IP-Adresse sein."); edit/visibility of network meta not exercised. |
| S3-27 | FAIL (F-S3-4) | 500 UniqueConstraintViolation. |
| S3-28 | N-A | No release action seen in Seats admin (list shows "Belegt durch" only). Recorded as gap. |
| S3-29 | PARTIAL | Teams list/search ok (name, tag, owner, member count, Bearbeiten); create/edit/delete not exercised. |
| S3-30 | PASS (F-S3-6) | `QA-Game` created (min1/max2), deleted though used by 2 tournaments -> game_id null. |
| S3-31 | PASS | `QA-Admin SE` on qa-live created as `draft`; Berlin times round-trip (10:00 entered = 09:00 UTC). |
| S3-32 | PASS (fixed) | Ruling E: edit page shows status badge "Entwurf" + "Anmeldung öffnen" (confirm text explains checks); -> "Anmeldung offen"; header now "Zur Check-in-Phase", "Anmeldung schließen", "Turnier starten"; "Anmeldung schließen" disappears once an entry exists; list rows carry the same actions; participants can then enroll. |
| S3-33 | PARTIAL | Entries manager: Zurückgezogen / Angemeldet rows, row actions Einchecken/Zurückziehen; check-in correctly refused during enrollment; withdraw action not clicked (covered participant-side). |
| S3-34 | PASS | `/admin/users` list + view, linked accounts relation, no edit/delete. |
| S3-35 | PASS | no role control (by design). |
| S3-36 | BLOCKED | No disputed match (needs S4-14). Queue page loads with empty state "Keine game matches" (English, F-S3-3). |
| S3-37 | PASS | widget rows equal CLI; OK/Übersprungen/Ausfall labels German; incl. new Backup row. |
| S3-38 | PASS | CLI exit 1 "2 system(s) down" with Scheduler/Queue-Worker DOWN until `lanomat:heartbeat`; then exit 0; failed job -> WARN (exit 0). Backup marker (unix timestamp in file content): 1h OK, 30h WARN "vor 30 Stunden", 60h DOWN exit 1, garbage WARN "unlesbar", empty/absent SKIP locally. |
| S3-39 | PASS | with failed job: `SystemUnhealthy` ("Systemwarnung") to admin+orga (not helper); re-run no duplicate. |
| S3-40 | PASS | participant/helper `/admin` 403; "Zur App" and "Abmelden" present in panel; app user menu for orga has "Admin-Bereich". |
| S3-41 | N-A | Requires `MODULES_PROFILE=full`; not run (task scope = core). |
| S3-42 | PASS | orga "Stornieren" row action (confirm modal) cancels; token then rejected by scanner; participant cannot cancel other registrations (own DELETE only). |
| S3-43 | PASS | `lanomat:install` prints "Admin ready: QA-Admin (...)". |

### Fix-round behaviours (admin side)
- Admin "Admin" badge, "Zur App": PASS. Preflight widget incl. Backup: PASS. Tournament lifecycle actions (Ruling E): PASS. Orga "Stornieren" (Ruling C): PASS. Arrival overview: PASS. Check-in QR modal/print/rotate: PASS. Europe/Berlin in Filament inputs: PASS (F-S3-8 for display format).
- Mobile (390px) admin: no page-level horizontal overflow on any admin page; tables scroll inside their container (Filament default).

## Not covered / owner to test manually
- Real Discord/Steam/Twitch OAuth (S2-34): link/unlink, reauth banner.
- CSV export download (S3-20), camera QR scanning on a real phone, S3-41 full profile, S3-36 dispute override (needs S4), Teams admin create/edit/delete, seat edit form.
- Real device check of the print sheet (browser print dialog).

## New open questions (recommended default)
1. OQ-N1: Should `/events` list all non-draft upcoming events (not just the current one + archive)? Default: yes, list upcoming/registration events.
2. OQ-N2: Team names unique? Default: unique (case-insensitive) name, or show tag in picker.
3. OQ-N3: Should core send an in-app "Anmeldung offen" notification (move out of Discord listener)? Default: yes.
4. OQ-N4: QR/check-in URL base: `APP_URL` instead of request host? Default: APP_URL.
5. OQ-N5: Notify team owner on join requests (bell)? Default: yes.
6. OQ-N6: Event form validation: ends_at > starts_at, max_participants >= 1 or empty = unlimited? Default: enforce both.
7. OQ-N7: Block game deletion while tournaments reference it? Default: block with German message.
8. OQ-N8: Seat create: validate unique label per event (and position). Default: validation error instead of 500.

## Environment
Started: `php artisan serve` (:8000, background), created DB `lanomat_qa`, `public/storage` symlink (storage:link), file `storage/app/public/team-logos/*.png`, `/tmp/qa` helper files. All removed/stopped/dropped at the end of the run. Note: logging in via `localhost:8000` in Chrome overwrote that origin's dev session cookie (`lanomat-session`) and set the `appearance` cookie (reset to system).
