# QA round 2, agent A: tournaments and teams (deep tests)

Scope: formats and field sizes, team tournaments and teams, lifecycle guards, result flow including concurrency, realtime, visibility, mobile, Filament admin, German copy.
Environment: throwaway database, profile `full`, all external secrets blanked, own app port, own Reverb and queue worker. Participants were the seeded `qa-p*` identities (`example.test`). Production was not touched.
Method: the domain and actions were driven through tinker (formats, matrices, races with two to three PHP processes released at the same instant), the HTTP layer through curl with session cookies (guards, validation, policies), Filament through Livewire test calls, the live page and realtime through the browser (two tabs, two users).

Deviation: after the first Match-Ready events the queue worker sent real, unauthenticated HTTP requests (see F-R2A-12). From then on the Discord, Voice, GameServers, Hosts, Lancache, Provisioning and CustomServers modules were switched off for the run (`MODULE_*=false`). Nothing else in the scope depends on them.

Result key: PASS / FAIL(F-R2A-n) / BLOCKED / MANUAL.

## Cases

### Formats and field sizes (all played to the end through submit + confirm)

| # | Case | Result |
|---|------|--------|
| 1 | Single elimination n=3, 5, 8, 13, 2, 64: matches 3/7/7/15/1/63, byes completed at start (1/3/0/3), champion = expected (lowest id always wins), status `finished`, winner set | PASS |
| 2 | Single elimination n=13 with highest-id-wins: champion = highest id | PASS |
| 3 | Double elimination n=5, 9, 16, 33, 8, 6, 13, 2, 4: every bracket finishes, `finished`, champion correct for low-wins and high-wins | PASS |
| 4 | Double elimination, loser-bracket champion wins grand final 1 (n=4, 5, 8, 9, 16, 33): reset match is played, champion = reset winner, no unfinished match left | PASS |
| 5 | Double elimination, winners-bracket champion wins grand final: reset stays unplayed ("Entfällt"), tournament finishes | PASS |
| 6 | Double elimination n=9 / 33 byes: 11 / 47 bye cards, no stuck `pending` match except the unused reset | PASS |
| 7 | Round robin n=4 (6 matches) and n=5 (10 matches): all ready at start, finishes after last result, standings shown, winner = first row | PASS |
| 8 | Round robin three-way tie: shared rank 1 for three entries, a single winner is declared by lowest entry id | PASS (documented in ruling H) / question Q-R2A-2 |
| 9 | Bracket page after start, after a result, at the end (browser, 500 px and 1280 px): byes shown as "Freilos", scores, champion box "Sieger" | PASS |
| 10 | Tournament pages of 127-match double elimination render in about 0.13 s, 64 kB | PASS |

### Team tournaments and teams

| # | Case | Result |
|---|------|--------|
| 11 | Team creation: duplicate name case-insensitive, name 65 chars, empty tag, tag 17 chars rejected; `<script>` name stored but rendered escaped | PASS |
| 12 | Join request, duplicate request, own team, stranger answers request (403), request from another team (403) | PASS |
| 13 | Owner enrolls team of required size; member (403) and non-member (403) cannot; missing/invalid `team_id` gives German toast | PASS |
| 14 | Roster size mismatch (3 members, size 2) refused with German message | PASS |
| 15 | Owner without event registration blocked (Ruling A); member without event registration does not block (Ruling A, owner only); cancelled registration blocked, pending allowed | PASS |
| 16 | Same team twice refused | PASS |
| 17 | Same persons in two different team entries of one tournament | FAIL(F-R2A-5) |
| 18 | Member leaves a team that is already entered | FAIL(F-R2A-6) |
| 19 | Owner leaves own team: refused with German toast | PASS |
| 20 | Owner transfer: reachable from UI | FAIL(F-R2A-7) |
| 21 | Owner transfer through the action while the team is entered: new owner reports, old owner gets 403, old owner loses entry controls | PASS |
| 22 | Join request answered twice | FAIL(F-R2A-9) |
| 23 | Logo upload: PNG, JPEG, GIF (becomes PNG, first frame), PNG with `.exe` name accepted; SVG, text file renamed `.png`, PHP file renamed `.png` / `.php` rejected with German message; 20000x20000 pixel bomb rejected with German message; old logo file deleted on replace | PASS |
| 24 | Logo EXIF/GPS: JPEG with Make + GPS latitude uploaded, stored file has no EXIF | PASS |
| 25 | Logo larger than 2 MB: German message, but the text is "Das Logo konnte nicht hochgeladen werden." because the dev PHP limit (2 MB) fires before the Laravel rule; the production php.ini is 1G, so the size rule message applies there | MANUAL (verify the production message once) |

