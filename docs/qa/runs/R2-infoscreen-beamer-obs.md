# QA round 2 (agent C): infoscreen, beamer, OBS overlays

Scope: infoscreen/beamer scenes (`/screen/{event}`), helper remote (`/screen/{event}/control`), OBS overlays (`/overlay/...`), pre-LAN countdown, recap, Filament scene admin. Environment: local, `MODULES_PROFILE=full`, throwaway database, all external secrets blanked, `BROADCAST_CONNECTION=log`, no Reverb (realtime pushes are MANUAL). Test data: QA seed plus a "big" event (70 registrations, 55 checked in, 70 seats), a 64-player single elimination, a 16-player double elimination with long names, a 6-player round robin, one finished 4-player bracket, all 17 rotation scene types, 30 schedule items, 8 tombola prizes, status signals, a jukebox queue; plus an "empty" event (no scenes) and a "bare" event (all scenes, no data).

Method note: the Chrome window could not be resized (`resize_window` reported success, viewport stayed ~500 px). The beamer was therefore rendered in a same-origin iframe of exactly 1920x1080 and 1280x720 with a DOM audit (font sizes, clipped containers, elements outside the viewport) plus scaled screenshots.

Counts: 33 PASS, 12 FAIL (11 findings), 0 BLOCKED, 6 MANUAL.

## Cases

| ID | Case | Result |
|----|------|--------|
| B-01 | Announcement scene, normal text, 1080p/720p | PASS (60 px headline, nothing outside viewport) |
| B-02 | Announcement, very long headline plus ~500 chars body | PASS |
| B-03 | Announcement, 3000 chars body (no max length) | FAIL(F-R2C-8) |
| B-04 | Announcement headline with HTML/script | PASS (rendered as text, no element created) |
| B-05 | Bracket scene, 64 players | FAIL(F-R2C-1) |
| B-06 | Bracket scene, DE16 with long names | FAIL(F-R2C-1) |
| B-07 | Bracket scene, RR6 / small bracket legibility | FAIL(F-R2C-1) (12-14 px text in a corner) |
| B-08 | Bracket scene with no tournament configured | FAIL(F-R2C-9) |
| B-09 | Upcoming matches, 8 matches, long names | PASS (wraps, 20-30 px) |
| B-10 | Upcoming matches, 32 ready matches | FAIL(F-R2C-4) |
| B-11 | Schedule scene, 33 items | FAIL(F-R2C-3) |
| B-12 | Seatmap, 70 seats, long names | FAIL(F-R2C-6, F-R2C-7) |
| B-13 | Payment QR scene | PASS (256 px QR, caption) |
| B-14 | Sponsors scene without logos | PASS (calm German empty text; see Q-2) |
| B-15 | Tombola scene with draws | FAIL(F-R2C-5) |
| B-16 | Status board (3 components) | PASS (message only on outage override, see Q-4) |
| B-17 | Servers scene empty | PASS |
| B-18 | Presence scene with 55 checked in, 32 "playing" | FAIL(F-R2C-4) |
| B-19 | Now playing, long track title, up next | PASS (truncation OK) |
| B-20 | Gallery empty state | PASS |
| B-21 | Recap scene (live event, finished event) | PASS |
| B-22 | Empty event (no scenes) idle screen | PASS ("Bereit", no error); see Q-3 |
| B-23 | Bare event: every scene type with no data | PASS except bracket (B-08) |
| B-24 | Rotation timing and disabled scene skipped | PASS |
| B-25 | Beamer boots with 0 scenes, scenes added later, reload via `scenes.updated` path | FAIL(F-R2C-2) |
| B-26 | Beamer reachable without login; draft event | PASS (200 guest, 404 draft, also 404 for orga) |
| B-27 | Winner / gong / live scoreboard override scenes, `scene.override` push | MANUAL (needs Reverb) |
| B-28 | Realtime reload on jukebox/gallery/scenes updates | MANUAL |
| B-29 | Layout jump at scene change (fade only, `h-screen`) | PASS (code and rotation observation) |
| B-30 | Reduced motion (SceneFrame `motion-safe`, LiveIndicator `motion-reduce`, confetti checks `matchMedia`) | PASS (code reading only; emulation not available) |
| C-01 | Control page: guest GET 401, participant 403, helper/orga 200 | PASS |
| C-02 | Control POST (show, status, check-in trigger, tombola, food) as guest 401 / participant 403 | PASS (see F-R2C-11 for a 404-before-403 oracle) |
| C-03 | Scene or prize of another event via wrong slug, unknown or non-numeric ids | PASS (404) |
| C-04 | Status validation: bad level, bad component, 5000 chars, empty | PASS (German messages) |
| C-05 | Tombola: draw same prize twice | FAIL(F-R2C-5) |
| C-06 | Control page usability with several scenes of one type | FAIL(F-R2C-10) |
| C-07 | "Orga rufen": 5 ok, 6th and 7th 429; words rules | PASS |
| C-08 | "Orga rufen" validation text | FAIL(F-R2C-11) |
| C-09 | Triggers without confirmation or in-flight disabling | FAIL(F-R2C-5, F-R2C-10) |
| P-01 | HTML source and Inertia props of beamer/overlays: no e-mail, Discord id, user id, network data | PASS |
| P-02 | Seatmap and tombola expose user display names to unauthenticated visitors | FAIL(F-R2C-6), Q-1 |
| P-03 | Filament scene form lets a public beamer show a tournament of another or a draft event | FAIL(F-R2C-12) |
| O-01 | Overlay bracket/scoreboard: `html.overlay`, html and body background rgba(0,0,0,0) | PASS |
| O-02 | No nav/aside/header chrome, no document scroll | PASS |
| O-03 | Visibility: draft event 404; draft tournament 404; unknown/negative/huge/non-numeric ids 404 with minimal body | PASS |
| O-04 | Finished/archived events still serve overlays | PASS (expected) |
| O-05 | Overlay bracket for 64 players and DE16 | FAIL(F-R2C-1) (clipped; DE16 shows a horizontal scrollbar that OBS would capture) |
| O-06 | Scoreboard for pending match (no entrants) shows "—", "0:0" | PASS |
| O-07 | Live score update push | MANUAL |
| R-01 | Recap page: guest redirected to login (route is in the `auth` group), live/draft/unknown event 404 | PASS; see Q-5 |
| R-02 | Recap content and numbers | FAIL(F-R2C-13) |
| R-03 | Pre-LAN countdown on event page (`hype`), ticking, mobile layout | PASS |
| F-01 | Filament scene list/create reachable for orga, German labels | PASS |
| F-02 | Filament create form, empty submit | PASS (browser-native English tooltip only, see Q-6) |
| F-03 | Duration upper bound and ordering | FAIL(F-R2C-14) (no max; list not grouped by event) |
| X-01 | Console on beamer and overlay pages (CSP Report-Only header present) | PASS (no errors, no policy violations logged) |
| X-02 | All beamer/control/recap text in German, no keys | PASS |
| X-03 | No 500 in any request | PASS |

