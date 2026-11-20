---
title: LFG
description: Looking-for-group posts per event, expiry and Discord announcements.
sidebar_icon: search
---

`Lfg` is the "Mitspieler finden" board: short posts per event for people who want a group for a
game right now. Posts expire, can be deleted by their owner or orga, and are optionally announced
to Discord.

## Who uses it

- **Teilnehmer** post a game, a time window, how many players are needed and a skill level; browse
  open posts.
- **Orga** removes stale or inappropriate posts.

## Key screens

- `/events/{slug}/lfg` — open posts, filter by game, create post.
- Dashboard/event page — a link when the module is enabled and the event is live.
- Discord — an optional announcement when a post is created.

| Dark | Light |
|---|---|
| ![LFG, dark](/screens/lfg-dark.png) | ![LFG, light](/screens/lfg-light.png) |
| `LFG · DARK` — open posts | `LFG · LIGHT` |

## Guards and rules

- Posting requires a non-cancelled event registration.
- A post has an expiry; `lanomat:prune-expired-lfg-posts` closes expired posts and the scheduler runs
  it regularly.
- The owner or orga can delete a post; deletion is soft in the sense that the announcement stays in
  Discord.
- `players_needed` and `skill_level` are validated enums, not free text.
- No direct messages are sent by the module; interested people answer in Discord or in person.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Right-now tool.</strong> LFG is intentionally ephemeral. If a post is older than the
  event's rhythm, it should expire rather than linger.
</div>
