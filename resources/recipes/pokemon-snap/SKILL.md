---
name: pokemon-snap
description: "Install Pokémon Snap with the native Snap64Recomp port, place the user's USA N64 ROM, configure widescreen, frame rate and controls, and verify the first course. Use when the user says install Pokemon Snap or Snap64 for the LAN."
---

# Pokémon Snap

The N64 photo safari, running natively through Snap64Recomp. It is not a
GameCube game, so Dolphin is not the emulator here.

## When to use

- "install Pokemon Snap for me"
- "set up Snap64Recomp"
- "play Pokemon Snap on PC"
- "Pokemon Snap widescreen / mouse aim"

Not for New Pokémon Snap (Switch) or an N64 emulator setup.

## What you need

- A Pokémon Snap USA ROM (`.z64`, `.n64`, `.v64`). SHA-1
  `edc7c49cc568c045fe48be0d18011c30f393cbaf`; other regions are refused.
- The Snap64Recomp release for the OS (Windows portable zip; Linux/macOS
  builds exist).
- A gamepad and/or keyboard and mouse.
- About 1 GB of disk.

## Steps

1. **Install the port.** Unpack the win64 zip, the Linux `linux-x86_64`
   tarball or the `macos-universal` zip into `{{target}}`; it is portable. No
   installer, no account.
2. **Add the ROM.** Put it beside the executable as `pokemonsnap.z64`, or
   start the program and pick it once. The port validates and copies it.
3. **Open Options.** Press Esc (or Select on a pad). There is no launcher:
   widescreen, frame rate, sound and controls are all in the game's own
   Options.
4. **Graphics.** Widescreen, frame-rate interpolation and render scale are
   opt-in. Higher frame rates do not change the 30 Hz photo logic.
5. **Controls.** Map camera, apples, pester ball and shutter; mouse aim and
   gyro are optional.
6. **Verify.** Play the Beach, take a photo, let Oak score it, and check the
   album and `saves/`.

## Verification

- The program starts without asking for the ROM again.
- The Beach course renders with the chosen aspect ratio.
- A photo is scored and appears in the album.
- The save survives a restart.

## Troubleshooting

- **ROM refused**: wrong region or a bad dump; the dialog shows both SHA-1
  values.
- **Black screen / crash at start**: update the GPU driver. Windows needs
  D3D12 with Shader Model 6.3; Linux uses Vulkan.
- **First start is slow**: shader compilation; later starts use `cache/`.
- **SmartScreen warning**: the executable is unsigned; verify the SHA-256 file
  beside the download, then run it.
- **No controller**: SDL must see the pad before launch; remap in Options.

## Status

Draft recipe, needs verification on the LAN hardware. The Linux and macOS
builds are experimental; Android is a beta and not covered here.

## License

Pokémon Snap belongs to Nintendo, Creatures, GAME FREAK and HAL. The ROM is
user-supplied; Snap64Recomp is GPL-3.0 and ships no game data.