## Findings

### F-R2C-1 (hoch): bracket scenes and bracket overlay never scale to the screen
- Repro: beamer scene `bracket` for a 64-player single elimination (1920x1080); also DE16 and RR6.
- Expected: the bracket is fit to the viewport and readable from distance (calm app, loud beamer).
- Actual: `SceneBracket.vue` wraps `BracketView` in `min-h-0 flex-1 overflow-auto`, fixed size. 64 players: content 1841x4604 in a 1841x968 box, only round 1 rows 1-4 visible plus a visible scrollbar (nobody scrolls a beamer). DE16: 3240x1211 in 1841x968, scrollbars both ways, long names truncated ("QA-Xx_ProGamer_1337…"). Even RR6 renders 12 px status and 14 px names in the top-left quarter of an otherwise empty screen. Overlay bracket: same component; DE16 shows a horizontal scrollbar that OBS captures; 64 players clipped by `overflow-hidden`.
- Cause: `resources/js/components/scenes/SceneBracket.vue` (no scale-to-fit), `resources/js/components/bracket/BracketView.vue:213` (`overflow-x-auto`, fixed card sizes), `resources/js/components/overlay/OverlayFrame.vue`.
- Suggest: scale the rendered bracket with a CSS transform to the available box (measure content, `scale = min(w/cw, h/ch)`), hide scrollbars, and for large brackets show only the current round(s) or paginate.

### F-R2C-2 (hoch): beamer started with zero scenes never starts rotating after scenes appear
- Repro: open `/screen/{event}` for an event with no enabled scenes (shows "Bereit"), add two 3 s scenes, reload the `scenes` prop (what the `scenes.updated` push does). Observed: first scene shown, still on it after 12 s.
- Expected: rotation starts.
- Cause: `resources/js/composables/useSceneRotation.ts`: `scheduleNext()` is called once at setup and returns immediately for an empty list; nothing watches `scenesRef`, so no timer is armed when the list grows (same stale-list issue after any reload: the timer closure keeps the old list).
- Impact: on LAN day the beamer is often powered on before scenes are configured, or scenes are edited while it runs; it freezes on one scene until someone reloads the page (the beamer is unattended).
- Suggest: `watch(scenesRef, () => scheduleNext())` (and clamp `index`).

