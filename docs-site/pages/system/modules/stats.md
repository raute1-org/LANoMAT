---
title: Stats
description: Cross-event leaderboards and derived badges, including MVP of the night.
sidebar_icon: chart-bar
---

`Stats` computes leaderboards and badges across events. Nothing is stored: every number is derived
on read from tournament entries, match results and the MVP poll, so a corrected result immediately
changes the table.

## Who uses it

- **Teilnehmer** see their placement and badges on the profile and the stats page.
- **Orga** uses the leaderboard for the closing ceremony.
- **Beamer** shows winner moments driven by tournament completion.

## Key screens

- `/stats` — cross-event leaderboard, filterable by game.
- Profile — badges and placements.
- Beamer winner scene — fed by the tournament, not by Stats.

## Guards and rules

- `BadgeCalculator` aggregates one competitor across all events; `EventBadgeCalculator` is a
  separate, event-keyed lookup. They are deliberately not one method.
- The `mvp_of_the_night` badge comes from the closed MVP poll's winning `subject_user_id`; no poll
  or no votes yields `[]`.
- Badges are never persisted. A changed result changes the badge.
- Round-robin ties are resolved by the tournament's standings rule, not by Stats.
- Requires Voting (for the MVP badge).

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>One competitor, many LANs.</strong> Because <code>User</code>, <code>Team</code> and
  <code>Game</code> are cross-event, a leaderboard is a query, not an import.
</div>
