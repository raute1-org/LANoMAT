---
name: uncharted
description: "Install Uncharted: Drake's Fortune with RPCS3, install PS3 firmware, add the user's PS3 disc, configure Vulkan and a controller, and verify the game boots. Use when the user says install Uncharted or Uncharted 1 for the LAN."
---

# Uncharted: Drake's Fortune

The first Uncharted, running in RPCS3. This skill uses the PS3 game; the PS4
Nathan Drake Collection in the LAN collection is a different platform build.

## When to use

- "install Uncharted for me"
- "set up Uncharted 1 on RPCS3"
- "play Drake's Fortune on the LAN"
- "Nathan Drake Collection on PC"

Not for Among Thieves, Drake's Deception, or A Thief's End.

## What you need

- An Uncharted: Drake's Fortune PS3 dump: the folder with `PS3_GAME`, or an
  ISO.
- PS3 firmware `PS3UPDAT.PUP`.
- RPCS3 (current release).
- A gamepad.
- About 25 GB of disk.

## Steps

1. **Install RPCS3.** Extract to `{{target}}` or install the Linux build;
   launch once.
2. **Install firmware.** File → Install Firmware, or drag the PUP onto the
   window; wait for completion.
3. **Add the game.** Point RPCS3 at the folder containing `PS3_GAME` or the
   ISO; confirm the serial.
4. **Graphics.** Vulkan, SPU LLVM, 150–200% scale, VSync, recommended patches.
5. **Controller.** Map sticks, face buttons, triggers and touchpad; test
   cover, blind fire and grenades.
6. **Verify.** Play the jungle intro and the first firefight, then reload a
   checkpoint.

## Verification

- The game appears with the PS3 serial, not the PS4 collection.
- Firmware is installed; no prompt appears on boot.
- Cutscene audio and the first firefight work.
- A checkpoint save survives a restart.

## Troubleshooting

- **PS4 Collection does not load**: expected. RPCS3 cannot run the PS4
  package; use the PS3 disc or shadPS4 (unverified).
- **Firmware missing**: install the PUP and restart RPCS3.
- **Game folder not detected**: point at the directory containing
  `PS3_GAME`, not `USRDIR`.
- **Stutter in new areas**: shader compilation; replay the area once.
- **Audio crackle in cutscenes**: try the recommended SPU settings and the
  game's patch list.

## Status

Draft recipe, needs verification on the LAN hardware. The PS4 Collection and
the native uncharted-windows port are separate paths.

## License

Uncharted belongs to Sony/Naughty Dog. The game and firmware are user-supplied;
RPCS3 ships no game data.