### Lifecycle guards

| # | Case | Result |
|---|------|--------|
| 26 | Action x status matrix (announce, open enrollment, open check-in, start, reopen to draft, enroll, duplicate enroll, check in, withdraw) over draft, announced, enrollment, check_in, live, finished: every forbidden transition refused with a German-keyed `TournamentException`, allowed ones succeed | PASS |
| 27 | Double start with three processes at once: one wins, two get "already started", 3 matches created once | PASS |
| 28 | Enrollment race: 8 concurrent enrolls (two users twice) into max 2: exactly 2 entries, rest "full" | PASS |
| 29 | Direct HTTP: enroll in draft, announced, check_in, live gives 403; enroll without event registration gives toast "Melde dich zuerst zum Event an." | PASS |
| 30 | Check-in window: open ok, window in the future refused, window in the past refused, enrollment phase refused, no entry gives 404 | PASS |
| 31 | Withdraw: in live 403, without entry 404, in enrollment ok, second withdraw 404, re-enroll ok | PASS |
| 32 | Admin buttons per status: draft has "Ankündigen" + "Anmeldung öffnen"; announced "Anmeldung öffnen" + close; enrollment "Zur Check-in-Phase" + "Turnier starten"; check_in "Turnier starten"; live/finished none | PASS |
| 33 | Admin "delete" offered in every status incl. live and finished, deletes the tournament and all matches | FAIL(F-R2A-11) |
| 34 | Admin entry "withdraw" button visible in live/finished (always fails there) | FAIL(F-R2A-11) |

### Result flow

| # | Case | Result |
|---|------|--------|
| 35 | Report, opponent confirm; reporter confirms own report (403); reporter disputes own (403); stranger confirm (403); participant of another match (403); guest 419 without token | PASS |
| 36 | Report by stranger / other match participant / pending round-2 match: 403; unknown match 404 | PASS |
| 37 | Invalid scores: negative, tie, 99999999999, `abc`, `1.5`, empty, 10000, missing score2, `1e3`, 0:0 all rejected, nothing stored | PASS |
| 38 | Array-valued score (`score1[]=1`) on report and on correct | FAIL(F-R2A-8) |
| 39 | Confirm with stale / missing / non-numeric `lock_version`: German "Das Ergebnis wurde inzwischen geändert" or validation, no state change | PASS |
| 40 | Correct a pending report (new `lock_version`), opponent's old page then stale; correct tie rejected; correct by non-reporter 403; withdraw gives ready match; dispute after withdraw gives friendly info toast | PASS |
| 41 | Dispute flow: reporter waits, opponent disputes, orga override (Filament) 3:0 completes and advances; tie, negative, 10000 rejected with German messages | PASS |
| 42 | Two confirms at the same instant (two processes, 10 rounds): exactly one wins, other gets stale-match error, advance happens once | PASS |
| 43 | Confirm vs withdraw at the same instant (10 rounds): withdraw always wins consistently, match back to ready, no advance | PASS |
| 44 | Both sides submit a report at the same instant (10 of 10 rounds) | FAIL(F-R2A-3) |
| 45 | Confirm vs dispute at the same instant (10 of 10 rounds) | FAIL(F-R2A-4) |
| 46 | Orga override on `ready` / `reported` match (no-show), orga correction of a confirmed result | FAIL(F-R2A-1) |
| 47 | `OverrideMatchResult` on completed match and on pending match (action level) | FAIL(F-R2A-2) |
| 48 | Report copy when the match is already reported ("noch nicht bereit") | FAIL(F-R2A-13) |
| 49 | Report form wording "du : Gegner" is stored from the reporter's side: report 3:1 by entry 2 shows as 1:3 to the opponent and confirm crowns the right winner | PASS |

