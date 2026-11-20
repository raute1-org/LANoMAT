---
title: Install Ridge Racer Type 4 (browser cabinet or native recomp)
description: The Ridge Racer Type 4 LAN setup — the self-hosted browser cabinet (disc, OpenBIOS, soundtrack, 2P lounge, Race Day) and the native Windows/Linux recompilation, with controllers and troubleshooting.
sidebar_icon: car
---

Ridge Racer Type 4 has two LAN paths: the **browser cabinet** (a Node process serving the
disc + EmulatorJS, no install on the player PC) and the **native recompilation** (a Windows
zip or Linux AppImage). The recipe is `ridge-racer-type-4`.

## What you need

| Thing | Where | Required? |
|---|---|---|
| `R4 - Ridge Racer Type 4 (USA).bin/.cue` or `.chd` | LAN Files | yes |
| `scph5501.bin` (PSX BIOS) | LAN Files | no — the cabinet ships OpenBIOS; add a retail dump if a game refuses |
| The cabinet (`RidgeRacer4Recomp/_server`, Node 20+) | LAN server or your PC | for the browser path |
| The native recomp (`Ridge_Racer_4_Recompiled.exe` / `linux-x86_64.AppImage`) | LAN Files | for the native path |
| A gamepad | optional (keyboard/touch works) | no |

## Browser cabinet (recommended for the LAN)

1. On the machine that will host it (the LAN server or a spare PC), get the cabinet from
   the recipe's LAN folder or `git clone https://github.com/reneil1337/riiiiiiiiiidge-raaaaaaaaaaaaceer`.
2. Put the disc and BIOS where `_server/config.json` expects them (the repo README and
   `_server/README.md` name the folders).
3. `cd _server && npm install && .\start-test.bat` (Windows) or `npm start`.
4. Open `http://<host>:8080/` — the ENTER gate, then the cabinet. `/play?game=r4` is the disc
   deck; `/multiplayer` is the 2P lounge; `/race-day` is the 50-driver grid.
5. On the player PC: open the same URL, click ENTER once (the click is the audio gesture),
   pick **Ridge Racer Type 4**, and drive.

Ports: `8080` (HTTP, `RR4_PORT`), `3010` (netplay relay). The soundtrack player follows the
selected disc and keeps playing while you browse.

## Native recompilation

**Windows:** unzip `Ridge_Racer_4_Recomp-v1.0.0-windows.zip` to a stable folder
(`C:\Games\RidgeRacer4`). The layout is flat: `Ridge_Racer_4_Recompiled.exe`, `game.toml`,
`launcher/`, `bios/openbios.bin`. Start `Ridge_Racer_4_Recompiled.exe`; the first launch
writes `RidgeRacer4Recomp/settings.toml` — set `[video] fullscreen = 0` there if the window
misbehaves.

**Linux:** use `Ridge_Racer_4_Recomp-v1.0.0-linux-x86_64.AppImage` (bundled OpenBIOS), run
with the disc argument as documented in `NATIVE-RECOMP-SETUP.md`.

Controllers: Xbox/XInput and keyboard work natively; the browser uses the Gamepad API,
keyboard and touch.

## 2P and Race Day

- **2P lounge** (`/multiplayer`): room code, DISC/DRIVE/TURN, teams, READY. R4 has real
  split-screen VS; Rage/Ridge share a car with turn-based ghost.
- **Race Day** (`/race-day`): one 50-driver mass start on one shared clock, LFG queue, live
  standings, results. The guest audio is absent in the current cabinet; the soundtrack
  player is the audio.

## Verify

- The cabinet's `/api/health` answers, `/play?game=r4` boots the disc.
- The native exe/AppImage reaches the title screen with the disc image.
- A second player can join the room code; the race starts only when both seats are READY.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Cabinet shows "no disc" | The `.bin/.cue` path in `_server/config.json` is wrong; keep the CUE and BIN together. |
| Black screen in the browser | Use a Chromium/Firefox with WebGL; the EmulatorJS core needs it. |
| No audio | Click ENTER first (autoplay policy); check the soundtrack player is not muted. |
| Native recomp window is tiny | Set `fullscreen = 0` in the generated `settings.toml`, or use the launcher. |
| Controller not detected | Browser: press a button on the pad before starting; native: use XInput. |
| 2P netplay fails | Open TCP 3010 on the host; run `verify-mp.mjs` from `_server`. |

## Checklist

- [ ] Disc image matched on the LAN (`/games/ridge-racer-type-4` shows LAN)
- [ ] BIOS present or OpenBIOS accepted
- [ ] Cabinet reachable at `http://<host>:8080/`
- [ ] ENTER clicked once, soundtrack audible
- [ ] 2P/Race Day tested with a second player
- [ ] Native path installed (optional) with the right launcher
