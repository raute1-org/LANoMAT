# QA run: S4 (tournaments end to end) + S6 (realtime)

Branch under test `fix/qa-round1-ops`, profile `core`, throwaway DB `lanomat_qa` (dropped at the end). Env via shell overrides: `CACHE_STORE=file QUEUE_CONNECTION=database BROADCAST_CONNECTION=reverb`, own REVERB_* values (key `qakey123`), `REVERB_ALLOWED_ORIGINS=localhost:8000,localhost,127.0.0.1:8000,127.0.0.1` (later restricted to `localhost:8000,localhost` for S6-06). Processes on host: `artisan serve` (4 workers) :8000, `reverb:start` :8081, `queue:work --tries=1` (database queue), vite dev :5173. Browser: `localhost:8000` = orga (admin), `127.0.0.1:8000` = participants (p1, later p5/p4). HTTP-level checks via curl cookie jars (JSON login, Inertia flash read-back); engine sweeps via tinker with the real Actions (`EnrollSolo`, `StartTournament`, `OverrideMatchResult`).

Counts
- S4: 32 cases: PASS 24, PARTIAL 6 (S4-02, S4-10, S4-21, S4-22, S4-25, S4-31), FAIL 2 (S4-09, S4-26).
- S6: 9 cases: PASS 6 (S6-01..04, S6-06, S6-07), FAIL 1 (S6-05), N-A 2 (S6-08, S6-09); extra no-console-error/WSS-config check PASS.
- Findings: 13 (1 Critical, 5 Important, 7 Minor).

## Findings

