---
title: Infoscreen
description: The beamer — rotating scenes, announcements, tombola, winner moments and OBS overlays.
sidebar_icon: monitor
---

`Infoscreen` is the loud surface. `/screen/{event}` rotates scenes on the venue projector: now/next,
bracket, presence, gallery, jukebox, announcements, tombola and the winner moment. It is the one
place where amber, scale and motion are allowed to be loud.

<div class="live">LIVE 17:42 · SZENE 3/8 · NÄCHSTES MATCH · UT2004</div>

## Who uses it

- **Orga** controls scenes, announcements, the tombola and overrides from the admin panel.
- **The venue** reads it from several metres away.
- **OBS** uses the same scenes as transparent overlays.

## Key screens

- `/screen/{event}` — the beamer.
- `/overlay/tournament/{tournament}/bracket` — OBS browser source.
- `/overlay/match/{match}/scoreboard` — per-match scoreboard overlay.
- Filament **Infoscreen** — scenes, order, duration, enable/disable, announcement, tombola.

| Dark | Light |
|---|---|
| ![Infoscreen, dark](/screens/infoscreen-dark.png) | ![Infoscreen, light](/screens/infoscreen-light.png) |
| `INFOSCREEN · DARK` — the loud beamer | `INFOSCREEN · LIGHT` |

## Guards and rules

- Nobody scrolls a beamer: lists have fixed caps ("+N weitere"), announcement text is capped at 280
  characters, scenes run 5–300 seconds.
- One focal thing per scene; legibility floor at 1920×1080.
- Bracket scenes use `BracketView` in fit mode. A bracket that cannot fit at the floor hides early
  rounds ("Frühe Runden ausgeblendet") and rotates windows every 10 seconds.
- A synthetic scene override (winner, tombola) is time-boxed and authorized.
- Public scenes carry no e-mail, Discord ids, user ids or network data.
- The beamer is guest-safe and needs no session.
- `ScenesUpdated` broadcasts on the public `event.{id}` channel with an empty payload.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Calm app, loud beamer.</strong> If a scene would also work on a phone, it is probably too
  quiet for the beamer. If it would work in the app's sidebar, it is definitely too loud.
</div>