### Realtime (own Reverb)

| # | Case | Result |
|---|------|--------|
| 50 | Two tabs, two users: report in tab 1, tab 2 shows the claimed result and Confirm/Dispute without reload | PASS |
| 51 | Reverb stopped: after about 8 s the page shows "Verbindung unterbrochen, aktualisiere nach dem Wiederverbinden" next to LIVE, the page stays usable; a confirm during the outage succeeds over HTTP | PASS |
| 52 | Reverb restarted: both tabs reconnect within about 30 s, the hint disappears and both show the current state (completed match, next round ready) | PASS |
| 53 | Console errors during the realtime session | PASS (none logged) |

### Visibility

| # | Case | Result |
|---|------|--------|
| 54 | Guest: tournament pages, event tournament list redirect to login; overlay of a live tournament is public by design (200), overlay of a draft 404 | PASS |
| 55 | Draft invisible for participants (show 404, absent from the list, overlay 404); orga sees it (200) | PASS |
| 56 | Announced visible to a participant without registration, listed with status `announced`, sign-up refused | PASS |
| 57 | Direct enroll POST on a draft answers 403 although the page answers 404 | FAIL(F-R2A-14) |

### Mobile (500 px viewport, the browser window did not shrink below it)

| # | Case | Result |
|---|------|--------|
| 58 | Live bracket and inline report form (two score fields + "Melden") fit without horizontal page scroll (scrollWidth 485 of 500) | PASS |
| 59 | Touch targets: back link 20 px, sidebar toggle 28 px, "Melden" 32 px with a mouse pointer; the 40 px rule only applies under `pointer-coarse` | MANUAL (check on a real phone) |

### Filament admin

| # | Case | Result |
|---|------|--------|
| 60 | Create with empty form: German "… ist erforderlich." per field (server side); the browser additionally shows its native English "Please fill out this field." depending on browser language | PASS / MANUAL (browser locale) |
| 61 | team_size 0 / -1, name 300, unknown format, check-in closes before opens: German messages | PASS |
| 62 | `max_entries` -5, `team_size` 999999, `max_entries` 100000 accepted; `max_entries` 3000000000 gives a database error (500) | FAIL(F-R2A-10) |
| 63 | Override form validation (tie, negative, 10000) in German | PASS |

### German copy, errors

| # | Case | Result |
|---|------|--------|
| 64 | No translation keys or English raw text found in the props of the tournament, team, index and edit pages (scan), toasts all German | PASS |
| 65 | 500 responses seen: array score on report and correct only (F-R2A-8) plus the admin integer overflow (F-R2A-10) | see findings |

Totals: 53 PASS (incl. PASS with notes), 13 FAIL, 0 BLOCKED, 3 MANUAL (rows 25, 59, 60 browser part).

## Findings

### F-R2A-1 (hoch, candidate for blocker): orga cannot decide a no-show or correct a confirmed result
- Repro: start a tournament, let one player not show up. The match is `ready`; the present player reports, the absent one never confirms. In the admin panel look for any way to decide the match.
- Expected (Ruling S): "The orga override works on a ready, reported or disputed match."
- Actual: the only entry point is the "Konflikte" page (`ManageDisputes`), whose table lists only `disputed` matches. There is no admin action for `ready` or `reported` matches, none for correcting an already confirmed result, no disqualification, and the entry "withdraw" action refuses in live (`WithdrawEntry` -> already started). A route-level confirm by an orga returns 404 (`entryForMatch` is null). The bracket stays blocked; only a database edit helps.
- Cause: `app/Modules/Tournaments/Filament/Resources/Tournaments/Pages/ManageDisputes.php:~75` (`->where('status', Disputed)`) is the only caller of `OverrideMatchResult`.
- Note: see F-R2A-2 before exposing the override on completed matches.

