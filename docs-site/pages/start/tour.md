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

Once you are signed in, the dashboard loads the event it found: the live LAN, the ticket and its
payment state, your seat, and the teams you are on. Nothing else competes for the space.

| Dark | Light |
|---|---|
| ![Signed-in dashboard with live LAN, ticket and seat A3-1, dark](/screens/dashboard-dark.png) | ![Signed-in dashboard with live LAN, ticket and seat A3-1, light](/screens/dashboard-light.png) |
| `DASHBOARD · DARK` — live LAN, ticket, seat A3-1 | `DASHBOARD · LIGHT` — the same four cards |

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

The event page also carries the servers for the evening: address, port, estimated demand, the launcher
one-click for a published recipe, and a join that copies the address for the game client. The
cross-event server browser lives at `/servers`.

| Dark | Light |
|---|---|
| ![Event server block with UT2004 DM-Antalus, address and join, dark](/screens/event-servers-dark.png) | ![Event server block with UT2004 DM-Antalus, address and join, light](/screens/event-servers-light.png) |
| `EVENT SERVERS · DARK` — address, port, estimated demand | `EVENT SERVERS · LIGHT` |

### Seating, schedule, tournaments

The seat map reads like a control diagram. The bracket is a tree, not a poster: SVG connectors,
mono scores, and live matches marked with the signal.

| Dark | Light |
|---|---|
| ![Seating, dark](/screens/seating-dark.png) | ![Seating, light](/screens/seating-light.png) |
| `SEATING · DARK` — claimed, free, blocked | `SEATING · LIGHT` |

The same grid exists for the orga as a plain table: one row per seat with its column, row and the
person holding it, plus the bulk creator that lays out a whole hall.

| Dark | Light |
|---|---|
| ![Admin seat list with grid bulk-create and per-seat assignment, dark](/screens/admin-seats-dark.png) | ![Admin seat list with grid bulk-create and per-seat assignment, light](/screens/admin-seats-light.png) |
| `ADMIN SEATING · DARK` — orga table, seat by seat | `ADMIN SEATING · LIGHT` |

| Dark | Light |
|---|---|
| ![Schedule, dark](/screens/schedule-dark.png) | ![Schedule, light](/screens/schedule-light.png) |
| `SCHEDULE · DARK` — now/next, ICS export | `SCHEDULE · LIGHT` |

Entries carry a type — a tournament round, a meal, a stage slot — so one timeline renders every kind
of item without a second list.

| Dark | Light |
|---|---|
| ![Admin schedule with tournament rounds and a food slot, dark](/screens/admin-schedule-dark.png) | ![Admin schedule with tournament rounds and a food slot, light](/screens/admin-schedule-light.png) |
| `ADMIN SCHEDULE · DARK` — typed entries, every kind | `ADMIN SCHEDULE · LIGHT` |

| Dark | Light |
|---|---|
| ![Tournaments, dark](/screens/tournaments-dark.png) | ![Tournaments, light](/screens/tournaments-light.png) |
| `TOURNAMENTS · DARK` — bracket and match actions | `TOURNAMENTS · LIGHT` |

The bracket itself is the tree: rounds left to right, mono scores, played matches closed and the
running one marked, the next round filling in as results are confirmed.

| Dark | Light |
|---|---|
| ![Live Counter-Strike 2 bracket with rounds, scores and a running match, dark](/screens/tournament-bracket-dark.png) | ![Live Counter-Strike 2 bracket with rounds, scores and a running match, light](/screens/tournament-bracket-light.png) |
| `BRACKET · DARK` — rounds, scores, live match | `BRACKET · LIGHT` |

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

Uploads are resumable, and every file carries its size, its uploader and a download that stays
signed. The quota bar is the only meter on the page.

| Dark | Light |
|---|---|
| ![File upload with the quota bar and the user's own uploads, dark](/screens/files-upload-dark.png) | ![File upload with the quota bar and the user's own uploads, light](/screens/files-upload-light.png) |
| `FILES UPLOAD · DARK` — quota bar, own uploads | `FILES UPLOAD · LIGHT` |

| Dark | Light |
|---|---|
| ![Gallery, dark](/screens/gallery-dark.png) | ![Gallery, light](/screens/gallery-light.png) |
| `GALLERY · DARK` — approved photos, highlights | `GALLERY · LIGHT` |

Every photo passes the same queue before it reaches the wall: preview, event, uploader, caption, and
the status that decides whether it is visible and whether it becomes a highlight.

| Dark | Light |
|---|---|
| ![Admin photo queue with approvals, status and highlight toggles, dark](/screens/admin-event-photos-dark.png) | ![Admin photo queue with approvals, status and highlight toggles, light](/screens/admin-event-photos-light.png) |
| `GALLERY MODERATION · DARK` — approval queue, highlights | `GALLERY MODERATION · LIGHT` |

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

## These docs

The docs site you are reading is a Zudoku build under `/docs`, generated from `docs-site/pages` and
served from the same host as the app. It uses the Signalpult palette, so a screenshot in the docs and
the page it describes share one look.

| Dark | Light |
|---|---|
| ![Docs, games library, dark](/screens/docs-games-library-dark.png) | ![Docs, games library, light](/screens/docs-games-library-light.png) |
| `DOCS · DARK` — the games library page with its screenshot pair | `DOCS · LIGHT` |

The page it documents is the app's games library: every published recipe the lineup allows, the LAN
dot, the **Spiel teilen** entry, and the "Nur LAN-Spiele" filter that narrows the list to what runs
from the LAN disc.

| Dark | Light |
|---|---|
| ![Games library with the LAN-only filter active, dark](/screens/games-filter-dark.png) | ![Games library with the LAN-only filter active, light](/screens/games-filter-light.png) |
| `GAMES · DARK` — LAN-only filter, two recipes left | `GAMES · LIGHT` |

A recipe opens onto its game page: the server with its join link, the install steps the agent runs,
and the files and avatars that come from the LAN. The server browser at `/servers` answers the same
question from the other side: what the LAN runs, and how to get onto it.

| Dark | Light |
|---|---|
| ![Game page for Unreal Tournament 2004 with server, install steps and files, dark](/screens/game-ut2004-dark.png) | ![Game page for Unreal Tournament 2004 with server, install steps and files, light](/screens/game-ut2004-light.png) |
| `GAME DETAIL · DARK` — server, agent command, install steps | `GAME DETAIL · LIGHT` |

| Dark | Light |
|---|---|
| ![Docs, MCP, dark](/screens/docs-mcp-dark.png) | ![Docs, MCP, light](/screens/docs-mcp-light.png) |
| `DOCS · DARK` — the MCP page, pairing section | `DOCS · LIGHT` |

What that page describes is the pairing itself: the code the agent printed, its name, and the clients
already connected to the account, each with its own detach.

| Dark | Light |
|---|---|
| ![MCP pairing with a pending agent code and the connected clients, dark](/screens/mcp-connect-pending-dark.png) | ![MCP pairing with a pending agent code and the connected clients, light](/screens/mcp-connect-pending-light.png) |
| `MCP · DARK` — code `DEMO-2345`, approve or deny | `MCP · LIGHT` |

## Reading a screenshot

Two details repeat everywhere and are worth learning once:

- **The signal dot.** `● LIVE 17:42` is a mono label with an amber dot. It pulses gently, and stops
  pulsing under `prefers-reduced-motion`. Green/amber/red variants mean OK/warn/down.
- **Mono is data.** Seat labels, ports, IPs, scores, round numbers, timers and QR payloads are
  JetBrains Mono with tabular numbers. Prose is Space Grotesk. There is no third face.
