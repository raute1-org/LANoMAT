---
title: Recap
description: "The post-LAN projection: podiums, photos, songs, MVP, computed from other modules."
sidebar_icon: history
---

`Recap` is a pure read-model for the time after the LAN. It owns no tables; it assembles an event's
summary from the other modules' read-models and serves a public page plus a beamer scene.

## Who uses it

- **Guests and Teilnehmer** visit the public recap after the event.
- **Orga** uses it as the closing slide and as a check that the event's data is complete.

## Key screens

- `/events/{slug}/recap` — podiums, top photos, MVP, songs played, counts.
- Beamer `Recap` scene — the same projection, no PII.

| Dark | Light |
|---|---|
| ![Recap, dark](/screens/recap-dark.png) | ![Recap, light](/screens/recap-light.png) |
| `RECAP · DARK` — podiums, photos, songs | `RECAP · LIGHT` |

## Guards and rules

- The page is public only when the event is publicly visible **and** `finished`/`archived`. A recap
  during the LAN would be premature.
- The projection reaches facts only through `GalleryQuery`, `MvpPollQuery`, `JukeboxStats` and
  tournament models — never raw cross-module queries.
- Photos link through the **public** gallery thumb route, never the auth-gated one.
- Display names and public photo URLs only: no e-mail, Discord ids, user ids or network data.
- Nothing is stored; every load recomputes.
- Requires Gallery, Jukebox, Voting and Presence (hard dependency).

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>The closing argument.</strong> A recap is not a second dashboard. It is the story of one
  LAN: who won, which photos survived moderation, which songs played.
</div>
