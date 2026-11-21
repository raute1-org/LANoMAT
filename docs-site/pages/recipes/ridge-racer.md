---
title: Ridge Racer Type 4
description: A self-hosted browser arcade cabinet with 2P netplay, Race Day and an optional native recomp.
sidebar_icon: car
---

Ridge Racer Type 4 is the counter-example to UT2004: the base content is not an installer but a
**self-hosted cabinet**. One Node process serves the disc, the BIOS, the soundtrack and an
EmulatorJS front end; every player runs a PCSX-Rearmed core in their own browser. The recipe installs
the cabinet, places the user's disc and BIOS, starts it on port 8080 and optionally adds a native
Windows recomp.

<div class="live">LIVE 17:42 · cabinet up :8080 · 2P lounge · 12 drivers waiting</div>

The recipe is published, so the games library lists it with a LAN badge, an install button and a start
button that opens the cabinet.

| Dark | Light |
|---|---|
| ![Games library filtered to LAN games, with Ridge Racer Type 4 and UT2004 install buttons, dark](/screens/games-filter-dark.png) | ![Games library filtered to LAN games, with Ridge Racer Type 4 and UT2004 install buttons, light](/screens/games-filter-light.png) |
| `GAMES · NUR LAN-SPIELE · R4 · INSTALLIEREN · STARTEN` | `GAMES · LIGHT` |

## What the cabinet is

| Part | What it does |
|---|---|
| `_server` | Node 20+ Express app, serves EmulatorJS, the ROM, BIOS and the relay |
| `disc/` | The user's own dump: `r4.chd` for the browser, CUE/BIN for the native path |
| `_server/game/bios/` | Optional `scph5501.bin`; the cabinet ships OpenBIOS and boots without it |
| `/multiplayer` | 2P lounge with room code, DISC selector, teams and READY |
| `/race-day` | Up to 50 seats on one shared clock (MVP target) |
| `_server/PLAY-QA.md` | The known QA state, read it before promising a mode |

The disc and BIOS are user-supplied. The recipe never downloads game data. Serial **SLUS-00797**
(NTSC-U) is the supported target; other regions may boot but are not the tested path.

## The recipe, abbreviated

```yaml
schema: lanomat.recipe/v1
slug: ridge-racer-type-4
title: Ridge Racer Type 4
aliases: ["r4", "ridge racer 4", "rr4", "type 4"]
version: "1.0.0"
platforms: [windows, linux]
requires:
  - role: iso
    label: "Ridge Racer Type 4 (USA) disc image"
    match: ["*ridge*racer*4*.bin", "*R4*.cue", "*ridge*racer*4*.chd", "R4.chd"]
    required: true
  - role: base
    label: "PlayStation BIOS (scph5501.bin, NTSC-U)"
    match: ["scph5501.bin", "scph*.bin"]
    required: true
  - role: tool
    label: "Node.js 20 or newer"
    match: []
    required: true
  - role: tool
    label: "Ridge Racer 4 Recomp (optional native bundle)"
    match: ["Ridge_Racer_4_Recomp_Windows*.zip"]
    required: false
```

The BIOS entry is marked required but the notes say the cabinet can boot with OpenBIOS/HLE. That is
intentional: a real NTSC-U dump is the recommended baseline, while the installer knows the fallback.
Node.js is a tool requirement with no LAN match — it is checked on the machine, not downloaded.

In the panel the same recipe is one row: version, status, the number of required files and the last
sync. Ridge Racer Type 4 sits next to UT2004 as **Veröffentlicht**; the drafts around it are the rest
of the library.

| Dark | Light |
|---|---|
| ![Admin recipe list with Ridge Racer Type 4 published among draft recipes, dark](/screens/admin-recipes-dark.png) | ![Admin recipe list with Ridge Racer Type 4 published among draft recipes, light](/screens/admin-recipes-light.png) |
| `ADMIN · SPIELREZEPTE · R4 · VERÖFFENTLICHT · ENTWURF` | `SPIELREZEPTE · LIGHT` |

## Install in six steps

1. **Clone the cabinet** into the target, e.g. `C:\Games\RidgeRacer4`.
2. **Install dependencies:** `npm --prefix <target>/_server install`. No build step.
3. **Place the disc** in `<target>/disc/`: `r4.chd` for the browser path, or the CUE/BIN pair for
   the native recomp. Do not rename tracks inside a CUE.
4. **Place the BIOS** in `<target>/_server/game/bios/scph5501.bin` if you have one.
5. **Start it:** `_server\start-test.bat` on Windows, `node _server/src/server.js` on Linux.
6. **Open** `http://localhost:8080/` on the host, `http://<host-lan-ip>:8080/` elsewhere. The field
   manual is at `/docs` on the same server.

```bash
lanomat-agent ask "install ridge racer type 4 for the LAN"
```

The agent reads the skill, finds the disc and BIOS on the LAN file host (or accepts local paths),
installs the Node dependencies and verifies the cabinet answers on 8080.

The requirement block is where the disc, the BIOS and the optional recomp are described: a role, a
label and the match globs the file host is searched with. A `tool` requirement such as Node.js has no
glob — it is checked on the machine.

| Dark | Light |
|---|---|
| ![Admin recipe edit form with requirement role, file-name patterns and the required toggle, dark](/screens/admin-recipe-edit-dark.png) | ![Admin recipe edit form with requirement role, file-name patterns and the required toggle, light](/screens/admin-recipe-edit-light.png) |
| `ADMIN · REZEPT · ROLLE · MUSTER · PFLICHT` | `REZEPT · LIGHT` |

## 2P and Race Day

- **2P lounge** (`/multiplayer`): room code, disc selector, teams, READY. On R4 this starts a real
  split-screen VS Battle. The guest stream is video-only; the guest hears the game through the
  host's speakers.
- **Race Day** (`/race-day`): one race, one clock, up to 50 seats in the MVP target. The server owns
  the grid and standings, not the video. The 50 is verified with synthetic WebSocket clients, not
  50 humans.
- **Native fallback** under `/lobby` needs ffmpeg; the browser cabinet is the primary LAN path.

## Verification and pitfalls

- `curl -fsS http://localhost:8080/` answers, and `/play?game=r4` boots to the intro.
- The car select accepts input, audio plays and the pad/keyboard is detected.
- For 2P, two browsers see the same room and the same race plan; both seats must press READY.
- **Port 8080 in use:** stop the old cabinet (`_server\stop.bat`) or choose another port. A
  long-running instance may predate the current room protocol.
- **Black page / asks for a disc:** the deck loads `disc/r4.chd`; a renamed CHD or a CUE without its
  BIN will not boot.
- **Guest has no audio:** expected; the host stream captures video only.
- **Native recomp zooms the desktop:** set `settings.toml` `[video] fullscreen = 0`. Exclusive
  fullscreen switched a test desktop to 1024×768; borderless is not verified.

<div class="callout-live">
  <strong>A recipe is not always an installer.</strong> UT2004 installs files; Ridge Racer starts a
  service. The schema carries both because steps are just commands with checks, and the skill
  explains what "running" means for this game.
</div>
