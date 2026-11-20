---
title: Voting
description: Game votes, generic polls, one vote per user, results and MVP of the night.
sidebar_icon: vote
---

`Voting` covers both "which game do we play next?" and the event's **MVP of the night**. A poll has
options, an open/close window and one vote per user; results are visible according to the poll's
state.

## Who uses it

- **Teilnehmer** vote in open polls and see results once the poll allows it.
- **Orga** creates polls, seeds the MVP poll, opens/closes and reveals the winner on the beamer.

## Key screens

- `/events/{slug}/polls` — open and closed polls, one vote per user.
- Filament **Polls** — question, options, kind, window, option management.
- Beamer — MVP reveal reuses the winner scene.

| Dark | Light |
|---|---|
| ![Voting, dark](/screens/voting-dark.png) | ![Voting, light](/screens/voting-light.png) |
| `VOTING · DARK` — open poll | `VOTING · LIGHT` |

## Guards and rules

- One vote per user per poll (`UNIQUE(poll_id, user_id)`); a second vote is rejected, not silently
  replaced.
- Voting requires an open poll and a logged-in user; it does not additionally require a registration.
- `PollKind::Mvp` is a special flavour: `SeedMvpPoll` creates one option per registered participant,
  linked by `subject_user_id` (not mass-assignable).
- `RevealMvp` re-checks that the poll really is the event's closed MVP poll; a stray id cannot
  trigger a reveal.
- `MvpPollQuery::winner()` returns `null` for a poll with options but zero votes — never option 0.
- Results never expose who voted for whom.

## Docs for agents

`polls_list` returns one event's polls with options and the caller's own vote; `poll_vote` casts or
changes that vote in an open poll. Results are only included where the web page would show them.

<div class="callout-live">
  <strong>Beamer reveal.</strong> The MVP reveal uses the existing synthetic winner scene with an
  overridden title ("Spieler:in des Abends"). It carries only the winning label, never the user id.
</div>
