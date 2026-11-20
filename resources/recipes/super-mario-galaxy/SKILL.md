---
name: super-mario-galaxy
description: "Install Super Mario Galaxy with Dolphin, import the user's Wii disc image, configure an emulated Wii Remote plus Nunchuk and pointer control, then verify it boots. Use when the user says install Super Mario Galaxy or SMG for the LAN."
---

# Super Mario Galaxy

The Wii gravity-platformer. This skill sets up Dolphin with the user's own Wii
disc, an emulated Wii Remote + Nunchuk, and pointer control for the Star
Cursor.

## When to use

- "install Super Mario Galaxy for me"
- "set up SMG on Dolphin"
- "play Mario Galaxy with a controller / mouse"
- "Super Mario Galaxy widescreen"

Not for Super Mario Galaxy 2, which has its own disc and save.

## What you need

- A Super Mario Galaxy USA disc image (`*.iso` or lossless `*.rvz`).
- Dolphin (current stable/beta).
- An Xbox/PlayStation pad for the emulated Wii Remote + Nunchuk, or a real Wii
  Remote with Bluetooth/DolphinBar.
- About 1.5 GB of disk.

## Steps

1. **Install Dolphin.** Extract the portable build to `{{target}}` or install
   the Linux build; launch once.
2. **Import the disc.** Copy the ISO/RVZ to `{{target}}/Games`, add the folder
   in Config → Paths, and check the game ID.
3. **Wii Remote.** Controller 1 → Emulated Wii Remote + Nunchuk. Map the
   pointer to the mouse or right stick, A/B to jump/spin, and the Nunchuk stick
   to movement.
4. **Real remote (optional).** Pair over Bluetooth or use a DolphinBar, enable
   Continuous Scanning, set the speaker to Emulated.
5. **Graphics.** 3x–4x native, VSync, Vulkan when stable. Widescreen only with
   a tested code; check cutscenes.
6. **Verify.** Play Gateway Galaxy, collect a star, return to the observatory,
   save and reload.

## Verification

- The game list shows the expected Wii disc ID.
- The Star Cursor follows the pointer.
- Jump, Star Spin and Nunchuk movement work.
- The save survives a restart.

## Troubleshooting

- **Pointer stuck at the centre**: the emulated pointer is not bound, or the
  real remote lost the sensor bar. Rebind the pointer or set the sensor bar
  position.
- **Game crashes on the second layer**: the ISO is scrubbed or truncated; use
  a clean dump or a proper RVZ.
- **Audio crackle**: use the recommended DSP settings for the Dolphin build;
  real Wii Remote audio can be set to Emulated.
- **Widescreen UI offset**: disable the widescreen code or use the 16:9 Gecko
  code rather than the aspect override.
- **Real remote not found**: pair it in the OS first, then enable Continuous
  Scanning in Dolphin.

## Status

Draft recipe, needs verification on the LAN hardware. The native Nebula recomp
in the LAN collection is an alternative and is not covered here.

## License

Super Mario Galaxy belongs to Nintendo. The disc image is user-supplied;
Dolphin ships no game data.
