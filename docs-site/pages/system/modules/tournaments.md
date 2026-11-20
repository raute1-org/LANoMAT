---
title: Tournaments
description: Bracket engine, lifecycle, check-in, report/confirm/dispute, live bracket UI and orga override.
sidebar_icon: trophy
---

`Tournaments` is the largest module: a pure bracket engine, an enrollment/check-in lifecycle, a
match-result flow with optimistic locking, a live bracket UI over Reverb, and Discord/voice/server
fan-out on `MatchReady` and `MatchCompleted`.

## Who uses it

- **Teilnehmer** enroll (solo or with a team), check in, play, report a result, confirm or dispute.
- **Orga** announces, opens enrollment, starts, decides disputed matches, corrects results and
  manages the bracket.

## Key screens

- `/events/{slug}/tournaments` — list with status, format, slots and entry CTA.
- `/tournaments/{id}` — bracket, match cards, report/confirm actions, join links.
- Filament — lifecycle header actions, entries, matches, **Dispute** page.

| Dark | Light |
|---|---|
| ![Tournaments, dark](/screens/tournaments-dark.png) | ![Tournaments, light](/screens/tournaments-light.png) |
| `TOURNAMENTS · DARK` — bracket and match actions | `TOURNAMENTS · LIGHT` |

## Guards and rules

- Formats: single elimination 2–64, double elimination 2/4/6/8/16, round robin. Byes are engine
  concerns.
- Status: `draft → announced → enrollment → check_in → live → finished`. A `draft` tournament is
  invisible to everyone but orga (`Tournament::scopeVisibleTo`).
- Enrollment and check-in require a non-cancelled event registration (orga exempt). Team
  tournaments require an exact roster size.
- The roster is frozen at start (`roster_snapshot`), so later transfers do not rewrite history.
- Start is row-locked; double-start and double-enrollment cannot win.
- A report is `pending` until the opponent confirms or disputes. The reporter can change or withdraw
  while pending. Orga can decide any match.
- Match writes use `lock_version`; a stale confirm fails instead of overwriting.
- `BracketPersister` writes the initial tree, `MatchProgression` is the only domain↔DB bridge for
  played results.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Live bracket.</strong> <code>TournamentStarted</code>, <code>MatchReady</code>,
  <code>MatchCompleted</code> and <code>TournamentCompleted</code> broadcast to the public
  <code>tournament.{id}</code> channel. The UI is correct after a reload; realtime is the bonus.
</div>
