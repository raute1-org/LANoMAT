---
name: ridge-racer-type-4
description: "Set up Ridge Racer Type 4 on the LAN from the user's own disc: clone the self-hosted browser cabinet, install Node dependencies, place r4.chd, optionally add a retail BIOS, start the server on port 8080 and open the cabinet at the host's LAN address; optionally install the native recomp. Use when the user says install Ridge Racer Type 4, R4, RR4, or type 4 for the LAN."
---

# Ridge Racer Type 4

Ridge Racer Type 4 (Namco, 1998) runs on the LAN as a self-hosted browser
arcade: one Node process serves the disc, BIOS, soundtrack and EmulatorJS, and
every player runs a PCSX-Rearmed core in their own page. A native recomp is an
optional alternative, not the LAN path.
Repo: https://github.com/reneil1337/riiiiiiiiiidge-raaaaaaaaaaaaceer

## When to use

Use this skill when the user says "install ridge racer type 4 for me", "set up
R4 on the LAN", "Ridge Racer 4", "RR4", "type 4", "run the Ridge Racer
cabinet", "start the 2P lounge" or "install the native recomp".

R4 is the only disc with a real two-car VS Battle. Rage Racer and Ridge Racer
(1994) are shared-car or turn-based ghost variants, not part of this setup.

## What you need

- The user's own Ridge Racer Type 4 USA dump, serial **SLUS-00797**. The
  browser deck wants `disc/r4.chd` (357,817,282 bytes); the native recomp and
  the repo's `/lobby` fallback read the CUE/BIN pair. Other regions are not
  established as compatible.
- A PlayStation BIOS dump (`scph5501.bin`) if the user has one. **Not
  required:** the repo ships OpenBIOS at `_server/game/bios/openbios.bin`
  (served at `/bios/`) and PCSX-Rearmed falls back to HLE. A retail NTSC-U dump
  is the better baseline. Never download a BIOS for the user.
- Node.js 20 or newer (`package.json` pins `engines >=20`).
- The cabinet checkout plus `npm install`, plus the disc: a few hundred MB for
  the checkout and dependencies, and 341 MB for the CHD.
- Windows or Linux. A controller is recommended but not required; the native
  recomp was verified with an Xbox pad.

## Steps

1. **Clone the repo.** `git clone <repo> {{target}}`, for example
   `C:\Games\RidgeRacer4` or `~/Games/RidgeRacer4`.
2. **Install dependencies.** `npm --prefix {{target}}/_server install`. There
   is no build step and no frontend toolchain.
3. **Place the disc.** Copy the user's dump to `{{target}}/disc/`:
    - **Browser:** `disc/r4.chd`, served at `/rom/r4.chd`. Convert a CUE/BIN
      with `chdman createcd` if needed, and keep the name `r4.chd`.
    - **Native:** the CUE/BIN pair with every referenced track beside the CUE.
      The repo's native config expects `disc/R4 - Ridge Racer Type 4 (USA).cue`;
      keep that name or edit `_server/config.json`.
4. **Place the BIOS (optional).** Copy `scph5501.bin` to
   `{{target}}/_server/game/bios/`; the deck selects it with
   `?bios=/bios/scph5501.bin`. `?bios=openbios` forces the bundled image and
   `?bios=hle` the built-in fallback. Skip it entirely if the user has none.
5. **Start the cabinet.** Windows: `_server\start-test.bat` in its own window
   (web server only). Linux: `node {{target}}/_server/src/server.js`. The host
   must stay awake while people play.
6. **Open the cabinet.** The server prints its LAN URL. On the host use
   `http://localhost:8080/` for the ENTER shell; on any other device use
   `http://{{host}}:8080/`. To boot R4 directly:
   `http://{{host}}:8080/play?game=r4`.
7. **Open the firewall.** `_server\firewall.bat` adds inbound TCP 8080; the 2P
   lounge needs TCP 3010, opened by `_server\firewall-netplay.bat` (the
   launchers start the relay).
8. **Optional native recomp.** Windows: extract
   `Ridge_Racer_4_Recomp_Windows.zip` to `{{target}}/RidgeRacer4Recomp` and run
   `launcher\ridgeRacer4.bat`. Linux: mark the AppImage executable and pass
   `--disc /path/to/R4.cue`.
