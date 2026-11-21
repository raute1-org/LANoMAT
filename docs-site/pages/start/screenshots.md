---
title: Screenshots
description: The real LANoMAT app — participant pages, teams and friends, orga check-in, the Filament resources, the beamer, the agent and the desktop client — in light and dark. Every image is captured from the seeded demo instance, not a mock-up.
sidebar_icon: image
---

Every screenshot on this page comes from the seeded demo instance (`ScreenshotSeeder` + `npm run screenshots`) at 1440×900. Light and dark are the same page, not two designs: layout, spacing and state stay identical, only the tokens change.

## Participant app

The participant app is calm on purpose: one primary action per screen, mono only for machine data, and the amber signal reserved for *now*.

| Dark | Light |
|---|---|
| ![Home page with the LANoMAT mark and the way into Discord login, dark](/screens/home-dark.png) | ![Home page with the LANoMAT mark and the way into Discord login, light](/screens/home-light.png) |
| `HOME · DARK` — the guest landing and the Discord login | `HOME · LIGHT` — same landing, paper tokens |
| ![Public event page with live status, countdown, free spots and registration, dark](/screens/event-page-dark.png) | ![Public event page with live status, countdown, free spots and registration, light](/screens/event-page-light.png) |
| `EVENT PAGE · DARK` — `/events/{slug}`, countdown, spots, arrival | `EVENT · LIGHT` |
| ![Seat grid with claimed, free and blocked seats and the participant list, dark](/screens/seating-dark.png) | ![Seat grid with claimed, free and blocked seats and the participant list, light](/screens/seating-light.png) |
| `SEATING · DARK` — claimed, free, blocked | `SEATING · LIGHT` |
| ![Tournament list with check-in state and the match actions, dark](/screens/tournaments-dark.png) | ![Tournament list with check-in state and the match actions, light](/screens/tournaments-light.png) |
| `TOURNAMENTS · DARK` — brackets, check-in, match actions | `TOURNAMENTS · LIGHT` |
| ![Event schedule with the programme slots and favourites, dark](/screens/schedule-dark.png) | ![Event schedule with the programme slots and favourites, light](/screens/schedule-light.png) |
| `SCHEDULE · DARK` — programme, now/next, favourites | `SCHEDULE · LIGHT` |
| ![Event file host with ISOs, patches and avatars plus quota and moderation, dark](/screens/files-dark.png) | ![Event file host with ISOs, patches and avatars plus quota and moderation, light](/screens/files-light.png) |
| `FILES · DARK` — ISOs, patches, avatars | `FILES · LIGHT` |
| ![Catering page with the menu, prices and a placed order, dark](/screens/catering-dark.png) | ![Catering page with the menu, prices and a placed order, light](/screens/catering-light.png) |
| `CATERING · DARK` — menu, prices, one order per person | `CATERING · LIGHT` |
| ![Poll page with an open vote and its result bars, dark](/screens/voting-dark.png) | ![Poll page with an open vote and its result bars, light](/screens/voting-light.png) |
| `VOTING · DARK` — open poll, the MVP vote | `VOTING · LIGHT` |
| ![Looking-for-group page with open rounds and one-click join, dark](/screens/lfg-dark.png) | ![Looking-for-group page with open rounds and one-click join, light](/screens/lfg-light.png) |
| `LFG · DARK` — looking for group, the one-click round | `LFG · LIGHT` |
| ![Presence page showing who is online, on the LAN and in voice, dark](/screens/presence-dark.png) | ![Presence page showing who is online, on the LAN and in voice, light](/screens/presence-light.png) |
| `PRESENCE · DARK` — on the LAN, playing, in voice | `PRESENCE · LIGHT` |
| ![Jukebox with the vote-ordered shared queue, dark](/screens/jukebox-dark.png) | ![Jukebox with the vote-ordered shared queue, light](/screens/jukebox-light.png) |
| `JUKEBOX · DARK` — the shared, vote-ordered queue | `JUKEBOX · LIGHT` |
| ![Gallery with approved photos, highlights and moderation state, dark](/screens/gallery-dark.png) | ![Gallery with approved photos, highlights and moderation state, light](/screens/gallery-light.png) |
| `GALLERY · DARK` — photos, highlights, moderation | `GALLERY · LIGHT` |
| ![Event recap with podium, photos and the song list, dark](/screens/recap-dark.png) | ![Event recap with podium, photos and the song list, light](/screens/recap-light.png) |
| `RECAP · DARK` — the finished weekend in one page | `RECAP · LIGHT` |

## Teams, friends and stats

Teams, the leaderboard and friend requests are cross-event: they survive the weekend and are the same on every LAN. The notification bell sits in the app shell and opens over any page.