### F-R2A-2 (mittel, latent until F-R2A-1 is fixed): `OverrideMatchResult` is not safe for completed or pending matches
- Repro (tinker, orga logged in): finish a 4-entry single elimination; override the final with the other entry as winner. Also override a `pending` final.
- Expected: tournament champion follows the corrected result, downstream matches are re-evaluated or the action refuses; a pending match gives a German error.
- Actual: the final shows the new winner but `tournaments.winner_entry_id` stays on the old champion and the tournament stays `finished` (inconsistent champion). Downstream matches already played from the old result are not re-evaluated. Overriding a `pending` match throws a raw `InvalidArgumentException: Cannot propagate a match without a recorded winner.` (would be a 500 in the UI).
- Cause: `app/Modules/Tournaments/Actions/OverrideMatchResult.php` has no status/entry check and `MatchProgression::detectCompletion` returns early for finished tournaments (`Support/MatchProgression.php`, `detectCompletion`).

### F-R2A-3 (mittel): both players reporting at the same moment leaves an orphan report
- Repro: in a ready match both participants click "Melden" within the same second (reproduced 10 of 10 times with two processes releasing at one instant; plain HTTP clients did not hit it because the dev server is slow to overlap).
- Expected: the second submit is refused ("already reported").
- Actual: two `pending` reports and match `reported`. The latest report is the only one the page uses. The author of the older report sees only the opponent's claim, cannot withdraw their own (403, `withdrawReport` checks the latest report) and can only confirm the opponent's claim (conceding) or dispute. After confirm, the older report stays `pending` forever.
- Cause: `Actions/SubmitMatchReport.php` checks `match->status` on an unlocked model and saves without a row lock or `lock_version` check.

### F-R2A-4 (gering to mittel): confirm and dispute at the same instant give an incoherent match
- Repro: opponent of a pending report triggers confirm and dispute together (double click on two buttons, two tabs). 10 of 10 rounds with two processes.
- Actual: match status `disputed` (appears in the orga conflict list) while the report is `confirmed`, score and winner are set and the winner already advanced into the next round.
- Cause: `DisputeMatchReport` has no lock and does not check/bump `lock_version`; `ConfirmMatchReport` and `DisputeMatchReport` use the status of a report model loaded before the transaction (`Actions/DisputeMatchReport.php`, `Actions/ConfirmMatchReport.php`).

### F-R2A-5 (mittel): the same persons can play twice in one team tournament
- Repro: team A (users 1+2) and team F (users 2+1, identical roster, different owner). Enroll both in the same 2v2 tournament, check in, start.
- Expected: a person can be in one entry per tournament (as in solo enrollment).
- Actual: both enroll; the first match is "QA-Team-A vs QA-Team-F", the same two people against each other. A user in two enrolled teams would also have two matches possibly at the same time.
- Cause: `Actions/EnrollTeam.php` only checks `team_id` duplicates, not the roster users against other entries.

### F-R2A-6 (mittel): a member can leave an entered team and the entry is not re-validated
- Repro: team of 2 enrolled in a 2v2 tournament; the member leaves the team (`DELETE /teams/{id}/leave`); check in and start.
- Actual: the entry keeps `roster_snapshot` with two names, the team now has one member (picker marks it not eligible), check-in and start still work, the team plays with one player.
- Cause: `LeaveTeam` has no tournament check; neither `CheckInEntry` nor `StartTournament` compare team size.

### F-R2A-7 (mittel): owner transfer has no entry point
- Actual: `TransferOwnership` is used nowhere outside its class (no route, no Filament action). The owner cannot leave ("must transfer first"), cannot transfer, and the UI has no team delete or member removal. The Filament team form has an owner select (not tested what it does to the membership rows).
- Expected (feature list): owner transfer while a team is entered.