### F-R2C-3 (mittel): schedule scene shows past items first and cannot show upcoming ones
- Repro: event with 33 items, scene `schedule`, 1080p and 720p.
- Expected: "now/next" cards plus the upcoming items.
- Actual: below the cards a `overflow-auto` list (visible scrollbar) starts at the first item of the day, past ones included; at 1080p about 15 rows fit, at 720p about 7, so upcoming items after that are never visible.
- Cause: `resources/js/components/scenes/SceneSchedule.vue` list `UL.mt-4 flex-1 space-y-3 overflow-auto`; `ScheduleProjection::itemDtos` unfiltered.
- Suggest: filter to now and later, cap the count, no scroller.

### F-R2C-4 (mittel): presence and upcoming-matches lists overflow with many entries
- Repro: 55 checked in, 64-player bracket live (all 32 round-1 matches are Ready, so all "läuft gerade" by the M10 semantics). Scenes `presence` and `upcoming_matches`.
- Actual: presence "Läuft gerade" is a scroll list (4 visible of 32, scrollbar), names truncated ("QA-Spieler 18 vs QA-Spiele…"), "LIVE" badge 12 px. Upcoming matches renders 32 cards in a 2440 px column inside a 1080 px frame with `overflow-hidden`; the last rows are silently cut, no "+N more".
- Cause: `ScenePresence.vue` (`overflow-auto`, `truncate`), `SceneUpcomingMatches.vue` (grid, no cap), `SceneFrame.vue` (`overflow-hidden`).
- Suggest: cap the number of rows (for example 8-12) with "+N weitere", no truncation of the pairing line (wrap).

### F-R2C-5 (hoch): tombola can draw the same prize twice; stale winner replaces the prize board
- Repro (a): helper POSTs `/screen/{event}/control/tombola/{prize}/draw` twice for the same prize (a double click does the same; the buttons have no in-flight disabling or confirm). Result: two `tombola_draws` rows for one prize with two different winners, two reveals on stage.
- Expected: second request refuses ("Preis wurde schon gezogen").
- Cause: `app/Modules/Infoscreen/Actions/DrawTombola.php` has no "prize already drawn" check (the unique key is `(event_id, registration_id)` only; the control page merely hides drawn prizes); `resources/js/pages/Screen/Control.vue` buttons not disabled while processing.
- Repro (b): after the first draw, the rotation scene `tombola` always renders "GEWINNER! <last winner>" with confetti (`reveal = data.lastDraw`), hours later, and the prize board is never shown again (`SceneTombola.vue` `reveal` falls back to `lastDraw`).
- Also: the eligible pool is checked-in registrations not yet winning; cancelled-but-checked-in registrations are not excluded (low).

### F-R2C-6 (mittel, privacy decision): public beamer exposes participant display names
- Repro: guest GET `/screen/{event}` for an event with seatmap and tombola scenes; Inertia props contain `seats[].occupant` (name of every seated participant) and tombola winner names. The login-only seating page is the participant UI for the same data, but the beamer URL is unauthenticated and, in production, reachable from the internet.
- No e-mail, Discord id, user id or network data appears (P-01 PASS). See Q-1.
- Cause: `app/Modules/Seating/Support/SeatProjection.php:dto` (`occupant`), `ScenePayload::seatmapData/tombolaData`.

### F-R2C-7 (gering): seatmap names are 13 px and truncated
- Seatmap cells show `QA-Maximi…` at 13 px (labels 16 px), unreadable from distance, and truncated to about 8 characters. `SceneSeatmap.vue` (SVG viewBox scales but text is tiny for 70 seats). Consider labels only plus occupied/free colour, or names for the highlighted seat only.

### F-R2C-8 (gering): announcement text has no length limit and can push the headline off screen
- Repro: Filament `body` Textarea (no max), 3000 chars: the vertically centred block exceeds 1080 px, headline at y = -454..-334 (invisible), body cut at both ends (`overflow-hidden`). Cause: `InfoscreenSceneForm` `Textarea::make('body')` without `maxLength`, `SceneAnnouncement.vue`/`SceneFrame.vue`. Suggest `maxLength(600)` plus `line-clamp`.