| Dark | Light |
|---|---|
| ![Teams page with the create-team button and the empty state, dark](/screens/teams-dark.png) | ![Teams page with the create-team button and the empty state, light](/screens/teams-light.png) |
| `TEAMS · DARK` — `/teams`, create and join; empty state | `TEAMS · LIGHT` |
| ![Leaderboard ranking players by wins, tournament wins, participations and podiums, dark](/screens/leaderboard-dark.png) | ![Leaderboard ranking players by wins, tournament wins, participations and podiums, light](/screens/leaderboard-light.png) |
| `LEADERBOARD · DARK` — `/stats/leaderboard`, wins and podiums | `LEADERBOARD · LIGHT` |
| ![Friends page with open requests, friends and suggestions from shared events, dark](/screens/friends-dark.png) | ![Friends page with open requests, friends and suggestions from shared events, light](/screens/friends-light.png) |
| `FRIENDS · DARK` — requests, friends, suggestions | `FRIENDS · LIGHT` |
| ![Notification bell dropdown open on the dashboard, dark](/screens/notifications-dark.png) | ![Notification bell dropdown open on the dashboard, light](/screens/notifications-light.png) |
| `NOTIFICATIONS · DARK` — the bell dropdown in the shell | `NOTIFICATIONS · LIGHT` |

## Servers, voice and account

Game servers stay available between events, the event page lists the ones running now, and voice has two doors: the browser (`/talk`) and the desktop client. Account connections and the client download live in the settings.

| Dark | Light |
|---|---|
| ![Permanent servers page with the empty state before the first server, dark](/screens/servers-dark.png) | ![Permanent servers page with the empty state before the first server, light](/screens/servers-light.png) |
| `SERVER OVERVIEW · DARK` — `/servers`, the LAN's servers and the permanent ones | `SERVER OVERVIEW · LIGHT` |
| ![Event game server card with address, port, memory estimate, ready status and connect button, dark](/screens/event-servers-dark.png) | ![Event game server card with address, port, memory estimate, ready status and connect button, light](/screens/event-servers-light.png) |
| `EVENT SERVERS · DARK` — UT2004 DM-Antalus, ready, connect | `EVENT SERVERS · LIGHT` |
| ![Account connections for Steam and Twitch plus the desktop-app download, dark](/screens/connections-dark.png) | ![Account connections for Steam and Twitch plus the desktop-app download, light](/screens/connections-light.png) |
| `CONNECTIONS · DARK` — `/settings/connections`, Steam, Twitch, app | `CONNECTIONS · LIGHT` |
| ![Browser voice page with the empty state before the first team room, dark](/screens/talk-dark.png) | ![Browser voice page with the empty state before the first team room, light](/screens/talk-light.png) |
| `TALK · DARK` — `/talk`, team rooms appear with the match | `TALK · LIGHT` |

## Games library and MCP

Every published recipe, and the pairing flow that connects an agent to the account.

| Dark | Light |
|---|---|
| ![Games library with published recipes, the LAN filter and running tournaments, dark](/screens/games-dark.png) | ![Games library with published recipes, the LAN filter and running tournaments, light](/screens/games-light.png) |
| `GAMES · DARK` — every recipe, LAN dot, the one-click round | `GAMES · LIGHT` |
| ![UT2004 game page with install and launch steps, the live server and LAN files, dark](/screens/game-ut2004-dark.png) | ![UT2004 game page with install and launch steps, the live server and LAN files, light](/screens/game-ut2004-light.png) |
| `GAME · DARK` — install, launch, live server, LAN files | `GAME · LIGHT` |
| ![MCP pairing page with the approval prompt for pi · demo-laptop, its code and two paired devices, dark](/screens/mcp-connect-pending-dark.png) | ![MCP pairing page with the approval prompt for pi · demo-laptop, its code and two paired devices, light](/screens/mcp-connect-pending-light.png) |
| `MCP · DARK` — approve the code from `lanomat-agent login` | `MCP · LIGHT` |

## Orga: registration and check-in

Registration is participant-facing — ticket choice plus optional arrival and departure. Check-in is orga work: camera scan on the phone, or the token typed in by hand. The QR poster has no theme, so its two captures are identical.