### F-R2A-8 (gering): array-valued score gives a 500
- Repro: authenticated participant of a ready match: `POST /matches/{id}/report` with `score1[]=1&score2=2`; same for `/report/correct`.
- Actual: HTTP 500, log "Array to string conversion" at `vendor/laravel/framework/src/Illuminate/Validation/Rules/NotIn.php:50`.
- Cause: `Rule::notIn([$this->input('score1')])` in `Http/Requests/SubmitReportRequest.php` (and `CorrectReportRequest`). Fix idea: validate `score1` as integer first or use `different:score1`.

### F-R2A-9 (gering): a join request can be answered again
- Repro: owner accepts a join request, then posts `accept=0` for the same request.
- Actual: the request flips to `declined` while the user stays a member (and the user is notified again). Cause: `Actions/RespondToJoinRequest.php` has no pending check.

### F-R2A-10 (gering): admin tournament form lacks numeric bounds
- Actual: `max_entries` -5 and 100000, `team_size` 999999 are saved; `max_entries` 3000000000 gives `SQLSTATE[22003] value out of range for type integer` (500). `OpenTournamentEnrollment` later catches `max_entries < 2` only at opening.
- Cause: `Filament/Resources/Tournaments/Schemas/TournamentForm.php` (missing `minValue`/`maxValue`).

### F-R2A-11 (mittel): destructive and failing admin buttons
- Actual: "delete" is offered in every status. Deleting a live or finished tournament removes all matches and results after one confirmation. The per-entry "withdraw" row action is shown in live/finished although it always fails there.
- Expected: block or strongly warn for live/finished tournaments; hide withdraw outside enrollment/check-in.

### F-R2A-12 (gering for production, relevant for QA safety): empty Discord or Mumble config still sends real requests
- Actual: with `DISCORD_*` and `MUMBLE_*` blank, a Match-Ready event queued `CreateMatchChannelJob` / voice jobs which called `https://discord.com/api/...` without token (answers 401/405) and `http://mumble-admin:8000` (host not found); hundreds of failed jobs. `failed_jobs` filled quickly.
- Cause: `AppServiceProvider.php:173` binds `HttpDiscordClient` unconditionally with an empty token (the Voice client likewise); listeners are registered whenever the module is on, not when the integration is configured.
- Expected: a null client or a configured-check (as for other optional integrations), so a half-configured dev or CI run never calls real hosts.

### F-R2A-13 (gering): misleading copy
- Report on an already reported match says "Dieses Match ist noch nicht bereit, um gemeldet zu werden." instead of "already reported". Also "Dieser Nutzer ist bereits Mitglied des Teams." is shown to the user himself when joining his own team.

### F-R2A-14 (gering): draft existence leak
- `POST /tournaments/{draft}/enroll` answers 403 for a participant, while the page answers 404. Minor information leak; align with 404.

## Questions for the product owner (recommended default first)

- Q-R2A-1: Should the orga be able to decide a match in `ready`/`reported`/`completed` state (walkover, correction)? Recommended: yes, an admin action "Ergebnis setzen / Aufgabe" on the match list of the tournament, usable in any live state, with F-R2A-2 fixed first (recompute champion, refuse when downstream matches were already played unless confirmed).
- Q-R2A-2: Three-way (or n-way) round-robin tie: keep the arbitrary winner by lowest entry id, or announce the tie and let the orga pick? Recommended: keep the ranking, show "Gleichstand" and let the orga confirm the winner.
- Q-R2A-3: One person in several teams of the same tournament: forbid (recommended, same as solo) or allow?
- Q-R2A-4: Member leaves an entered team: block leaving while the team is enrolled in a not-finished tournament (recommended), or invalidate the entry?
- Q-R2A-5: Who may delete a tournament in live/finished state? Recommended: only draft/announced/enrollment, otherwise archive.

## Cleanup performed

Server, Reverb and queue worker stopped by remembered PID, throwaway database `lanomat_qa_r2a` dropped, own browser tabs closed, uploaded test logo removed from the shared storage directory.
