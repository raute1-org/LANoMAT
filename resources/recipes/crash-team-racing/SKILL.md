---
name: crash-team-racing
description: "Install Crash Team Racing with DuckStation, add the user's PS1 disc and scph5501 BIOS, configure enhancement settings and up to four controllers, and verify the game boots. Use when the user says install CTR or Crash Team Racing for the LAN."
---

# Crash Team Racing

The PlayStation kart racer. This skill sets up DuckStation with the user's own
USA disc and BIOS, plus local split-screen pads.

## When to use

- "install Crash Team Racing for me"
- "set up CTR on DuckStation"
- "play CTR with four controllers"
- "Crash Team Racing on the LAN"

Not for Crash Nitro Kart, Crash Team Racing Nitro-Fueled (the remake), or the
native "High Octane" recomp in the LAN collection.

## What you need

- A Crash Team Racing USA disc image (CUE/BIN or CHD).
- A PlayStation BIOS, normally `scph5501.bin` for NTSC-U. User-supplied.
- DuckStation (current release).
- One pad per player, up to four.
- About 700 MB of disk.

## Steps

1. **Install DuckStation.** Extract the portable release to `{{target}}` or
   install the Linux build; launch once.
2. **Add the BIOS.** Copy `scph5501.bin` to `{{target}}/bios` and select it in
   Settings → BIOS.
3. **Import the disc.** Put the CUE/BIN or CHD in `{{target}}/Games` and add
   the folder to the game list. Check the serial and region.
4. **Graphics.** 4x–6x internal resolution, PGXP geometry correction, VSync,
   native frame limit, 4:3.
5. **Controllers.** Map Ports 1–4; test accelerate, brake, hop/drift and item
   use. Verify the drift boost, not just movement.
6. **Verify.** Finish one Arcade race, save to a memory card, and run a
   two-player split-screen race.

## Verification

- DuckStation lists the game with the USA serial.
- The BIOS is accepted and the PlayStation boot logo appears.
- All mapped ports respond in a split-screen race.
- The memory card save survives a restart.

## Troubleshooting

- **"BIOS not found"**: the file is not in the configured folder or is the
  wrong region. Use the user's own dump.
- **Disc not recognised**: a CUE points at a renamed or missing BIN; keep the
  pair together.
- **Split-screen is squashed**: use 4:3 and disable widescreen hacks.
- **Drift boost feels late**: check frame pacing and the controller mapping;
  PGXP does not change input timing.
- **Save fails**: the memory card is read-only or the folder is not writable.

## Status

Draft recipe, needs verification on the LAN hardware. The native High Octane
build is a separate path and is not covered here.

## License

Crash Team Racing belongs to Sony/Naughty Dog. The disc and BIOS are
user-supplied; DuckStation ships no game data.
