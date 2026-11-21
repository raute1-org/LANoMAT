---
name: hookshot-standalone
description: 'Install and run the prebuilt Hookshot standalone package for the Ocarina of Time Switch 2 remake: unpack hookshot.exe with the built game and mods, configure graphics and controller, verify the version and save, and skip the dump, builder and 10-45 minute compile. Use when the user says Hookshot standalone, Ocarina of Time Switch 2 all files, or wants the ready package instead of building.'
---

# Hookshot Standalone

A ready-to-run Windows package for **Hookshot**, the native recompilation of
*The Legend of Zelda: Ocarina of Time* (Nintendo Switch 2). The package already
contains `hookshot.exe`, the built `game` folder and (usually) `mods`. No game
dump, no Hookshot Builder, no compiler and no 10-45 minute build.

Status: **draft recipe, researched online on 2026-11-21**. It needs
verification on the LAN hardware: unpacking, the executable layout, the
version string, the first-run shader wait, the controller and a save on a
fresh venue PC.

## When to use

- "install Hookshot standalone"
- "Ocarina of Time Switch 2 all files"
- "give me the ready Hookshot package, no build"
- "run the LAN Hookshot copy"

Use the **`hookshot`** recipe instead when the player has the supported game
dump (program `0400486024DEC000`, update `v131072`, build `e0f3ccfa`) and
wants to build locally with Hookshot Builder.

## What is in the package

- `hookshot.exe` — the built launcher (not the 124 KB official builder ZIP).
- `game/` — the translated game code and assets, about 4-10 GB unpacked.
- `mods/` — optional RomFS mods, each in `mods/<name>/romfs/...`.
- A package that is only a few megabytes is the builder ZIP. A package with
  `hookshot.exe` but an empty `game/` is incomplete.

The standalone package is user-supplied and carries the built game. Do not
mix it with a different Hookshot version or a second `game` folder.

## Requirements

- Windows 10/11 x64.
- Vulkan 1.3 with mesh shaders (the game refuses to start on Vulkan 1.2).
- 16 GB RAM installed; the running game uses close to 10 GB.
- About 10 GB free after unpacking, plus space for saves and shader cache.
- No `.nup`, no Visual Studio, no .NET/Python/Emscripten/LLVM tool downloads.

## Steps

1. **Unpack into `{{target}}`.** Extract `hookshot-standalone*.zip` (for
   example `C:\Games\Hookshot`). The ZIP has one top-level folder; move its
   contents up so `hookshot.exe`, `game/` and optional `mods/` sit directly in
   `{{target}}`. Do not unpack a second package over an existing one.
2. **Check the layout.** `hookshot.exe` sits next to `game/`, not inside it.
   Record the Hookshot version from the package name or `hookshot_log.txt`.
   A 2.x package needs its own matching `game`; do not mix 2.x and 3.x.
3. **First run.** Start `hookshot.exe`. The first launch prepares shaders and
   can stay black for several minutes; later launches use the cache. Set the
   resolution and fullscreen mode and wait for the title screen.
4. **Controls and save.** Map a gamepad or keyboard, play to the first save,
   restart and confirm the save loads.
5. **Mods (optional).** Put RomFS mods in `mods/<name>/romfs/...` beside
   `hookshot.exe`, or set `HOS_MODS` to another folder. A leading `-` or `_`
   disables a folder. ExeFS patches need a rebuild and belong to the builder
   recipe, not this package.
6. **Check the log.** `hookshot_log.txt` names the build and any mod that
   failed. It is the first thing to read when the intro stays black.

## Starting the game

- Default: `{{target}}/hookshot.exe`.
- If the package keeps the launcher under `bin/`, use
  `{{target}}/bin/hookshot.exe` and keep `game/` beside the executable; the
  source build supports `--user "D:\Hookshot data"` for a separate save folder.

The LAN shortcut can point at the executable directly; there is no launcher
argument, no server and no account.

## Verification

- `hookshot.exe` and `game/` exist directly in `{{target}}`.
- The package is several GB, not the ~124 KB builder ZIP.
- The title screen and intro appear; the first-run shader wait finishes.
- A save survives a restart, the controller works and `hookshot_log.txt`
  names the expected build.
- Still to verify on the LAN hardware: unpack on a fresh Windows PC, the exact
  package name and executable layout, the reported version, the shader wait,
  the save path and the packaged mods.

## Troubleshooting

- **"Unsupported version" / version error**: the `game/` folder belongs to a
  different Hookshot build; replace it with the matching package.
- **Black screen**: wait for the shader cache, then check the Vulkan driver;
  Vulkan 1.3 with mesh shaders is required.
- **Only a black window or no exe**: the ZIP was the builder, not the
  standalone package.
- **Antivirus quarantine**: the executable is unsigned; restore it or exclude
  the install folder, then start again.
- **Mods do not load**: check the `mods/<name>/romfs` layout and the leading
  `_`/`-` switch; ExeFS patches need a rebuild.

## Sources

- Hookshot project and downloads: https://hookshot.pro/download/
- Windows install path (builder, version and shader wait):
  https://hookshot.pro/install/windows/
- Repository and build/launch command:
  https://git.notrexed.dev/Hookshot/Hookshot
- Hookshot standalone package layout from the LAN collection and the source
  build output (`bin/hookshot.exe`, `game`, `mods`).
