---
title: Teams
description: Cross-event teams, rosters, join requests, logos and ownership transfer.
sidebar_icon: users
---

`Teams` is cross-event like `Games`: a team exists across LANs, and tournament entries reference it.
The module owns the roster, join requests, the logo and the ownership rules.

## Who uses it

- **Teilnehmer** create a team, request to join, accept or decline requests, leave, and transfer
  ownership.
- **Orga** manages teams in Filament and resolves stuck rosters.

## Key screens

- `/teams` — my teams, create, browse.
- `/teams/{id}` — roster, requests (owner), logo, leave/transfer.
- Tournament team picker — the viewer's own teams, with a roster-size hint.

| Dark | Light |
|---|---|
| ![Teams page in its empty state with the create action, dark](/screens/teams-dark.png) | ![Teams page in its empty state with the create action, light](/screens/teams-light.png) |
| `TEAMS · DARK` — empty state, create a team | `TEAMS · LIGHT` — same empty state |
| ![Admin teams list with name, tag, captain and members, dark](/screens/admin-teams-dark.png) | ![Admin teams list with name, tag, captain and members, light](/screens/admin-teams-light.png) |
| `ADMIN · TEAMS` — name, tag, captain, members | `ADMIN · LIGHT` |

## Guards and rules

- One membership per user per team (`UNIQUE(team_id, user_id)`).
- The owner cannot leave without transferring ownership first; the action refuses instead of
  orphaning a team.
- Only the owner responds to join requests and edits the roster.
- Team logos go to Laravel Storage, are re-encoded to strip metadata, and are served from LANoMAT,
  not an external CDN.
- A team tournament requires an exact roster size (`team_size`); teams that do not match are listed
  but disabled ("2 von 3 Mitgliedern").

## Docs for agents

`teams_list` with an optional `query` returns teams, member counts and the caller's membership.

<div class="callout-live">
  <strong>Roster snapshots.</strong> When a tournament starts, the roster is frozen into the entry's
  <code>roster_snapshot</code>. Later team changes do not rewrite tournament history.
</div>