| Dark | Light |
|---|---|
| ![Registration page with ticket choice, free spots and arrival and departure fields, dark](/screens/register-dark.png) | ![Registration page with ticket choice, free spots and arrival and departure fields, light](/screens/register-light.png) |
| `REGISTER · DARK` — `/events/{slug}/register`, ticket and arrival | `REGISTER · LIGHT` |
| ![Orga check-in with the camera scan button, manual token field and check-in action, dark](/screens/orga-checkin-dark.png) | ![Orga check-in with the camera scan button, manual token field and check-in action, light](/screens/orga-checkin-light.png) |
| `ORGA CHECK-IN · DARK` — scan or type the token | `ORGA CHECK-IN · LIGHT` |
| ![Printable check-in QR poster with the event name and the check-in URL, dark](/screens/orga-checkin-qr-dark.png) | ![Printable check-in QR poster with the event name and the check-in URL, light](/screens/orga-checkin-qr-light.png) |
| `CHECK-IN QR · DARK` — the poster for the entrance | `CHECK-IN QR · LIGHT` — no theme, same file |

## Admin: overview and events

The Filament panel at `/admin` wears the same mark and the same neutral shell as the app, with an "Admin" chip instead of the signal colour. The dashboard's Preflight traffic light is the first thing to read on a fresh host.

| Dark | Light |
|---|---|
| ![Filament dashboard with the Preflight traffic light for database, Redis, storage, jobs, Reverb, scheduler and queue worker, dark](/screens/admin-dark.png) | ![Filament dashboard with the Preflight traffic light for database, Redis, storage, jobs, Reverb, scheduler and queue worker, light](/screens/admin-light.png) |
| `ADMIN · DARK` — `/admin`, Preflight traffic light | `ADMIN · LIGHT` |
| ![Events list with draft, registration open, live and finished events and their public links, dark](/screens/admin-events-dark.png) | ![Events list with draft, registration open, live and finished events and their public links, light](/screens/admin-events-light.png) |
| `ADMIN EVENTS · DARK` — lifecycle states, public link, start | `ADMIN EVENTS · LIGHT` |

## Admin: people and seats

Cross-event people data and the seat grid. Seat rows are created in bulk and edited per seat, which is why seating has its own resource instead of an event tab.

| Dark | Light |
|---|---|
| ![Users list with roles and registration dates, dark](/screens/admin-users-dark.png) | ![Users list with roles and registration dates, light](/screens/admin-users-light.png) |
| `ADMIN USERS · DARK` — roles, registration date | `ADMIN USERS · LIGHT` |
| ![Teams list with captain and members and the empty state, dark](/screens/admin-teams-dark.png) | ![Teams list with captain and members and the empty state, light](/screens/admin-teams-light.png) |
| `ADMIN TEAMS · DARK` — captain, members; empty state | `ADMIN TEAMS · LIGHT` |
| ![Seats list with grid creation, seat labels and the participant assigned to each seat, dark](/screens/admin-seats-dark.png) | ![Seats list with grid creation, seat labels and the participant assigned to each seat, light](/screens/admin-seats-light.png) |
| `ADMIN SEATS · DARK` — create the grid, assign per seat | `ADMIN SEATS · LIGHT` |

## Admin: programme and catering

The programme feed behind the participant schedule, the food-order windows behind catering, and the news that lands on the dashboard.

| Dark | Light |
|---|---|
| ![Schedule items list with tournament and food slots and their start times, dark](/screens/admin-schedule-dark.png) | ![Schedule items list with tournament and food slots and their start times, light](/screens/admin-schedule-light.png) |
| `ADMIN SCHEDULE · DARK` — tournament and food slots | `ADMIN SCHEDULE · LIGHT` |
| ![Food orders list with the midnight-pizza window, open status and closing time, dark](/screens/admin-food-orders-dark.png) | ![Food orders list with the midnight-pizza window, open status and closing time, light](/screens/admin-food-orders-light.png) |
| `ADMIN FOOD ORDERS · DARK` — order windows, open until | `ADMIN FOOD ORDERS · LIGHT` |
| ![News list with published posts and the retract action, dark](/screens/admin-news-dark.png) | ![News list with published posts and the retract action, light](/screens/admin-news-light.png) |
| `ADMIN NEWS · DARK` — publish and retract | `ADMIN NEWS · LIGHT` |

## Admin: tournaments, games and files

Tournament rules live on the game, disputed matches land in one queue, and the file host plus the gallery are moderated from here.

| Dark | Light |
|---|---|
| ![Games list with slug, team size limits and the Pelican egg id, dark](/screens/admin-games-dark.png) | ![Games list with slug, team size limits and the Pelican egg id, light](/screens/admin-games-light.png) |
| `ADMIN GAMES · DARK` — slug, team size, Pelican egg id | `ADMIN GAMES · LIGHT` |
| ![Disputes list with tournament, round, reporter and report time and the empty state, dark](/screens/admin-disputes-dark.png) | ![Disputes list with tournament, round, reporter and report time and the empty state, light](/screens/admin-disputes-light.png) |
| `ADMIN DISPUTES · DARK` — reported matches, one queue | `ADMIN DISPUTES · LIGHT` |
| ![Shared files with event, uploader, size, release status and storage backend, dark](/screens/admin-shared-files-dark.png) | ![Shared files with event, uploader, size, release status and storage backend, light](/screens/admin-shared-files-light.png) |
| `ADMIN FILES · DARK` — uploader, size, released, storage | `ADMIN FILES · LIGHT` |
| ![Event photos with captions, status, highlight toggle and the release action, dark](/screens/admin-event-photos-dark.png) | ![Event photos with captions, status, highlight toggle and the release action, light](/screens/admin-event-photos-light.png) |
| `ADMIN PHOTOS · DARK` — approve, highlight, retract | `ADMIN PHOTOS · LIGHT` |

