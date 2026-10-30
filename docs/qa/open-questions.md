# QA open questions and recorded answers

Consolidated from the QA programme. A question is written self-contained, with a recommended default that testing continues with until it is answered. Obsolete questions (answered by a fix, or settled by approval of the test setup) are not repeated; the decisions behind them are in [`rulings.md`](rulings.md).

**Status:** `open` (needs a person), `answered` (answer recorded), `implemented` (answer is built).

## Open

| # | Question | Context | Recommended default | Status |
|---|----------|---------|---------------------|--------|
| OQ-01 | Can the owner verify the Discord login including the guild-membership gate on production? | Discord OAuth cannot be automated. Needs (a) a guild member, (b) an account that is NOT in the guild, (c) an admin (exempt). Covered locally only by Pest tests. | The owner does the three logins in the pre-LAN acceptance run (test plan S1-21) | open |
| OQ-02 | Can the owner link and unlink a Steam account once on production? | Account linking needs real OAuth/OpenID (S2-34 is MANUAL). The Steam Web API key will be set later; until then the Steam link is not offered. | Verify together with the key setup | open |
| OQ-04 | Does the production event have a check-in QR token yet, and is its URL base right? | Opening the QR modal creates the token (a write), so the read-only run only reads the token table. | Verify at the first real use (smoke SM-16) | open |
| OQ-S7-1 | Do browser CSP reports actually arrive in the CSP log channel on production? | The `/csp-report` endpoint works with a hand-posted report, but violations provoked in a browser were not seen in the log during the run (browser reporting delay, extension or localhost behaviour unknown). | Provoke one violation in DevTools after a deploy and check the log; if nothing arrives, add the `Report-To` fallback header. The policy stays report-only for now | open |

## Answered by the owner

| # | Question | Answer |
|---|----------|--------|
| OQ-03 | May QA write on production? | Yes, with a clearly named throwaway event and mandatory cleanup; done once with cleanup. The procedure is the optional S8-W of the test plan; approval is given per run. |
| OQ-05 | Are backups copied off the host? | No, not for now. Accepted risk (finding F-S8-4); backups stay on the host with the freshness check. |
| OQ-06 | How does a tournament move from draft to enrollment? | The action "Anmeldung öffnen" (implemented, rulings E and R; plus the `announced` status). |
| OQ-07 | Must tournament enrollment require an event registration? | Yes (ruling A). |
| OQ-10 | How are ticket types configured? | They stay a single "Standard" ticket; no form field needed. |
| OQ-N2 | Which timezone is authoritative? | Europe/Berlin for display and input over UTC storage (ruling D). |
| OQ-N6 | May a participant cancel after check-in? | No (ruling C). |
| OQ-N7 | May participants withdraw from a tournament? | Yes, while enrollment or check-in is open (ruling B). |
| Q-S5-1 | Executable-looking files in the file module? | Keep the allowlist; every upload passes the moderation gate. Add an extension denylist only if orga asks. |
| Q-S5-2 | Where do the secondary modules live in the navigation? | Sidebar group "Mehr" (ruling U). |
| Q-S5-3 | Should Discord-mirrored staff notifications be queued? | Yes (ruling T). |
| OQ-N1, N3, N4, N5 | Numeric route constraints, rate limiter without Redis, German validation/error pages, https-only stream URL | All answered "yes" and implemented (see `findings.md`). |
| OQ-N8 | Dev compose Reverb service cannot start | Document host Reverb for local realtime tests (accepted). |

## Decisions recorded alongside the programme

| Topic | Decision |
|-------|----------|
| CI | Paused until after the LAN. The public-safety checks (gitleaks, `check-public-safe.sh`) stay required; run all gates locally (`composer check`, the four frontend gates). |
| Offline login | The risk that Discord-only login is unavailable without internet at the venue is accepted. |
| Pelican panel | Reachable on the LAN / over the tunnel only, without TLS. |
| Game server port pool | 27015 to 27050 on the node address. |
| Garry's Mod | The project ships its own egg fork (`resources/pelican-eggs/`). |
| Steam Web API key | To be added later. |
| Games | Seeded on production. |
| CSP | Stays in report-only mode for now (enforcement is a later decision). |
