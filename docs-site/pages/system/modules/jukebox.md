---
title: Jukebox
description: The communal LAN radio — search, queue, up-vote, skip threshold and Music Assistant.
sidebar_icon: music
---

`Jukebox` is the venue radio. Checked-in participants search tracks, enqueue them and up-vote the
queue; vote order drives what plays next. LANoMAT is the voting and remote-control layer in front
of Music Assistant — it never handles audio bytes.

## Who uses it

- **Teilnehmer** search, queue one track at a time, vote and vote to skip.
- **Helpers / orga** moderate: skip or remove regardless of votes.
- **Everyone at the venue** hears the result.

## Key screens

- `/events/{slug}/jukebox` — now playing, queue ordered by votes, search.
- Beamer `now-playing` scene — title, artist, cover; no vote counts, no adder.
- Filament — moderation.

| Dark | Light |
|---|---|
| ![Jukebox, dark](/screens/jukebox-dark.png) | ![Jukebox, light](/screens/jukebox-light.png) |
| `JUKEBOX · DARK` — vote-ordered LAN radio | `JUKEBOX · LIGHT` |
| ![Admin Raumsteuerung in the dark theme, receiver unreachable banner, volume controls and a ceiling of 60](/screens/admin-room-control-dark.png) | ![Admin Raumsteuerung in the light theme, the same receiver state and volume ceiling](/screens/admin-room-control-light.png) |
| `RAUMSTEUERUNG · DARK` — the receiver the queue plays through; ceiling 60 | `RAUMSTEUERUNG · LIGHT` — same state |

## Guards and rules

- Queue and vote require a non-cancelled, **checked-in** registration — stricter than Gallery.
- At most one unplayed item per user per event (anti-flood).
- Queue order is `JukeboxQueue`: up-votes desc, then `created_at`/`id` asc. The same read-model is
  used by the page and the sync job.
- Skip threshold is `max(3, ceil(checkedIn × skip_ratio))`; a floor of 3 stops one person skipping.
- Orga/helper skip always wins.
- If Music Assistant is down, the queue still accepts changes; sync and polling no-op with a calm
  message, never a 500.
- `syncQueue()` mirrors the order into MA; the "sync implies play" assumption is verified at
  real-MA time.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>No audio in the app.</strong> The browser never streams the radio. Music Assistant owns
  playback; LANoMAT owns the order.
</div>
