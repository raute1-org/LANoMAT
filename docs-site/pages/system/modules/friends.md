---
title: Friends
description: Friend requests, blocking, and LAN-native suggestions.
sidebar_icon: user-plus
---

`Friends` is a small cross-user social layer: request → accept, block, and suggestions based on the
LAN context two people already share. It is deliberately not a social network — no feed, no public
friend counts.

## Who uses it

- **Teilnehmer** send and answer requests, block someone, and see suggestions from shared events,
  teams and tournaments.
- **Orga** rarely touches it; there is no moderation queue.

## Key screens

- `/friends` — friends, pending requests, blocked list, suggestions.
- Presence page — an optional "friends only" filter, computed per viewer.
- Profile — the friend action on another participant's profile.

| Dark | Light |
|---|---|
| ![Friends page with requests, friends and suggestions, dark](/screens/friends-dark.png) | ![Friends page with requests, friends and suggestions, light](/screens/friends-light.png) |
| `FRIENDS · DARK` — requests, friends, suggestions | `FRIENDS · LIGHT` — same lists |

| Dark | Light |
|---|---|
| ![Presence page with the friends-only filter, dark](/screens/presence-dark.png) | ![Presence page with the friends-only filter, light](/screens/presence-light.png) |
| `PRESENCE · DARK` — "Nur Freunde" filter | `PRESENCE · LIGHT` — same filter row |

## Guards and rules

- `friendships` stores one directed row per ordered pair; `accepted` means symmetric friends.
- Two simultaneous requests collapse into one accepted friendship instead of a dangling pair.
- A block wins over a friendship: creating a block deletes any existing row in either direction and
  prevents new requests.
- Every transition is authorized inside the action with `FriendshipPolicy`, not only in the
  controller. The actor is always `auth()->user()`.
- Notifications are bell-only; friend requests are not mirrored to Discord.
- `isFriend` is a per-viewer decoration added only by the presence page controller. It never enters
  a broadcast payload or a beamer scene.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>LAN-native suggestions.</strong> Candidates are ranked by distinct shared events, teams
  and tournaments. Steam friends are an optional fourth source; a private profile simply yields no
  suggestions, never an error.
</div>
