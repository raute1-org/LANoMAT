---
name: zelda-ocarina-of-time-3d
description: "Install Ocarina of Time 3D with Azahar, import the user's decrypted 3DS dump, set up the two-screen layout, a gamepad and optional HD textures, and verify the game boots. Use when the user says install Ocarina of Time 3D or OOT3D for the LAN."
---

# The Legend of Zelda: Ocarina of Time 3D

The 3DS remake of Ocarina of Time. This skill installs Azahar, imports the
user's dump, and makes the two-screen layout playable with a normal gamepad.

## When to use

- "install Ocarina of Time 3D for me"
- "set up OOT3D on Azahar"
- "play the 3DS Ocarina of Time on this PC"
- "Ocarina of Time 3D widescreen / HD textures"

Not for the N64 original, the Switch 2 Hookshot port, or the native TriAevum
build in the LAN collection.

## What you need

- A decrypted Ocarina of Time 3D USA dump (`.3ds`, `.cci`) or a `.cia` plus the
  console's `aes_keys.txt`.
- Azahar (current release), not the discontinued Citra.
- A gamepad with enough buttons for A/B, L/R, the D-pad and the touch menu.
- About 2 GB of disk and a Vulkan/OpenGL-capable GPU.

## Steps

1. **Install Azahar.** Extract the Windows build to `{{target}}` or install the
   Linux AppImage/package. Launch once to create the user folder.
2. **Import the dump.** Copy the `.3ds`/`.cci` to `{{target}}/Games`, add the
   folder in Azahar, and confirm the title ID and region.
3. **System files (only if needed).** Put `aes_keys.txt` in
   `Azahar/sysdata/` for an encrypted CIA. A decrypted dump needs no keys.
4. **Layout.** Stack the two screens or use a large screen plus a touch panel;
   make sure the inventory and map are reachable.
5. **Graphics.** 3x–4x internal resolution, VSync on, 30 fps default. Only use
   a 60 fps cheat that matches the exact revision.
6. **Controller.** Map A/B, L/R, D-pad and the touch screen, then test the
   ocarina and the item menu. Save the profile as "LAN pad".
7. **Verify.** Start a new game, reach Kokiri Forest, save and reload.

## Verification

- Azahar lists the game with the USA title ID.
- Both screens render; the touch inventory responds.
- The ocarina, targeting and shield work on the pad.
- The save survives a restart.

## Troubleshooting

- **Game does not appear**: wrong folder, still zipped, or an unsupported
  format. Add the folder in Azahar, not just the file.
- **"Missing keys" / encrypted CIA**: add `aes_keys.txt` from the user's
  console, or use a decrypted dump.
- **Touch menu unreachable**: change the screen layout; a single small bottom
  screen on a large monitor is easy to lose.
- **Shadows or fonts broken**: update the GPU driver and Azahar; old Citra
  builds are not a substitute.
- **Stutter on first area**: let the shader cache warm up, then recheck.

## Status

Draft recipe, needs verification on the LAN hardware. The native TriAevum build
and texture packs are not covered yet.

## License

Ocarina of Time 3D belongs to Nintendo. The 3DS dump is user-supplied; Azahar
ships no game data or keys.
