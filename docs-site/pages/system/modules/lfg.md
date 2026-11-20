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

- Posting requires a logged-in account and a publicly visible event; no registration or check-in gate
  (the board is deliberately low-friction).
- A post has an expiry; `lanomat:prune-lfg` closes expired posts and the scheduler runs it every
  five minutes.
- The owner or orga can delete a post; deletion is soft in the sense that the announcement stays in
  Discord.
- `slots_needed` is a validated integer, not free text; the game is a plain label so people can
  look for a round the catalogue does not know.
- No direct messages are sent by the module; interested people answer in Discord or in person.

## Docs for agents

`lfg_list` returns the active posts of one event; `lfg_create` creates a post for the caller
(`event_slug`, `title`, `game`, optional `body` and `slots_needed`). Expiry and moderation are the
same rules as the web board.

<div class="callout-live">
  <strong>Right-now tool.</strong> LFG is intentionally ephemeral. If a post is older than the
  event's rhythm, it should expire rather than linger.
</div>
