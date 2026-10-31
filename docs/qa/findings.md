# QA findings (consolidated)

Outcome of the pre-LAN QA programme (four rounds, suites S0-S9). Only items that are still relevant are kept: every finding has its final status. Details, repro steps and per-case evidence are in the run reports under [`runs/`](runs); product decisions behind the fixes are in [`rulings.md`](rulings.md).

**Severity:** Critical (data loss, security, production down), Important (feature broken for many, 500 on a common path), Minor (wrong behaviour with workaround, copy, a11y nit), Info.
**Status:** `fixed` (with the pull request that merged the fix and the main commits as evidence), `accepted` (deliberate decision, no change), `open`.
**ID scheme:** `F-<suite>-<n>`; a new round continues the numbering of its suite.

## Summary

No finding is open: every item below is either fixed or a deliberate, recorded decision. Two rows (`OQ-driven`, `copy nits`) bundle several small items; `F-S8-4` and `F-S8-5` got their IDs during this consolidation (the original report listed them as informational notes).

Merged fix pull requests: #43 (QA round 1: robustness, ops, tournament and registration rules, timezone, realtime), #45 (backup verification accepts tiny valid archives), #50 (QA round 4: queued notifications, "Mehr" navigation, overlays, accessibility, copy), #51 (Pelican client rebuilt against the real API). Rulings referenced as `R-<letter>` are in `rulings.md`.

## Accepted

| ID | Sev | Summary | Decision |
|----|-----|---------|----------|
| F-S0-1 | Info | `npm run types:check` failed once when run concurrently with pest (generated route file race); passes alone | accepted: do not run the frontend gates concurrently with the test suite |
| F-S1-2 | Info | Guest `/admin*` redirects to `/login`, not to a separate admin login | accepted: login is Discord-only, the plan text was updated |
| F-S9-9 | Minor | The compose `reverb` dev service (plain PHP CLI image) cannot start (no `pcntl`) | accepted: run `php artisan reverb:start` on the host for local realtime tests (documented in `compose.yml` and the test plan); the production image has the extension |
| F-S8-3 | Info | The websocket host answers a plain GET with 404 and no WSS connection is visible on pages without live channels | accepted: verify WSS on a page with live channels (smoke SM-19) |
| F-S8-4 | Info | Backups are not copied off the host (no second location yet) | accepted by the owner for now, see OQ-05 |

## Fixed

### Tournaments, results, realtime

| ID | Sev | Summary | Fix |
|----|-----|---------|-----|
| F-01 | Important | No way to move a tournament from draft to enrollment (status not in the form) | #43 `8b39b55`, `88ccdfb` (R-E, R-R: lifecycle actions, `announced` status) |
| F-S4-1 | Critical | Result form credited "own" score to entry 1 even for an entry-2 reporter | #43 `dd7a089` (R-G) |
| F-S4-2 | Important | Opponent never saw the reported score before confirming | #43 `dd7a089` (R-G) |
| F-S4-3 | Important | Reporter saw Bestätigen/Anfechten on their own report (403 page) | #43 `dd7a089` (R-G) |
| F-S4-4 | Important | A single-match elimination tournament never finished | #43 `7f364b9` (R-H) |
| F-S4-5 | Important | Round robin never finished and had no standings | #43 `7f364b9` (R-H) |
| F-S4-6 | Important | Starting with fewer than 2 participating entries returned 500 | #43 `9724d5b` (R-I) |
| F-S4-7 | Important | "Zur Check-in-Phase" opened a phase participants could not use | #43 `9724d5b` (R-I) |
| F-S4-8 | Minor | No feedback on a 422 in the report form | #43 `dd7a089` (R-G) |
| F-S4-9 | Minor | Bracket match numbers off by one | #43 `dd7a089` (R-G) |
| F-S4-11 | Minor | Index without enrollment counts or "full" cue, odd sort | #43 `9724d5b` (R-J) |
| F-S4-12 | Minor | Double-elimination grand-final reset and byes shown as pending | #43 `7f364b9` (R-H) |
| F-S4-13 | Minor | Raw 404 on stale confirm, missing toasts, formal "Sie" panel copy | #43 `dd7a089`, `9724d5b` (R-G, R-L) |
| F-S6-1 | Important | No resync after a Reverb reconnect (stale bracket) | #43 `3ac51f6` (R-K) |
| F-S6-2 | Minor | Report submission was not broadcast | #43 `3ac51f6` (R-K) |
| F-S6-3 | Minor | No connection indicator (LIVE badge shows tournament state only) | #43 `3ac51f6` (R-K) |
| F-S9-2 | Important | Double-click or stale confirm of a match result returned 500 | #43 `80d0816` |
| F-S9-3 | Minor | Score above the integer range returned 500 | #43 `80d0816` |

### Participant journey and registration

