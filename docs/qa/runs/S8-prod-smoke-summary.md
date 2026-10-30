# QA run: S8 production smoke, summary

Round 1, read-only (GET/HEAD requests, read-only commands, viewing pages as a logged-in admin; no writes, no seeding). Target: the production deployment at the time, running the then-current `main`. This is a deliberately generic summary: infrastructure specifics (addresses, ports, schedules, paths, certificate and server configuration details) are not recorded in the public repository.

## Counts

PASS 27, PARTIAL 1, FAIL 1 test case (plus one finding found while browsing the panel), BLOCKED 2, N-A 2.

## Results

| ID | Area | Result |
|----|------|--------|
| S8-01..03 | TLS, HTTP to HTTPS redirect, security headers | PASS (hardening headers present; CSP and referrer policy were missing then, since added, see F-S1-1) |
| S8-04..06 | Guest landing, guest redirects, 404s and module-off paths | PASS (no event data for guests, no stack traces, no dev login) |
| S8-07 | Health endpoint | PASS |
| S8-08 | Login page and Discord redirect | PASS (Discord button only, no dev-login buttons, correct callback) |
| S8-09 | Public data exposure | PASS (partial: only the teams list could be probed) |
| S8-10 | Admin browser pass over core pages | PASS (all pages 200, no console errors, German copy); one favicon problem found, fixed (F-S8-1) |
| S8-11 | Preflight widget | PASS |
| S8-12 | Check-in QR base URL | BLOCKED (no token existed yet; reading it would have required a write) |
| S8-13, S8-14 | Arrival overview, event page as admin | PASS (one copy nit, fixed) |
| S8-15, S8-16 | Module profile, preflight CLI | PASS |
| S8-17 | Containers | PASS (all up, application healthy) |
| S8-18, S8-19 | Logs, failed jobs | PASS |
| S8-20 | Scheduler | PASS |
| S8-21 | Backups | FAIL: no backup had run yet and the mechanism lacked a freshness check (F-S8-2, fixed) |
| S8-22 | Backup validity | BLOCKED (nothing to validate yet; part of the smoke list now, SM-12) |
| S8-23, S8-24 | SSH and network exposure hardening | PASS (hardening checks passed) |
| S8-25 | Reverb / WSS | PARTIAL (service healthy, preflight OK; the live connection needs a page with live channels, see F-S8-3) |
| S8-26..31 | Environment sanity, deploy state, resources, DNS, data sanity | PASS (production settings correct, running revision equal to `origin/main`, no QA or demo data) |
| S8-32 | Interactive write tests | N-A (not part of the read-only run; later covered by the approved throwaway-event procedure, S8-W) |
| Cookies | Session and CSRF cookie flags | PASS |
| Mobile width | Emulation in the owner's browser | N-A |

## Follow-ups

The findings of this round are fixed or accepted (see [`../findings.md`](../findings.md)). The recurring part of this suite is the smoke-minimal list in the test plan.