| ID | Sev | Summary |
|----|-----|---------|
| F-S4-1 | Critical | Report form maps "Ergebnis (du)" to `score1` (= entry1) regardless of who reports. When the reporter is entry 2 the typed own score is credited to the opponent; combined with F-S4-2 the opponent confirms blindly -> wrong winner. |
| F-S4-2 | Important | The opponent who has to confirm/dispute never sees the reported score (card shows only GEMELDET + buttons; `matches.score1/2` stay null until confirmed, no pending-report DTO). Disputed-matches admin table also shows empty Ergebnis 1/2, so orga cannot see the claim. |
| F-S4-3 | Important | Reporter sees "Bestätigen"/"Anfechten" on their own report; click -> full 403 error page (server correctly refuses). |
| F-S4-4 | Important | A single-match single-elimination (n=2) tournament never finishes: after the only match completes status stays `live`, `winner_entry_id` null. Cause `MatchProgression::detectCompletion` returns early when no match has `nextMatch` ("not progressing" = round robin heuristic). |
| F-S4-5 | Important | Round robin never finishes and has no standings anywhere: all 6 matches (n=4) completed, tournament stays `live`/LIVE forever, no table, no winner, no orga "finish" action. |
| F-S4-6 | Important | Starting a SE/RR tournament with < 2 participating entries (e.g. require_checkin and nobody checked in) -> 500 `InvalidArgumentException: Single elimination requires at least 2 entries` in the Filament "Turnier starten" action (only DE has a `TournamentException` guard). Same exception kills the autostart `StartTournamentJob` on EVERY scheduler tick (one failed job per minute) for a tournament past `starts_at` with < 2 checked-in entries. |
| F-S4-7 | Important | "Zur Check-in-Phase" (Ruling E) only flips status; `CheckInEntry` additionally enforces `checkin_opens_at/closes_at`. With a future `checkin_opens_at` participants see the "Einchecken" button (status CheckIn) but get "Der Check-in ist derzeit nicht geöffnet." The orga action should clamp/clear the window or the UI should not offer the button. |
| F-S4-8 | Minor | Participant report form gives no feedback on a 422 (tie 0:0 or invalid): click on "Melden" does nothing visible (no inline error, no toast). Server message exists (`tied_score`). |
| F-S4-9 | Minor | Bracket match numbers are `position + 1` but positions are already 1-based: first match of every round shows "#2" (SE: #2..#5, final "#2"). |
| F-S4-10 | Minor | Realtime resync on reconnect (see F-S6-1) and not-pushed report (F-S6-2) are tracked under S6. |
| F-S4-11 | Minor | Tournament index shows no enrollment counts/capacity (plan S4-02 expects counts) and no "full" cue; tournaments of one event are sorted by `starts_at` incl. finished ones above open ones. |
| F-S4-12 | Minor | Double-elimination grand-final reset (GF2) stays "Ausstehend" with empty slots after the tournament is Finished (WB winner won GF1). It is never marked "Entfällt". Also bye slots in DE LB show score values ("Freilos 0 / Name 1"). |
| F-S4-13 | Minor | Stale second click on "Bestätigen" (already completed) -> raw 404 page instead of a toast; helper "Go" success has no confirmation toast; non-entered user withdraw -> 404. Check-in close < open is accepted by the tournament form (only `OpenTournamentEnrollment` guards it). Finished tournaments show only "Turnier beendet", no champion name/callout. Single elimination accepts n=65 (127 matches) although the module documents 2..64. Filament confirm copy uses "Sie" ("Sind Sie sicher..."). |

S6-specific:

| ID | Sev | Summary |
|----|-----|---------|
| F-S6-1 | Important | No resync on reconnect: after Reverb was down 40 s (match completed meanwhile) the client reconnected correctly (new socket opened ~14 s after restart) but the bracket stayed stale (match shown BEREIT instead of Abgeschlossen) until the NEXT broadcast. Also the `MatchCompleted` broadcast job FAILED while Reverb was down (`BroadcastException`, `--tries=1`, row in `failed_jobs`), so the missed event is lost, not retried. Fix idea: `router.reload` on Pusher `connected` state change (after the first connect). |
| F-S6-2 | Minor | Match report submission (status Reported) is not broadcast: the opponent's open page keeps "BEREIT"+"Melden" until a reload or an unrelated event (no `MatchReported` event on `tournament.{id}`; listener list only match.ready/went_live/completed/score_updated/tournament.completed). |
| F-S6-3 | Minor (info) | The `LiveIndicator` on the tournament header reflects tournament status (LIVE), not the websocket connection state; there is no connection indicator, so a dead socket still shows a pulsing LIVE dot. `tournament.started` is not listened to; the start works only because `MatchReady` fires for round-1 matches. |

## S4 results

| ID | Result | Evidence |
|----|--------|----------|
| S4-01 | PASS | `QA-SE Draft`: POST enroll as p1 -> 403; page shows no Anmelden. |
| S4-02 | PARTIAL (F-S4-11) | `/events/qa-live/tournaments` lists all tournaments with German status labels ("Anmeldung offen", "Entwurf", "Live"); no counts. |
| S4-03 | PASS | p1 enroll -> toast "Erfolgreich angemeldet.", entry row `registered`. |
| S4-04 | PASS | Second enroll -> toast "Diese Person oder dieses Team ist bereits angemeldet."; 1 entry. |
| S4-05 | PASS (Ruling A) | p6/p1 without event registration -> toast "Melde dich zuerst zum Event an."; 0 entries; after registering them enroll works. |
| S4-06 | PASS | `max_entries=2`, third user -> "Das Turnier ist ausgebucht."; withdrawn entries free the slot immediately. |
| S4-07 | PASS (Ruling B) | Withdraw -> "Anmeldung zurückgezogen.", entry `withdrawn`, slot freed, others can enroll, re-enroll creates a new entry; withdraw on live tournament 403; plain team member withdraw 404; non-entered 404. |
| S4-08 | PASS | team_size 3: picker props eligible/ineligible; UI shows disabled "Mit Team anmelden" + hint "Keines deiner Teams hat genau 3 Mitglieder." for p4; no team_id -> German toast; owner OK; foreign team 403; non-owner member 403; ineligible size -> "Die Teamgröße stimmt nicht ... überein."; owner withdraw OK. Plain member sees no entry state (known F-S2-8). |
| S4-09 | FAIL (F-S4-6) | Admin start with 0 checked-in entries -> 500 InvalidArgumentException (screenshot of Laravel error modal). |
| S4-10 | PASS (tick) / FAIL-partial (F-S4-7) | `lanomat:tournament-tick` moves Enrollment -> CheckIn once `checkin_opens_at` passed; repeat ticks no-op. Manual "Zur Check-in-Phase" works but check-in is still gated by the time window. |
| S4-11 | PASS | Check-in before CheckIn status and after `checkin_closes_at` -> "Der Check-in ist derzeit nicht geöffnet."; inside window p1 (UI) and p2-p6 (HTTP) checked in; unchecked p7/p8 excluded from bracket; withdrawn entry cannot check in. |
| S4-12 | PASS | UI start of SE with 6 checked-in: 7 matches, 3 rounds, 2 byes ("Freilos") auto-completed, round-1 matches Ready; bracket with SVG connectors (52 paths); TournamentStarted reached watcher live. |
| S4-13 | PASS (F-S4-1,2,3 side effects) | p4 report 2:1 -> toast "Ergebnis gemeldet. Warte auf Bestätigung durch den Gegner.", match Reported, `match_reports` pending. |
| S4-14 | PASS | p5 "Anfechten" (keyboard Enter) -> toast "Ergebnis angefochten. Die Orga wird sich darum kümmern.", card "Strittig", listed in `/admin/tournaments/disputes` (scores columns empty, F-S4-2). |
| S4-15 | PASS | Override modal: tie 2:2 -> inline "Ein Match kann nicht unentschieden enden."; 2:0 -> match Completed, winner advanced, watcher updated live; queue empties. |
| S4-16 | PASS | Report by one entrant, confirm by opponent -> Completed, winner advances; double confirm -> 404 (F-S4-13). |
| S4-17 | PASS | tie / negative / letters / 99999999999 / 10000 / empty / 1.5 -> 422 with German messages (`tied_score`: "Ein Match kann nicht unentschieden enden."). |
| S4-18 | PASS | Non-participant report/confirm/dispute -> 403; reporter confirm/dispute own report -> 403; orga report -> 404. |
| S4-19 | PASS | Opposing concurrent reports: one wins (report row + Reported), other rejected (no corrupt state). Confirm with stale `lock_version=5` -> toast "Das Ergebnis wurde inzwischen geändert. Seite neu geladen."; non-integer lock_version 422. |
| S4-20 | PASS | Warmup match (via `EnterWarmup`): participant (in match) POST go 403; helper go -> match leaves Warmup (status Ready, MatchWentLive); go on non-warmup -> toast "Dieses Match ist nicht in der Aufwärmphase."; helper has no success toast (F-S4-13). |
| S4-21 | PASS (n>=3) / FAIL (n=2, F-S4-4) | UI: final confirmed -> tournament `finished`, `winner_entry_id` set; watcher page lost LIVE, shows "TURNIER BEENDET" without reload; no champion callout (F-S4-13). |
| S4-22 | PASS except n=2 | Sweep (all matches played via override): SE n=2 (hang F-S4-4), 3 (3 matches, 2 played), 4, 5 (7 matches/4 played), 8, 16, 33 (63/32 played), 64 (63), all finish with winner; byes auto-completed; n=1 -> raw InvalidArgumentException (F-S4-6); n=65 accepted. |
| S4-23 | PASS | DE n=8: 15 matches (7 WB, 6 LB, 2 finals); fav-wins path ends after GF1 (GF2 stays pending, correct per ruling), dog-wins/random path plays GF2 and finishes with winner. |
| S4-24 | PASS | DE n=3,4,5,6,8,16,32,33,64 all finish, no stall (n=5: 15 matches/8 played; n=33: 127/65; n=64: 127/126; n=32: 63/63); byes shown "Freilos", dead slots "Entfällt"; see F-S4-12 for GF2/score cosmetics. |
| S4-25 | PASS (DE) / FAIL-partial (SE) | DE n=1 and n=65 -> `TournamentException` "Double elimination is only supported for 2 to 64 participating entries" (German in UI path catch). SE n=65 accepted, SE n=1 500. |
| S4-26 | FAIL (F-S4-5) | RR n=4 -> 6 matches, n=5 -> 10 matches; all playable; tournament never finishes; no standings. |
| S4-27 | PASS | Enrollment tournament with `starts_at` in the past and require_checkin=false: tick -> `StartTournamentJob` -> Live with 3 matches. |
| S4-28 | PASS | Tick x3: no duplicate matches/events (one check-in transition, one start). (Failing-job loop for under-filled tournaments see F-S4-6.) |
| S4-29 | PASS | Two parallel starts: one STARTED, other `TournamentException: already started`; 3 matches. |
| S4-30 | PASS | Draft event `/events/qa-draft/tournaments` -> 404 for p1; unknown tournament 404; guests are redirected to login (302). |
| S4-31 | N-A / PARTIAL | There is no match detail page (`/matches/{id}` 404); the bracket card is the match UI: entries, scores, actions per role verified (Melden / Bestätigen+Anfechten, none for spectators). |
| S4-32 | PASS | Core: no voice join link, no Discord buttons on the tournament page, no console errors. |

Extra UI checks (bracket)
- SVG connectors: present between rounds in light/dark (52 paths for a 6-entry SE, DE 5 has 9 columns), correctly routed.
- 390 px width (iframe): document scrollWidth = clientWidth (no page overflow); the bracket container is `overflow-x-auto` (2424 px content in 341 px). PASS.
- Dark default and light theme: both readable, accent amber for "Abgeschlossen" badges (heavy use of the accent on every completed card, compare design rationing; style note).
- Keyboard: Melden, Bestätigen, Anfechten are real `<button>`s; Enter on the focused "Anfechten"/form submit via Tab+Enter works. Focus ring could not be confirmed (programmatic focus shows `outline: none`, no `:focus-visible` ring evidence) -> INCONCLUSIVE.
- German copy fine; report inputs have `aria-label` "Ergebnis (du)/(Gegner)" (misleading, F-S4-1).
- Bell badge updates live (match ready notification).

## S6 results

| ID | Result | Evidence |
|----|--------|----------|
| S6-01 | PASS | Reverb on :8081 reachable from both origins; client socket established on the tournament page (ss shows ESTAB 8081, window.__reverb = {key, host localhost, port 8081, scheme http} from the server config). `read_network_requests` did not list WS frames. |
| S6-02 | PASS | Watcher page (not started, "Das Turnier hat noch nicht begonnen.") switched to the bracket without reload when orga started from Filament (and again via tinker). Reload marker `window.__qa_marker` survived. |
| S6-03 | PASS | Override and report+confirm: match shows Abgeschlossen with scores, winner placed in next round, next match BEREIT, all without reload (marker kept). Report-only is not pushed (F-S6-2). |
| S6-04 | PASS | Final confirmed: watcher lost LIVE and shows "TURNIER BEENDET" without reload. |
| S6-05 | FAIL (F-S6-1) | Reverb killed ~40 s: sockets error/close with growing retry interval (no console error loop), reconnected after restart (open @14 s); stale bracket until next event; missed broadcast job failed in `failed_jobs`; the next override resynced everything. |
| S6-06 | PASS | With `REVERB_ALLOWED_ORIGINS=localhost:8000,localhost`, a socket from origin `http://127.0.0.1:5173` gets `pusher:error 4009 "Origin not allowed"`. Note: Reverb compares the host and ignores the port (with `127.0.0.1` in the list `127.0.0.1:5173` was accepted), and the HTTP upgrade itself always returns 101 (rejection happens in-protocol). Default `.env` therefore blocks `127.0.0.1:8000` pages. |
| S6-07 | PASS | `/broadcasting/auth` as p1: own `private-App.Models.User.5` 200, other user's channel 403, `private-tournament.1` 403, guest 403. |
| S6-08 | N-A | Presence is a full-profile module; not run under core. |
| S6-09 | N-A | `npm run smoke` covers presence/jukebox routes (full profile) and needs ScreenshotSeeder; not run under core. |
| S6-x | PASS | No console errors on tournament pages (also with the origin rejected: Echo fails silently, page renders, no error loop). WSS: server config passes `scheme`/`port` at runtime via `window.__reverb` (`forceTLS` derived from `https`); not exercisable locally (http only). |

## New open questions (recommended default)

- OQ-S4-1: Which score does the report form mean, and who sees it? Default: form fields stay "own : opponent", the controller maps them to entry1/entry2 by the reporter's side; the pending report (scores + reporter) is exposed in the match DTO to both participants and in the disputes table.
- OQ-S4-2: How does a round-robin tournament finish? Default: derive standings (wins, then score diff) in a read model, finish automatically when all matches are completed, set `winner_entry_id` to the top row, show the table on the tournament page.
- OQ-S4-3: Manual "Zur Check-in-Phase" with a future `checkin_opens_at`: Default: the action sets `checkin_opens_at = now()` when it is in the future (or the button is shown only when the window is open).
- OQ-S4-4: Start with < 2 participating entries: Default: throw `TournamentException` (German message) for SE/RR like DE; the scheduler tick skips (and logs once) instead of failing every minute.
- OQ-S4-5: Broadcast delivery when Reverb is down: Default: queue retries for broadcast events (`tries=3`, `backoff`), plus client `router.reload` on reconnect (`connection.bind('connected')`).
- OQ-S4-6: Should SE enforce 2..64 like the docs say? Default: yes, reject > 64 with the same error as DE.
- OQ-S4-7: GF2 after a WB-winner grand final: Default: mark as "Entfällt" (like dead DE slots) once the tournament is Finished.

## Environment changes

Started and stopped (all stopped at the end): `php artisan serve` :8000, `php artisan queue:work` (database queue), `php artisan reverb:start` :8081 (restarted twice, last with restricted origins), `npm run dev` (vite :5173). Created and dropped DB `lanomat_qa` (dev DB `lanomat`, Redis and compose services untouched). Removed `/tmp/qa-seed.php`. No repository files changed except this untracked report; temp scripts/cookie jars live only in the session scratchpad. Chrome: two own tabs opened and closed; a pre-existing tab was left alone.
