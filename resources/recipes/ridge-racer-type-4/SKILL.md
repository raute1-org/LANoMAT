---
name: ridge-racer-type-4
description: "Set up Ridge Racer Type 4 on the LAN from the user's own disc: clone the self-hosted browser cabinet, install Node dependencies, place r4.chd and the BIOS, start the server on port 8080, and optionally install the native Windows recomp. Use when the user says install Ridge Racer Type 4, R4, RR4, or type 4 for the LAN."
---

# Ridge Racer Type 4

Ridge Racer Type 4 (Namco, 1998) runs on the LAN as a self-hosted browser
arcade: one Node process serves the disc, BIOS, soundtrack and EmulatorJS, and
every player runs a PCSX-Rearmed core in their own page. A native Windows
recomp is available as an optional alternative.

Repo: https://github.com/reneil1337/riiiiiiiiiidge-raaaaaaaaaaaaceer

## When to use

Use this skill when the user says:

- "install ridge racer type 4 for me"
- "set up R4 on the LAN", "Ridge Racer 4", "RR4", "type 4"
- "run the Ridge Racer cabinet", "start the 2P lounge"
- "install the native Ridge Racer 4 recomp"

R4 is the only disc with a real two-car VS Battle. Rage Racer and Ridge Racer
(1994) can be added to the same cabinet later, but they are different recipes
of play (shared car or ghost) and not part of this setup.

## What you need

- The user's own Ridge Racer Type 4 USA dump, serial **SLUS-00797**. The
  browser deck wants `r4.chd` (about 341 MB); the native recomp reads CUE/BIN.
- A PlayStation BIOS dump (`scph5501.bin`) if the user has one. The cabinet
  ships OpenBIOS and boots without it, but a retail NTSC-U BIOS is the better
  baseline. Never download a BIOS for the user.
- Node.js 20 or newer.
- About 1 GB for the cabinet, plus the disc.
- Windows or Linux for the browser path; Windows for the native recomp.

## Steps

1. **Clone the repo.** `git clone <repo> {{target}}`, for example
   `C:\Games\RidgeRacer4`.
2. **Install dependencies.** `npm --prefix {{target}}/_server install`. There
   is no build step for the browser cabinet.
3. **Place the disc.** Copy the user's dump to `{{target}}/disc/`:
    - Browser: `disc/r4.chd`, served from `/rom/r4.chd`.
    - Native: the CUE/BIN pair, with the CUE kept beside its `.bin`.
      Do not rename tracks inside a CUE, and do not use a different region as the
      baseline.
4. **Place the BIOS.** Copy `scph5501.bin` to
   `{{target}}/_server/game/bios/`. The deck can select it with
   `?bios=/bios/scph5501.bin`; `?bios=hle` forces the built-in fallback.
5. **Start the cabinet.** Windows: run `_server\start-test.bat` in its own
   window. Linux: `node _server/src/server.js`. The host must stay awake.
6. **Open the cabinet.** http://localhost:8080/ on the host, or
   `http://<host-lan-ip>:8080/` on any device on the LAN. The Zudoku field
   manual is at http://localhost:8080/docs.
7. **Optional native recomp.** Download
   `Ridge_Racer_4_Recomp_Windows.zip` from
   https://github.com/omegakatana92/RidgeRacer4Recomp, extract it to
   `{{target}}/RidgeRacer4Recomp`, and run `launcher\ridgeRacer4.bat`. It
   bundles OpenBIOS, verifies SLUS-00797 and reads the same `disc/` folder.
8. **Check it.** Boot `http://localhost:8080/play?game=r4` once and confirm the
   intro and car select work. For the 2P path, open `/multiplayer`, create a
   room, and join from a second device; read the card's mode line before
   promising split-screen.

## 2P and Race Day

- **2P lounge** (`/multiplayer`): room code, DISC selector, teams, READY. R4
  starts a real split-screen VS Battle. The guest stream is video-only; the
  guest hears the game only through the host's speakers.
- **Race Day** (`/race-day`): one race, one shared clock, up to 50 seats in the
  MVP target. Every driver boots R4 in their own browser; the server owns the
  grid and standings, not video.
- **Native fallback** lives under `/lobby` and needs ffmpeg; the browser deck
  is the primary path.

## Verification

- `{{target}}/_server/package.json` exists and `npm --prefix ... install`
  completed.
- `{{target}}/disc/r4.chd` (browser) or the CUE/BIN pair (native) exists.
- `curl -fsS http://localhost:8080/` answers and `/play?game=r4` boots.
- The main menu, the intro audio and the pad/keyboard input work.
- For 2P, two browsers see the same room and the same race plan.

## Troubleshooting

- **Port 8080 already in use**: stop the old cabinet (`_server\stop.bat`) or
  start on another port; a long-running instance may predate the current room
  protocol.
- **Game asks for a disc / black page**: check the file name and folder. The
  deck loads `disc/r4.chd`; a renamed CHD or a CUE without its BIN will not
  boot.
- **No BIOS**: the deck falls back to OpenBIOS/HLE. If a retail dump is
  present but ignored, pass `?bios=/bios/scph5501.bin`.
- **Guest has no audio**: expected. The host stream captures video only; use
  the host's speakers or play local 2P on one machine.
- **Pad not detected**: open EmulatorJS Control Settings and select the pad;
  test at `/play`, not the bare host root.
- **Native recomp shows a zoomed desktop**: set `settings.toml`
  `[video] fullscreen = 0`; exclusive fullscreen switched a test desktop to
  1024x768. Borderless is not verified.
- **2P race does not start**: both seats must press READY. On Rage/Ridge the
  shared-car path is not the R4 VS Battle path.

## Files and docs

- `_server/` — server, EmulatorJS assets, public pages, relay.
- `disc/` — user-supplied CUE/BIN/CHD, served at `/rom/`.
- `_server/README.md`, `_server/PLAY-QA.md`, `NATIVE-RECOMP-SETUP.md`.
- `docs/` — Zudoku manual, built to `docs/dist/docs` and served at `/docs`.

## License

Ridge Racer Type 4 belongs to Namco. The cabinet repo contains no game data;
the user supplies the disc and BIOS. The native recomp bundles OpenBIOS, not
the game.
