---
name: apocalypse
description: "Install Apocalypse (1998, PS1) with DuckStation, add the user's disc and scph5501 BIOS, configure graphics and a controller, and verify the game boots. Use when the user says install Apocalypse or the Bruce Willis PS1 game for the LAN."
---

# Apocalypse

The 1998 PlayStation shooter with Bruce Willis, running in DuckStation. The
LAN collection's native `repocalypse.exe` build is a separate path.

## When to use

- "install Apocalypse for me"
- "set up the Bruce Willis PS1 game"
- "play Apocalypse on DuckStation"
- "Apocalypse 1998 on the LAN"

Not for the native repocalypse build, which has its own saves and launcher.

## What you need

- An Apocalypse (USA) PS1 disc image, CUE/BIN or CHD.
- A PlayStation BIOS, normally `scph5501.bin`. User-supplied.
- DuckStation (current release).
- A gamepad.
- About 700 MB of disk.

## Steps

1. **Install DuckStation.** Extract to `{{target}}` or install the Linux
   build; launch once.
2. **Add the BIOS.** Copy `scph5501.bin` to `{{target}}/bios` and select it in
   Settings → BIOS.
3. **Import the disc.** Put the CUE/BIN or CHD in `{{target}}/Games`, add the
   folder, and confirm the serial.
4. **Graphics.** 4x–6x resolution, PGXP, VSync, native frame limit, 4:3.
5. **Controller.** Map both sticks, fire, jump and weapon switching; test
   lock-on and grenades.
6. **Verify.** Finish the first chapter, save, and reload.

## Verification

- The game list shows the correct serial.
- The PlayStation boot logo appears.
- CD audio plays and the first chapter is playable.
- The memory card save survives a restart.

## Troubleshooting

- **No music**: the BIN is incomplete or the CUE lost its audio tracks.
- **"BIOS not found"**: the file is in the wrong folder or region.
- **Disc read error**: a CUE points at a renamed or missing BIN.
- **Aiming feels wrong**: check the dual-stick mapping; the game's lock-on is
  a button, not automatic.
- **Widescreen stretches backgrounds**: disable the hack; the game is 4:3.

## Status

Draft recipe, needs verification on the LAN hardware. The native repocalypse
build is not covered here.

## License

Apocalypse belongs to Activision/Sony. The disc and BIOS are user-supplied;
DuckStation ships no game data.