| ID | Sev | Summary | Fix |
|----|-----|---------|-----|
| F-S2-1 | Minor | English confirm-password failure message | #43 `b0c43c0` |
| F-S2-2 | Important | Bell emptied after "mark as read" / any redirect | #43 `5572139` (R-M) |
| F-S2-3 | Minor | No bell notification for team join requests | #43 `43d0f3a` (R-O) |
| F-S2-4 | Minor | Duplicate team names | #43 `43d0f3a` (R-O) |
| F-S2-5 | Minor | `/events` hid registrable events | #43 `8c14763` (R-O) |
| F-S2-6 | Minor | 403 page offered the admin link to participants | #43 `53c79df` |
| F-S2-7 | Minor | Unconfigured provider redirects, linked rows not unlinkable | #43 `36cd02b` |
| F-S2-8 | Minor | Plain team members saw no entry state | #43 `eaf7c07` |
| OQ-driven | Important | Tournament enrollment without an event registration; no way to withdraw; self-cancel after check-in | #43 `3989216`, `53a81cf` (R-A, R-B, R-C) |

### Admin and orga

| ID | Sev | Summary | Fix |
|----|-----|---------|-----|
| F-S3-1 | Important | Event form accepted end before start and capacity 0 | #43 `5605461` (R-N) |
| F-S3-2 | Minor | Event header actions stale after a transition | #43 `5605461` (R-N) |
| F-S3-3 | Minor | English/raw empty states on relation managers | #43 `ee5c73c` (R-P) |
| F-S3-4 | Important | Duplicate seat label returned 500 | #43 `5605461` (R-N) |
| F-S3-5 | Minor | No in-app "Anmeldung offen" notification under core | #43 `e93d3dc` (R-Q) |
| F-S3-6 | Minor | Deleting a game used by tournaments succeeded silently | #43 `0129436` (R-Q) |
| F-S3-7 | Minor | Check-in QR URL built from the request host | #43 `c272d8c` (R-Q) |
| F-S3-8 | Minor | US-style dates in Filament | #43 `c8cb9b3` (R-N) |
| F-S3-9 | Minor | Registrations manager: raw ticket key, no plan/status filter | #43 `ee5c73c` (R-P) |
| F-S3-10 | Minor | Game-server fields visible under core | #43 `678a7fd` (R-P) |
| F-S3-11 | Minor | Formal "Sie" copy in the panel | #43 `9724d5b` (R-L) |

### Robustness, security, ops

| ID | Sev | Summary | Fix |
|----|-----|---------|-----|
| F-S9-1 | Important | Non-numeric route ids returned 500, also for guests | #43 `5e2af28` |
| F-S9-4 | Minor | English validation messages | #43 `d9ad9dd` |
| F-S9-5 | Minor | `stream_url` accepted non-https schemes | #43 `51a0ba7` |
| F-S9-6 | Minor | Login and self check-in returned 500 when Redis was down | #43 `8ee90b2` (limiters on the database) |
| F-S9-7 | Minor | Default English unstyled 404 page | #43 `690b0dd` (German error pages) |
| F-S9-8 | Minor | UTC display/arrival days off around local midnight | #43 `303d35e` (R-D) |
| F-S1-1 | Minor | Version-leaking header, missing referrer/permissions policy and CSP | #43 `2f2383c`, `710bcc7` (CSP in report-only mode) |
| F-S8-1 | Minor | Admin favicon URL used http and failed | #43 `0339247` |
| F-S8-2 | Important | No backup mechanism verified: cron without freshness check, risk of silent empty dumps | #43 `67f2980` (script, cron installer, `BackupFreshnessCheck`), #45 `421d254` |
| F-S8-5 | Minor | Double period in the arrival hint | #43 `521937b` |

### Modules and mobile (full profile)

| ID | Sev | Summary | Fix |
|----|-----|---------|-----|
| F-S5-1 | Important | Staff notifications mirrored to Discord ran in the request: "Orga rufen", "Check-in öffnen", "Essen fertig" returned 500 with Discord down | #50 `7600e44` (R-T) |
| F-S5-2 | Important | Gallery zip on an event without approved photos returned 500 | #50 `a1559c4` (R-V) |
| F-S5-3 | Important | No navigation to five secondary modules | #50 `43fe107` (R-U) |
| F-S5-4 | Important | OBS overlays not transparent | #50 `70931cd` (R-V) |
| F-S5-5 | Minor | Remote-host form accepted any string | #50 `1e21957` (R-V) |
| F-S5-6 | Minor | Missing voice installer file returned 500 | #50 `83f9cc3` (R-V) |
| F-S5-7 | Minor | Raw field names in screen-control validation | #50 `d739706` |
| F-S5-8 | Minor | Zero-length ICS events | #50 `c41a41a` (R-V) |
| F-S5-9 | Minor | No availability hint when Music Assistant is down | #50 `81276ba` (R-V) |
| F-S7-1 | Minor | Touch targets below 40 px at 390 px | #50 `c3a00ec` (R-V) |
| F-S7-2 | Minor | English leftovers in accessibility chrome and file inputs | #50 `c3a00ec` |
| F-S7-3 | Minor | Two inputs without accessible name, no nav landmark | #50 `c3a00ec` |
| (copy nits) | Minor | Zeitplan/Programm, Umfragen/Abstimmungen, plural and decimal formats | #50 `b235894` (R-U, R-V) |

## Not yet verified (carried into the next run)

- Real browser delivery of CSP reports (the endpoint works; see OQ-S7-1).
- Delivery of queued notifications after a worker restart (S9-28 was verified only up to the written rows).
- Presets / Pelican provisioning against a real panel: tested with fakes and the contract check only.