## Admin: infrastructure

The optional tails of the stack: hosts that run game servers, the containers on them, the servers themselves and the room receiver.

| Dark | Light |
|---|---|
| ![Remote hosts list with SSH port, key, role, event and last check and the empty state, dark](/screens/admin-remote-hosts-dark.png) | ![Remote hosts list with SSH port, key, role, event and last check and the empty state, light](/screens/admin-remote-hosts-light.png) |
| `ADMIN REMOTE HOSTS · DARK` — SSH host, key, role, last check | `ADMIN REMOTE HOSTS · LIGHT` |
| ![Custom servers list with remote host, event, Docker image, container name and status and the empty state, dark](/screens/admin-custom-servers-dark.png) | ![Custom servers list with remote host, event, Docker image, container name and status and the empty state, light](/screens/admin-custom-servers-light.png) |
| `ADMIN CUSTOM SERVERS · DARK` — Docker image, container, status | `ADMIN CUSTOM SERVERS · LIGHT` |
| ![Game servers started from a preset with Pelican status, address and the deprovision action, dark](/screens/admin-server-links-dark.png) | ![Game servers started from a preset with Pelican status, address and the deprovision action, light](/screens/admin-server-links-light.png) |
| `ADMIN GAME SERVERS · DARK` — preset start, status, address | `ADMIN GAME SERVERS · LIGHT` |
| ![Room control with receiver state, volume, source, sound mode and the volume cap, dark](/screens/admin-room-control-dark.png) | ![Room control with receiver state, volume, source, sound mode and the volume cap, light](/screens/admin-room-control-light.png) |
| `ADMIN ROOM CONTROL · DARK` — receiver, volume, source, cap | `ADMIN ROOM CONTROL · LIGHT` |

## Beamer

The beamer (`/screen/{event}`) is the loud surface: large type, the live dot, no chrome. Dark is the venue default.

| Dark | Light |
|---|---|
| ![Infoscreen with large type, the live dot and no app chrome, dark](/screens/infoscreen-dark.png) | ![Infoscreen with large type, the live dot and no app chrome, light](/screens/infoscreen-light.png) |
| `INFOSCREEN · DARK` — the infoscreen scenes | `INFOSCREEN · LIGHT` |
| ![Infoscreen recap scene with podium and highlights after the last match, dark](/screens/infoscreen-recap-dark.png) | ![Infoscreen recap scene with podium and highlights after the last match, light](/screens/infoscreen-recap-light.png) |
| `RECAP SCENE · DARK` — beamer after the LAN | `RECAP SCENE · LIGHT` |

## Agent

The agent's CLI on a paired machine: the dry-run plan before anything is downloaded, and the doctor report that says whether the install can work.

| Dark | Light |
|---|---|
| ![Terminal with the agent dry-run plan listing files, numbered steps and the target folder, dark](/screens/agent-install-terminal-dark.png) | ![Terminal with the agent dry-run plan listing files, numbered steps and the target folder, light](/screens/agent-install-terminal-light.png) |
| `AGENT · DARK` — files, steps, dry run, target folder | `AGENT · LIGHT` |
| ![Terminal with the doctor report checking config, server, MCP, skills, key and model, dark](/screens/agent-doctor-terminal-dark.png) | ![Terminal with the doctor report checking config, server, MCP, skills, key and model, light](/screens/agent-doctor-terminal-light.png) |
| `DOCTOR · DARK` — config, server, MCP, skills, model | `DOCTOR · LIGHT` |

## Desktop voice client

| Screenshot | What it shows |
|---|---|
| ![Voice client connect](/screens/voice-app/01-connect.png) | **Connect** — the device-flow code from `/talk/connect`. |
| ![Voice client now](/screens/voice-app/10-jetzt.png) | **Jetzt** — the event feed: matches, votes, orders. |
| ![Voice client match](/screens/voice-app/13-jetzt-match.png) | **Match** — the match card with the voice room and join link. |

The native client lives in `clients/voice/`; the download page is `/app`, and the API lives in
the repository under `docs/infra/native-voice-client-api.md`.
