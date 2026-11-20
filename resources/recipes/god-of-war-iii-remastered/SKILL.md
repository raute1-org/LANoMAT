---
name: god-of-war-iii-remastered
description: "Install God of War III with RPCS3, install PS3 firmware, add the user's PS3 disc, configure Vulkan and a controller, and verify the game boots. Use when the user says install God of War 3, GOW3 or God of War III for the LAN."
---

# God of War III Remastered

Kratos' PS3 finale, running in RPCS3. The title says "Remastered", but RPCS3
runs the **PS3** game; the PS4 Remastered release is a different build.

## When to use

- "install God of War 3 for me"
- "set up GOW3 on RPCS3"
- "play God of War III on the LAN"
- "God of War III 60 fps"

Not for the PS4 Remastered build (shadPS4, unverified) or God of War (2018).

## What you need

- A God of War III PS3 disc dump: the folder containing `PS3_GAME`, or an ISO.
- PS3 firmware `PS3UPDAT.PUP` from the user's own console or Sony's official
  update.
- RPCS3 (current release).
- A gamepad.
- About 40 GB of disk.

## Steps

1. **Install RPCS3.** Extract to `{{target}}` or install the Linux build;
   launch once to create the config folders.
2. **Install firmware.** File → Install Firmware, or drag `PS3UPDAT.PUP` onto
   the window. Wait for it to finish; a game cannot boot without it.
3. **Add the game.** Point RPCS3 at the `PS3_GAME` folder or the ISO, confirm
   the serial, and install any game update it offers.
4. **Graphics.** Vulkan, SPU LLVM, 150–200% resolution scale, VSync. Use the
   game patch list for the recommended settings.
5. **Optional 60 fps patch.** Enable only for the exact serial and test the
   cutscenes.
6. **Controller.** Map both sticks, triggers and the touchpad click; save the
   profile and test a combat encounter.
7. **Verify.** Play the intro to the first boss.

## Verification

- RPCS3 shows the game with the PS3 serial.
- Firmware is installed and the game boots without a firmware prompt.
- Combat, audio and cutscenes run; the shader cache warms up.
- The controller mapping covers the triggers.

## Troubleshooting

- **"Firmware missing"**: install `PS3UPDAT.PUP` and restart RPCS3.
- **Game does not appear**: point at the folder containing `PS3_GAME`, not the
  inner `USRDIR`.
- **Wrong version**: the PS4 Remastered folder will never load in RPCS3.
- **Stutter in new areas**: shader compilation; replay the area once.
- **60 fps patch breaks cutscenes**: disable it or use the patch's framerate
  notes for the exact serial.

## Status

Draft recipe, needs verification on the LAN hardware. The PS4 Remastered build
is not covered.

## License

God of War III belongs to Sony Santa Monica. The game and firmware are
user-supplied; RPCS3 ships no game data.
