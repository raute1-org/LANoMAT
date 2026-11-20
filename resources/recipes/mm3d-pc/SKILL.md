---
name: mm3d-pc
description: "Install the native Majora's Mask 3D PC port (MM3D-PC) on Windows or Linux, run Setup with the user's decrypted USA v1.1 ROM, configure resolution and the single-screen layout, and verify the game boots. Use when the user says install MM3D-PC or the native Majora's Mask 3D port."
---

# Majora's Mask 3D PC (MM3D-PC)

A native port of Majora's Mask 3D on the TriAevum runtime. It builds the game
module on the user's PC from their own decrypted USA v1.1 ROM; no Nintendo code
is downloaded.

## When to use

- "install MM3D-PC for me"
- "set up the native Majora's Mask 3D port"
- "play MM3D at 144 fps / widescreen"
- "MM3D without an emulator"

Not for Azahar, which is the emulator path (`zelda-majoras-mask-3d`).

## What you need

- A decrypted Majora's Mask 3D **USA v1.1** ROM (`.3ds` or `.cci`). Other
  regions/revisions are rejected.
- Windows 10/11 x64 or Linux x64 with glibc 2.38+ (Ubuntu 24.04+, Debian 13,
  Fedora 39+, Arch/SteamOS).
- Vulkan 1.2 and current drivers, 8 GB RAM, about 4 GB disk.
- Internet on the first setup (the compiler download is checksum-pinned).
- 10–40 minutes for the build.

## Steps

1. **Extract.** Unzip `MM3D-PC-Windows.zip` to a short writable path such as
   `C:\Games\MM3D` (not `Program Files`). Linux: extract
   `MM3D-PC-Linux.tar.xz`.
2. **Place the ROM.** Put the USA v1.1 ROM in `{{target}}`.
3. **Run Setup.** Windows: `Setup.bat` (or drag the ROM onto it). Linux:
   `./Setup.sh`. It extracts, translates and compiles the game module; the log
   is `logs/setup.log`.
4. **Play.** `Play.bat` / `Play.sh`. First launch may build shaders; the setup
   optionally tours every scene to prebuild them.
5. **Configure.** F1 opens settings: resolution, frame interpolation, texture
   pack, single-screen layout and controls. F11 toggles fullscreen.
6. **Verify.** Create a save, reach Clock Town, open the notebook, play the
   ocarina, save at an owl statue.

## Updating

Extract the new release into a **new** folder and run Setup with the same ROM.
Then copy `data/savedata`, `data/textures` and `data/config` from the old
folder. Save states can break across releases; in-game saves carry over.

## Verification

- `Setup.bat`/`Setup.sh` finished without a hash error.
- `Play.bat`/`Play.sh` starts the game.
- The single-screen layout reaches the inventory, map and notebook.
- The owl save survives a restart.

## Troubleshooting

- **Setup rejects the ROM**: wrong region or revision; USA v1.1 only.
- **Setup fails to download the compiler**: check internet and the pinned
  SHA-256 in the log.
- **Black window / crash**: update the GPU driver; Vulkan 1.2 is required.
- **Stutter in new areas**: run `BuildShaders.bat` / `./BuildShaders.sh` or let
  the shader tour finish.
- **Texture pack does not load**: put the pack contents in `data/textures`;
  packs with and without `pack.json` both work.
- **Save states fail after an update**: expected; use in-game saves.

## Status

Draft recipe, needs verification on the LAN hardware. Performance varies by
scene and the port is a personal project; record the release used.

## License

Majora's Mask 3D belongs to Nintendo. The ROM is user-supplied; MM3D-PC is
GPL-3.0-or-later and ships no game data.
