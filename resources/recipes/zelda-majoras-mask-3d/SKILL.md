---
name: zelda-majoras-mask-3d
description: "Install Majora's Mask 3D with Azahar, import the user's decrypted 3DS dump, arrange the two screens, map a gamepad and optional HD textures, and verify the game boots. Use when the user says install Majora's Mask 3D or MM3D (emulator) for the LAN."
---

# The Legend of Zelda: Majora's Mask 3D

The 3DS remake of Majora's Mask. This skill installs Azahar and makes the
two-screen layout usable with a gamepad. The native MM3D-PC port is a separate
recipe (`mm3d-pc`).

## When to use

- "install Majora's Mask 3D for me"
- "set up MM3D on Azahar"
- "play the 3DS Majora's Mask on this PC"
- "Majora's Mask 3D HD textures"

Not for the N64 original or the native `MM3D-PC` port.

## What you need

- A decrypted Majora's Mask 3D USA dump (`.3ds`, `.cci`) or a `.cia` with
  `aes_keys.txt`.
- Azahar (current release).
- A gamepad and a reachable touch-screen layout.
- About 2 GB of disk.

## Steps

1. **Install Azahar.** Unpack the Windows zip into `{{target}}/Azahar` and move
   the single top-level `azahar-windows-<build>/` folder's contents up, so
   `{{target}}/Azahar/azahar.exe` exists. On Linux put `azahar.AppImage` there
   and make it executable; launch once to create the user folder.
2. **Import the dump.** Copy the `.3ds`/`.cci` to `{{target}}/Games`, add that
   folder in Azahar, confirm the USA title ID. A `.cia` goes through the CIA
   installer.
3. **Keys (if needed).** `aes_keys.txt` in `Azahar/sysdata/` only for encrypted
   CIAs; a decrypted dump needs none.
4. **Layout.** Stack the screens or use a large screen plus a touch panel. Make
   the item slots, masks and Bombers' Notebook reachable.
5. **Graphics.** 3x–4x internal resolution, VSync, 30 fps default. Use a
   version-matched 60 fps cheat only on request.
6. **Controller.** Map A/B, L/R, D-pad and the touch screen; test the ocarina,
   the notebook and the three-day save.
7. **Verify.** Reach Clock Town, open the notebook, save at an owl statue and
   reload.

## Verification

- The game appears with the expected USA title ID.
- Both screens render and the touch inventory works.
- The moon timer, notebook and owl save behave correctly.
- The save survives a restart.

## Troubleshooting

- **Empty game list**: add the folder, not just the file; unpack archives.
- **Encrypted CIA fails**: install the user's `aes_keys.txt`, or switch to a
  decrypted dump.
- **Touch menu unreachable**: change the layout before starting; the notebook
  is required to progress.
- **Timer or physics oddities**: disable 60 fps cheats and frame limits; the
  game logic is 30 Hz.
- **Wrong revision for a texture pack**: packs are often tied to USA v1.1;
  check before installing.

## Status

Draft recipe, needs verification on the LAN hardware. The native MM3D-PC port
is covered by its own recipe and is the better path when the user wants 144 fps
and a single-screen layout.

## License

Majora's Mask 3D belongs to Nintendo. The 3DS dump is user-supplied; Azahar
ships no game data or keys.
