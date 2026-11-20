---
title: Screenshots
description: The real LANoMAT app — participant pages, orga surfaces, the beamer, the MCP pairing flow and the desktop installer — in light and dark. Every image is captured from the seeded demo instance, not a mock-up.
sidebar_icon: image
---

Every screenshot on this page comes from the seeded demo instance (`ScreenshotSeeder` + `npm run screenshots`) at 1440×900. Light and dark are the same page, not two designs.

## Participant app

| Dark | Light |
|---|---|
| ![Home, dark](/screens/home-dark.png) | ![Home, light](/screens/home-light.png) |
| **`/`** — the guest landing and the way into Discord login. | |
| ![Event page, dark](/screens/event-page-dark.png) | ![Event page, light](/screens/event-page-light.png) |
| **`/events/{slug}`** — live event, countdown, arrival, registration. | |
| ![Seating, dark](/screens/seating-dark.png) | ![Seating, light](/screens/seating-light.png) |
| **`/events/{slug}/seating`** — the seat grid reads like a control diagram. | |
| ![Tournaments, dark](/screens/tournaments-dark.png) | ![Tournaments, light](/screens/tournaments-light.png) |
| **`/events/{slug}/tournaments`** — brackets, check-in and match actions. | |
| ![Schedule, dark](/screens/schedule-dark.png) | ![Schedule, light](/screens/schedule-light.png) |
| **`/events/{slug}/schedule`** — the programme, with favourites. | |
| ![Files, dark](/screens/files-dark.png) | ![Files, light](/screens/files-light.png) |
| **`/events/{slug}/files`** — the LAN file host: ISOs, patches, avatars. | |
| ![Catering, dark](/screens/catering-dark.png) | ![Catering, light](/screens/catering-light.png) |
| **`/events/{slug}/catering`** — menus, prices, one order per person. | |
| ![Voting, dark](/screens/voting-dark.png) | ![Voting, light](/screens/voting-light.png) |
| **`/events/{slug}/polls`** — polls and the MVP vote. | |
| ![LFG, dark](/screens/lfg-dark.png) | ![LFG, light](/screens/lfg-light.png) |
| **`/events/{slug}/lfg`** — "looking for group", the one-click round. | |
| ![Presence, dark](/screens/presence-dark.png) | ![Presence, light](/screens/presence-light.png) |
| **`/events/{slug}/presence`** — where people are, on the LAN and in voice. | |
| ![Jukebox, dark](/screens/jukebox-dark.png) | ![Jukebox, light](/screens/jukebox-light.png) |
| **`/events/{slug}/jukebox`** — the shared queue. | |
| ![Gallery, dark](/screens/gallery-dark.png) | ![Gallery, light](/screens/gallery-light.png) |
| **`/events/{slug}/gallery`** — photos, moderation, the recap source. | |
| ![Recap, dark](/screens/recap-dark.png) | ![Recap, light](/screens/recap-light.png) |
| **`/events/{slug}/recap`** — the finished weekend in one page. | |

## Games library and MCP

| Dark | Light |
|---|---|
| ![Games library, dark](/screens/games-dark.png) | ![Games library, light](/screens/games-light.png) |
| **`/games`** — every published recipe, the LAN dot, the "Nur LAN-Spiele" filter, running tournaments and the one-click round. | |
| ![UT2004 game page, dark](/screens/game-ut2004-dark.png) | ![UT2004 game page, light](/screens/game-ut2004-light.png) |
| **`/games/unreal-tournament-2004`** — install/launch, the live server, files from the LAN, the avatar multi-select and `ut.raute1.org`. | |
| ![MCP pairing, dark](/screens/mcp-connect-pending-dark.png) | ![MCP pairing, light](/screens/mcp-connect-pending-light.png) |
| **`/mcp/connect`** — the code from `lanomat-agent login`, approved in the browser. | |

## Orga and admin

| Dark | Light |
|---|---|
| ![Admin, dark](/screens/admin-dark.png) | ![Admin, light](/screens/admin-light.png) |
| **`/admin`** — Filament, the same Signalpult shell as the app. | |

The beamer (`/screen/{event}`) is the loud surface: large type, the live dot, no chrome.

| Dark | Light |
|---|---|
| ![Infoscreen, dark](/screens/infoscreen-dark.png) | ![Infoscreen, light](/screens/infoscreen-light.png) |
| **`/screen/{event}`** — the infoscreen scenes (beamer; dark is the venue default). | |
| ![Infoscreen recap, dark](/screens/infoscreen-recap-dark.png) | ![Infoscreen recap, light](/screens/infoscreen-recap-light.png) |
| **`/screen/{event}`** — the recap scene after the last match. | |

## Desktop voice client

| Screenshot | What it shows |
|---|---|
| ![Voice client connect](/screens/voice-app/01-connect.png) | **Connect** — the device-flow code from `/talk/connect`. |
| ![Voice client now](/screens/voice-app/10-jetzt.png) | **Jetzt** — the event feed: matches, votes, orders. |
| ![Voice client match](/screens/voice-app/13-jetzt-match.png) | **Match** — the match card with the voice room and join link. |

The native client lives in `clients/voice/`; the download page is `/app`, and the API lives in
the repository under `docs/infra/native-voice-client-api.md`.
