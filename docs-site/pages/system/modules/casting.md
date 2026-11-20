---
title: Casting
description: Stream links and no-auth OBS overlays for the venue stream.
sidebar_icon: radio
---

`Casting` is a deliberately thin module: it exposes the stream links and the OBS overlay routes.
The overlay is a browser source with a transparent background, no authentication, and only public
data.

## Who uses it

- **Orga / streamer** configures the stream URL and adds overlays to OBS.
- **Everyone at the venue** watches the stream through the link on the event page.

## Key screens

- `/events/{slug}` — stream link when configured.
- `/overlay/tournament/{tournament}/bracket` — OBS browser source (transparent, no auth).
- `/overlay/match/{match}/scoreboard` — per-match scoreboard overlay.
- Filament event form — stream URL field.

## Guards and rules

- Overlays are public by URL and carry no session. That is why they only ever receive public data:
  display names, scores, bracket state and approved photos.
- The overlay does not accept query parameters that select arbitrary data; the scene is validated.
- A missing scene renders an empty transparent frame, not an error page.
- The stream URL is display-only; LANoMAT does not embed a player with third-party tracking.
- No overlay writes anything.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>The fourth surface.</strong> App, admin, beamer and overlay. The overlay is the only one
  with a transparent background; the beamer is the only one allowed to be loud.
</div>