9. **Check it.** `curl -fsS http://localhost:8080/api/health`, boot
   `/play?game=r4` once, and run `node _server/verify-mp.mjs` (add `--full` for
   the two-browser race).

## The play URL and the port

`play_url` in the recipe is `http://{{host}}:8080/play?game=r4`. `{{host}}` is
the LAN address of the PC that runs the cabinet, as printed in the server
banner; `localhost` only works on that PC itself. If the address changes, use
the printed one (or the dynamic `/qr.svg`).

The port is 8080 by default and `RR4_PORT` moves it; the console URL follows,
but the `.bat` launchers, firewall rule and stop scripts still assume 8080.
Opt-in TLS (`RR4_TLS=1`) adds `https://host:8443` beside the plain listener;
single-player needs only inbound TCP 8080.

## Controllers

- **Browser:** Xbox and PlayStation pads through the Gamepad API, plus keyboard
  and touch. Detected pads are assigned into empty slots, but that is
  unverified: if a pad does not respond, open EmulatorJS Control Settings, and
  test at `/play`.
- **Native recomp:** verified with an Xbox One pad on XInput in analog mode,
  configured through the launcher. `input.ini` carries an SDL mapping for other
  pads (not button-by-button verified) and `keybinds.ini` has keyboard
  fallback. If a reconnected pad is not picked up, set `p1_device = "gamepad"`
  or reselect it in the launcher.

## 2P and Race Day

- **2P lounge** (`/multiplayer`): room code, DISC selector, teams, READY. R4
  starts a real split-screen VS Battle. The guest stream is video-only; the
  guest hears the game only through the host's speakers.
- **Race Day** (`/race-day`): one race, one shared clock, up to 50 seats in the
  MVP target. Every driver boots R4 in their own browser; the server owns the
  grid and standings, not video. The `/lobby` fallback needs ffmpeg.

## Native recomp notes

- Use the release asset, not GitHub's source ZIP: the source has no submodules,
  generated code or OpenBIOS. Early v1.0.0 Windows zips contained only the EXE;
  the current one has `game.toml`, `launcher/` and `bios/`.
- The launcher expects the EXE and `bios/` beside `launcher/`, not inside it.
  Browse to the `.cue`, never a track `.bin` or ZIP.
- Set `settings.toml` `[video] fullscreen = 0` until Borderless is verified:
  exclusive fullscreen (value 2) switched a test desktop to 1024x768. Run only
  one native instance at a time.
- Linux AppImage: OpenBIOS is copied to `~/.local/share/RidgeRacer4Recomp`
  (`$XDG_DATA_HOME` wins, `RIDGERACER4_DATA_DIR` overrides); append
  `--bios /path/to/scph5501.bin` for a custom dump.

## Verification

- `{{target}}/_server/package.json` exists and `npm install` completed.
- `{{target}}/disc/r4.chd` (browser) or the CUE/BIN pair (native) exists.
- `curl -fsS http://localhost:8080/api/health` answers and `/play?game=r4`
  boots to the intro and car select; the `/docs` manual loads.

## Troubleshooting

- **Port 8080 already in use:** stop the old cabinet (`_server\stop.bat`) or
  set `RR4_PORT`; the launchers, firewall and stop scripts assume 8080.
- **Game asks for a disc / black page:** the deck loads `disc/r4.chd`. A
  renamed CHD, a CUE without its BIN or a missing track will not boot.
- **No BIOS:** expected to work. The deck uses OpenBIOS or HLE; if a retail
  dump is present but ignored, pass `?bios=/bios/scph5501.bin`.
- **Guest has no audio:** expected. The stream is video-only; use the host's
  speakers or play local 2P on one machine.
- **Native recomp shows a zoomed desktop:** `fullscreen = 0`; Borderless is
  unverified.
- **Native recomp says OpenBIOS is missing:** the old Windows zip lacked it.
  Re-download the repaired release or add `bios/openbios.bin` from the
  submodule.
- **2P race does not start:** both seats must press READY. Rage/Ridge use the
  shared-car or ghost path, not the R4 VS Battle path.

## License

Ridge Racer Type 4 belongs to Namco; the cabinet repo contains no game data and
the user supplies the disc. OpenBIOS is freely licensed and bundled. The native
recomp is an unofficial fan project and bundles OpenBIOS, not the game.
