---
name: hookshot
description: "Build and run Hookshot, the native Ocarina of Time Switch 2 recompilation, from the user's own supported .nup/romfs dump on Windows or Linux, and verify it boots. Use when the user says install Hookshot or the native Ocarina of Time Switch 2 port."
---

# Hookshot

A native static recompilation of Ocarina of Time (Switch 2): the game's ARM64
code is translated to C, compiled on the PC, and drawn with Vulkan. No
emulator, no shipped game data.

## When to use

- "install Hookshot for me"
- "set up the native Ocarina of Time Switch 2 port"
- "play OoT Switch 2 on PC"
- "Hookshot recomp"

Not for the N64 original, Ocarina of Time 3D (Azahar), or Ship of Harkinian
(the N64 decompilation).

## What you need

- The exact supported game dump: program `0400486024DEC000`, update
  `v131072`, build `e0f3ccfa`. A `.nup`, an `.nx` packed to `.nup`, or
  `romfs` + `exefs`.
- Hookshot's builder/release from hookshotrecomp.wiki or
  git.notrexed.dev/Hookshot/Hookshot.
- Windows 10/11 or Linux x64, Vulkan, about 10 GB of disk.
- Time for the build.

## Steps

1. **Get Hookshot.** Download from hookshotrecomp.wiki (the GitHub repo moved;
   old links 404) and extract to `{{target}}`.
2. **Place the dump.** Put the `.nup` or `romfs`/`exefs` in the game folder.
   Convert an `.nx` with NXFormat's `nxconvert pack-nup`.
3. **Build.** Use Hookshot Builder or `nx2/make_game.py` from the extracted
   tree. The build lifts the code to C, compiles it with the host layer, and
   writes `hookshot.exe`.
4. **Run.** Start `hookshot.exe`, set resolution/fullscreen, map a pad or
   keyboard, and let the shader cache build.
5. **Mods (optional).** Put RomFS mods in `mods/<name>/romfs/...` beside the
   executable. `.ips`/`.pchtxt` patches apply only after a rebuild.
6. **Verify.** Watch the intro, make a save, and check `hookshot_log.txt`.

## Verification

- The build finishes and `hookshot.exe` exists.
- The game boots without a version error.
- The intro, controller and save work.
- The log names the supported build and any mods loaded.

## Troubleshooting

- **"Unsupported version"**: the dump is not update v131072 / build
  `e0f3ccfa`; other versions do not work.
- **No executable after build**: read the build log; the lift or link failed.
- **Black screen**: Vulkan driver too old, or shader compilation still
  running; retry after the cache is built.
- **Mods do not load**: check the `mods/<name>/romfs` layout; a leading `_` or
  `-` disables a folder.
- **ExeFS patch does nothing**: it is applied at build time; rebuild with the
  patch enabled.
- **Antivirus warning**: the release executable is unsigned; verify the file
  source.

## Status

Draft recipe, needs verification on the LAN hardware. The LAN collection has a
built `hookshot.exe` with `game` and `mods`; the builder steps may differ by
release.

## License

Ocarina of Time belongs to Nintendo. The game dump is user-supplied; Hookshot
is GPL-3.0 and ships no game data.
