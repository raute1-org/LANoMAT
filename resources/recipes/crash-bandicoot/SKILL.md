---
name: crash-bandicoot
description: "Install the original Crash Bandicoot with DuckStation, add the user's PS1 disc and scph5501 BIOS, configure graphics and a controller, and verify the game boots. Use when the user says install Crash Bandicoot or Crash 1 for the LAN."
---

# Crash Bandicoot

The 1996 PlayStation platformer. This skill sets up DuckStation with the
user's own disc and BIOS. It deliberately targets the first game, not the
sequels.

## When to use

- "install Crash Bandicoot for me"
- "set up Crash 1 on DuckStation"
- "play the original Crash Bandicoot"
- "Crash Bandicoot on the LAN"

Not for Crash 2, Crash 3, Crash Team Racing, or the native `CrashBandicoot.exe`
port in the LAN collection.

## What you need

- A Crash Bandicoot (first game) USA disc image, CUE/BIN or CHD.
- A PlayStation BIOS, normally `scph5501.bin`. User-supplied.
- DuckStation (current release).
- A gamepad.
- About 600 MB of disk.

## Steps

1. **Install DuckStation.** Extract the portable release to `{{target}}` or
   install the Linux build; launch once.
2. **Add the BIOS.** Copy `scph5501.bin` to `{{target}}/bios` and select it in
   Settings → BIOS.
3. **Import the disc.** Put the CUE/BIN or CHD in `{{target}}/Games`, add the
   folder, and confirm the serial is the first game.
4. **Graphics.** 4x–6x resolution, PGXP geometry correction, VSync, native
   frame limit, 4:3.
5. **Controller.** Map D-pad/stick, jump, spin and crouch; test a slide-jump
   and a hog ride.
6. **Verify.** Finish N. Sanity Beach, save, and reload the save.

## Verification

- The game list shows the first game's serial, not Crash 2/3.
- The PlayStation boot logo appears (BIOS is working).
- Jump, spin and crouch respond on the pad.
- The memory card save survives a restart.

## Troubleshooting

- **Wrong game imported**: the glob matches all Crash discs; check the title
  and serial, then import only the first.
- **"BIOS not found"**: wrong folder or wrong region; use the user's dump.
- **Disc read error**: a CUE points at a renamed or missing BIN.
- **Jumps feel off**: disable widescreen hacks and frame-limit overrides; the
  game's collision is tied to 4:3 and native timing.
- **No music**: check the CD audio track in the CUE and that the BIN is
  complete.

## Status

Draft recipe, needs verification on the LAN hardware. The native port is a
separate path and is not covered here.

## License

Crash Bandicoot belongs to Sony/Naughty Dog. The disc and BIOS are
user-supplied; DuckStation ships no game data.
