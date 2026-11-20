---
title: Screen tour
description: The LANoMAT surfaces in light and dark, from the dashboard to the beamer.
sidebar_icon: images
---

Every screenshot below comes from the app's own screenshot set (`docs/screenshots/`). Where the app
produces a light/dark pair, both are shown side by side; the layout, spacing and state are the same,
only the tokens change.

<div class="live">LIVE 17:42 · screenshots from a seeded demo event</div>

## The participant app

The participant app is calm on purpose. One primary action per screen, mono for machine data, and
the amber signal only for *now*.

| Dark | Light |
|---|---|
| ![Dashboard, dark](/screens/home-dark.png) | ![Dashboard, light](/screens/home-light.png) |
| `DASHBOARD · DARK` — next match, ticket, bell | `DASHBOARD · LIGHT` — the same calm desk |

### Event page and countdown

The public event page switches with the event lifecycle: `announced` shows the date and a hint,
`registration` shows the primary "Jetzt anmelden", `live` keeps walk-in registration open while
spots remain. The countdown is a display-only payload, recomputed on every load.

| Dark | Light |
|---|---|
| ![Event page, dark](/screens/event-page-dark.png) | ![Event page, light](/screens/event-page-light.png) |
| `EVENT · DARK` — status, spots, arrival info | `EVENT · LIGHT` — same state, paper tokens |

| Dark | Light |
|---|---|
| ![Countdown, dark](/screens/countdown-dark.png) | ![Countdown, light](/screens/countdown-light.png) |
| `COUNTDOWN · DARK` — hype mode before the LAN | `COUNTDOWN · LIGHT` |

### Seating, schedule, tournaments

The seat map reads like a control diagram. The bracket is a tree, not a poster: SVG connectors,
mono scores, and live matches marked with the signal.

| Dark | Light |
|---|---|
| ![Seating, dark](/screens/seating-dark.png) | ![Seating, light](/screens/seating-light.png) |
| `SEATING · DARK` — claimed, free, blocked | `SEATING · LIGHT` |

| Dark | Light |
|---|---|
| ![Schedule, dark](/screens/schedule-dark.png) | ![Schedule, light](/screens/schedule-light.png) |
| `SCHEDULE · DARK` — now/next, ICS export | `SCHEDULE · LIGHT` |

| Dark | Light |
|---|---|
| ![Tournaments, dark](/screens/tournaments-dark.png) | ![Tournaments, light](/screens/tournaments-light.png) |
| `TOURNAMENTS · DARK` — bracket and match actions | `TOURNAMENTS · LIGHT` |

### Food, votes, people, LFG

| Dark | Light |
|---|---|
| ![Catering, dark](/screens/catering-dark.png) | ![Catering, light](/screens/catering-light.png) |
| `CATERING · DARK` — menu, order, total | `CATERING · LIGHT` |

| Dark | Light |
|---|---|
| ![Voting, dark](/screens/voting-dark.png) | ![Voting, light](/screens/voting-light.png) |
| `VOTING · DARK` — open poll, one vote per user | `VOTING · LIGHT` |

| Dark | Light |
|---|---|
| ![Presence, dark](/screens/presence-dark.png) | ![Presence, light](/screens/presence-light.png) |
| `PRESENCE · DARK` — who is online, playing, free | `PRESENCE · LIGHT` |

| Dark | Light |
|---|---|
| ![LFG, dark](/screens/lfg-dark.png) | ![LFG, light](/screens/lfg-light.png) |
| `LFG · DARK` — looking for group posts | `LFG · LIGHT` |

## Media and the beamer

| Dark | Light |
|---|---|
| ![Files, dark](/screens/files-dark.png) | ![Files, light](/screens/files-light.png) |
| `FILES · DARK` — quota, moderation, downloads | `FILES · LIGHT` |

| Dark | Light |
|---|---|
| ![Gallery, dark](/screens/gallery-dark.png) | ![Gallery, light](/screens/gallery-light.png) |
| `GALLERY · DARK` — approved photos, highlights | `GALLERY · LIGHT` |

| Dark | Light |
|---|---|
| ![Jukebox, dark](/screens/jukebox-dark.png) | ![Jukebox, light](/screens/jukebox-light.png) |
| `JUKEBOX · DARK` — vote-ordered LAN radio | `JUKEBOX · LIGHT` |

| Dark | Light |
|---|---|
| ![Infoscreen, dark](/screens/infoscreen-dark.png) | ![Infoscreen, light](/screens/infoscreen-light.png) |
| `INFOSCREEN · DARK` — the loud beamer surface | `INFOSCREEN · LIGHT` |

| Dark | Light |
|---|---|
| ![Recap, dark](/screens/recap-dark.png) | ![Recap, light](/screens/recap-light.png) |
| `RECAP · DARK` — podiums, photos, songs | `RECAP · LIGHT` |

| Dark | Light |
|---|---|
| ![Recap scene, dark](/screens/infoscreen-recap-dark.png) | ![Recap scene, light](/screens/infoscreen-recap-light.png) |
| `RECAP SCENE · DARK` — beamer after the LAN | `RECAP SCENE · LIGHT` |

## Admin panel

The Filament panel at `/admin` is one shell with the app: same mark, same brand, plus a neutral
"Admin" marker (never amber — amber belongs to the primary action and live state).

| Dark | Light |
|---|---|
| ![Admin, dark](/screens/admin-dark.png) | ![Admin, light](/screens/admin-light.png) |
| `ADMIN · DARK` — resources, preflight tile | `ADMIN · LIGHT` |

## Native voice client

The Tauri desktop app lives in `clients/voice/`. These screens come from the participant walkthrough.

| Screen | What it shows |
|---|---|
| ![Connect](/screens/voice-app/01-connect.png) | Server address and device code — pair in the browser |
| ![Waiting](/screens/voice-app/02-connect-wait.png) | Waiting for the browser confirmation |
| ![Headset](/screens/voice-app/03-guide-headset.png) | Headset check, step 1: pick the device |
| ![Meter](/screens/voice-app/04-guide-meter.png) | Microphone meter: say a few words |
| ![Tone](/screens/voice-app/05-guide-tone.png) | Test tone on the selected output |
| ![Push to talk](/screens/voice-app/06-guide-key.png) | Push-to-talk key |
| ![Now](/screens/voice-app/10-jetzt.png) | "Jetzt": next match, current room |
| ![Rooms](/screens/voice-app/v03-rooms.png) | Voice rooms and occupancy |
| ![Overlay](/screens/voice-app/v14-overlay.png) | Speaking overlay while the game is fullscreen |

<span class="shot-caption">NATIVE CLIENT · screenshots from the app's own walkthrough</span>

## Reading a screenshot

Two details repeat everywhere and are worth learning once:

- **The signal dot.** `● LIVE 17:42` is a mono label with an amber dot. It pulses gently, and stops
  pulsing under `prefers-reduced-motion`. Green/amber/red variants mean OK/warn/down.
- **Mono is data.** Seat labels, ports, IPs, scores, round numbers, timers and QR payloads are
  JetBrains Mono with tabular numbers. Prose is Space Grotesk. There is no third face.