### F-R2C-9 (gering): bracket scene without a tournament shows only a heading
- Repro: scene type `bracket` with no tournament selected (the Select is optional). Shows "Turnierbaum" with nothing below for the full duration; the "bare" event rotation spends 3 slots on blank screens. Expected an empty text like the other scenes, or the form should require the tournament for bracket and upcoming matches.

### F-R2C-10 (gering): control page cannot tell scenes apart; actions have no confirm
- Three "Turnierbaum" entries and two "Ansage" entries are listed with only type and "Inaktiv/Aktiv", no tournament name or headline, so a helper cannot pick the right "Sofort einblenden". "Check-in öffnet" (notifies every registrant, 70 bells plus Discord DMs) and tombola draws are single clicks without confirm. `Screen/Control.vue`, `ScreenControlController::index`.

### F-R2C-11 (gering): copy and oracle nits
- "Orga rufen" with 41+ characters returns "Words darf höchstens 40 Zeichen lang sein." (raw field name `Words`; no German attribute name for `words`).
- Participant POST `.../control/triggers/food-ready/{id}` returns 404 for a non-existent id but 403 for an existing resource (route binding runs before the `role:helper` middleware); only reveals existence of a food order id.

### F-R2C-12 (gering): scene form can point a public beamer at any tournament
- `InfoscreenSceneForm` Tournament `Select` lists tournaments of all events, drafts included, and `ScenePayload::bracketData/upcomingMatchesData` apply no event or visibility check. A bracket scene on a public event can therefore show a tournament of another event (verified: event "live" showed the bracket of another event's tournament) or one belonging to a draft (non-public) event. A draft tournament has no matches so nothing leaks today; a started tournament of a draft event would. Orga-only action, so low. Suggest filtering the options and the payload to `event_id`.

### F-R2C-13 (gering): recap numbers
- "Teilnehmende" is `registrations()->count()` (all rows, cancelled and no-shows included): 70 shown with 55 checked in. "Turniere" counts every visible tournament (4) while the podium list has 1 finished. `RecapProjection.php:34-35`. Also the controller docblock says "no auth required" but the route is in the `auth` group (Q-5).

### F-R2C-14 (gering): scene admin details
- `duration_sec` has a minimum (3) but no maximum; a value above about 2.1 million seconds overflows `setTimeout` (fires immediately). The list is not grouped or filtered by event (36 rows mixed across events).

## Environment notes (for the owner)

- The scratchpad directory was shared with the two parallel QA agents; an environment file was overwritten by another agent and one seed script of mine ran against that agent's throwaway database (`lanomat_qa_r2a`) before I noticed: it added `qa-big*@example.test` users (70), three events (`qa-big`, `qa-empty`, `qa-bare`) and their registrations/seats, and re-ran the idempotent QA seed there. I could not remove them (the cleanup was blocked), please drop or reseed that database. No other database except my own was touched.
- Cookies are per host, not per port: three agents on `127.0.0.1` with different ports overwrote each other's session cookie (an orga login returned 403 on the control page). Using a distinct `*.localhost` host name per agent fixed it; worth one line in the QA README for parallel runs.

## Questions for the product owner (recommended defaults)

- Q-1: Should the unauthenticated beamer show names of seated participants (seatmap) and tombola winners? Default: tombola winner name yes (it is the point of the reveal); seatmap shows seat labels and occupied/free only, names only on the participant seating page. Also consider an optional token or LAN-only access for `/screen/*` in production.
- Q-2: Should an empty scene (sponsors without logos, gallery without photos, bracket without tournament) be skipped from the rotation instead of showing a technical empty text on the big screen? Default: skip empty scenes.
- Q-3: Idle beamer (no scenes) shows only "Bereit". Default: show event name and a calm logo.
- Q-4: The status board lists only OK/Eingeschränkt/Ausgefallen; the optional message is shown only in the outage override. Default: show the message under the component on the board too.
- Q-5: The recap page requires login (route in the `auth` group) while the docblock and the QA brief call it public. Default: keep login-required (ruling "login required"), fix the docblock.
- Q-6: Native browser validation on the Filament form is shown in the browser locale (English "Please select an item in the list."). Default: accept, or add `novalidate`.
- Q-7: Should the beamer be previewable by orga while the event is still a draft? Currently 404 for everyone (also orga). Default: allow orga/helper to preview a draft event's beamer when logged in.
