---
title: The system
description: LANoMAT's 36 modules, the module contract, and how core and optional modules fit together.
sidebar_icon: layout-dashboard
---

LANoMAT is a **modular monolith**. Every domain lives in `app/Modules/<Name>/` with the same shape:
`Models`, `Actions`, `Policies`, `Filament`, `Jobs`, `Events`, `Contracts`. Modules talk through
Laravel events and explicit contracts; no module reaches into another module's tables.

<div class="stat-row">
  <div class="stat"><span>Modules</span><strong>36</strong></div>
  <div class="stat"><span>Core</span><strong>9</strong></div>
  <div class="stat"><span>Optional</span><strong>27</strong></div>
  <div class="stat"><span>Database</span><strong>Postgres 16</strong></div>
  <div class="stat"><span>Realtime</span><strong>Reverb</strong></div>
</div>

Every module lands in one shell: the participant app for Teilnehmer, the Filament panel for orga.
The dashboard is the participant's entry point — current LAN, ticket, seat and teams.

| Dark | Light |
|---|---|
| ![Participant dashboard with current LAN, ticket, seat and teams](/screens/dashboard-dark.png) | ![Participant dashboard in light mode](/screens/dashboard-light.png) |
| `DASHBOARD · DARK` — current LAN, ticket, seat, teams | `DASHBOARD · LIGHT` — same state, paper tokens |

## The module contract

| Layer | Rule |
|---|---|
| Models | Only the owning module queries its tables. Other modules use a read-model or the model's public API. |
| Actions | One class per use case (`RegisterForEvent`, `SubmitMatchReport`). Controllers and Filament resources stay thin. |
| Policies | Every authorization goes through a policy. Never trust a client-supplied user id. |
| Events | Cross-module fan-out is an event (`MatchReady`, `ServerLinkUpdated`, `GalleryUpdated`). |
| Contracts | External systems are behind `DiscordClient`, `MumbleClient`, `PelicanClient`, `FileStore`, `RemoteExecutor`, `MusicClient`, `ReceiverClient`, `HypervisorClient`. Fakes back every test. |
| Filament | Admin resources, pages and widgets live in the module's `Filament/` folder. |
| Tests | Mirrored under `tests/{Feature,Unit}/<Name>/`; the bracket engine is pure domain code with exhaustive property tests. |

The Filament layer is the orga's half of the contract: one admin panel, one Preflight traffic light
over database, cache, file store, queue, scheduler and Reverb.

| Dark | Light |
|---|---|
| ![Filament admin dashboard with the Preflight traffic light](/screens/admin-dark.png) | ![Filament admin dashboard in light mode](/screens/admin-light.png) |
| `ADMIN · DARK` — Filament panel, Preflight probes | `ADMIN · LIGHT` — same probes, same states |

## Core and optional

`config/modules.php` resolves the deployment profile: `MODULES_PROFILE=core` (default) or `full`,
plus per-module overrides `MODULE_<KEY>=true|false`. `php artisan lanomat:modules` prints the
result. Disabling a module gates its routes (404), Filament resources, scheduler entries, listeners
and Preflight probes — not the container bindings or Artisan commands.

Hard dependencies (`Module::requires()`, enforced at boot):

| Module | Requires |
|---|---|
| CustomServers, Lancache | Hosts |
| Provisioning | Hosts, Lancache |
| GameRecipes | Files, Mcp |
| Recap | Gallery, Jukebox, Voting, Presence |
| News | Gallery |
| Gallery | Files |
| Stats | Voting |
| Friends | Presence |

An enabled module whose requirement is off fails boot with a list of every violation. Core modules
cannot be disabled.

## Event as aggregate root

An `Event` is one LAN edition. Its lifecycle is
`draft → announced → registration → live → finished → archived`. Registration, seating, tournaments,
schedule, catering, votes, LFG, gallery, jukebox, presence and recap hang off it.

`User`, `Team` and `Game` are cross-event. That is why history, leaderboards and recaps across
several LANs fall out of the model instead of needing a second schema.

The public event page is the participant's view of the aggregate: status, dates, spots left and
arrival info, with the sign-up button appearing only when `RegistrationAvailability` says `open`.

| Dark | Light |
|---|---|
| ![Public event page with status, spots left and arrival info](/screens/event-page-dark.png) | ![Public event page in light mode](/screens/event-page-light.png) |
| `EVENT · DARK` — status, spots, arrival info | `EVENT · LIGHT` — same state |

```text
Event (draft … archived)
├── Registration ── ticket, QR, check-in, arrival plan
├── Seating ─────── grid, seat assignment, network metadata
├── Tournaments ─── entries, matches, reports, bracket
├── Schedule ────── slots, favourites, ICS
├── Catering ────── menu, orders, cost split
├── Voting ──────── polls, MVP of the night
├── Lfg ─────────── posts per event
├── Gallery ─────── photos, moderation, zip
├── Files ───────── shared files, quota, file host
├── Jukebox ─────── LAN radio queue
├── Presence ────── who is online/playing/free
├── Infoscreen ──── beamer scenes, tombola
└── Recap ───────── post-LAN projection
```

Cross-event `User` and `Game` records make the leaderboard a plain query, not a per-LAN import: every
tournament result is already attached to the people and games that played it.

| Dark | Light |
|---|---|
| ![Cross-event leaderboard with wins, tournament wins and podiums](/screens/leaderboard-dark.png) | ![Cross-event leaderboard in light mode](/screens/leaderboard-light.png) |
| `LEADERBOARD · DARK` — wins, titles, podiums across LANs | `LEADERBOARD · LIGHT` — same ranking |

## The 36 modules

The group pages below are the map. Each module page has purpose, who uses it, key screens, the
guards that matter and — where one exists — the MCP tool an agent can call.

- **[Event & people](/docs/system/event-people)** — events, registration, seating, teams, friends,
  identity, notifications, schedule, catering, LFG, voting, presence, recap, news.
- **[Competition](/docs/system/competition)** — tournaments, games, game servers, stats, casting.
- **[Site & ops](/docs/system/site-ops)** — game recipes, hosts, custom servers, LanCache,
  provisioning, preflight, room audio, lighting.
- **[Media](/docs/system/media)** — gallery, files, jukebox, infoscreen.
- **[Integrations](/docs/system/integrations)** — Discord, Mumble voice, web voice, MCP, assistant.

The participant-facing **[games library](/docs/system/games-library)** at `/games` is a view over
the recipe catalogue, file host, server links and tournaments: install, launch, join a live server
and announce a round without reading a single YAML file.

## Deployment profiles in practice

The production LAN runs the `core` profile plus the modules the venue uses. Optional modules are
not "unfinished": they are toggles because a small LAN does not need provisioning, and a big one
does not want to run without Discord. Turning one on is a deploy-time decision, not a code change.

The admin event list keeps every edition side by side with the status the profile is actually
running — `draft`, `registration`, `live`, `finished` — so a profile switch is visible at a glance.

| Dark | Light |
|---|---|
| ![Filament event list with draft, registration, live and finished statuses](/screens/admin-events-dark.png) | ![Filament event list in light mode](/screens/admin-events-light.png) |
| `EVENTS · DARK` — every edition and its lifecycle status | `EVENTS · LIGHT` — same list, same badges |

<div class="callout-live">
  <strong>Reading order for operators.</strong> Start with <a href="/system/event-people">Event
  & people</a>, then <a href="/system/competition">Competition</a>. Site & ops is for the
  person who owns the venue network and the servers; Media is for the person running the beamer.
</div>
