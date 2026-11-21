---
name: f-zero-x-vr
description: "Install and configure the F-Zero X VR PCVR port on Windows with the user's own USA Rev 0 N64 ROM and an OpenXR headset. Use when the user says install F-Zero X VR, F-Zero X in VR, or asks for the VR F-Zero port for the LAN."
---

# F-Zero X VR

F-Zero X (Nintendo 64, 1998) in stereoscopic VR through the community PCVR
port. The game is not a GameCube title; Dolphin VR applies to F-Zero GX, not X.

## When to use

- "install F-Zero X VR for me"
- "F-Zero X in VR", "F-Zero VR", "FZero VR"
- "set up the VR F-Zero port on this PC"
- "run F-Zero X with my headset"

Not for F-Zero GX (GameCube, Dolphin) and not for the Quest 3 standalone build
(the same project, installed from a Windows PC over USB).

## What you need

- A legally dumped **F-Zero X (USA) Rev 0** ROM, 16 MiB, `.z64`, `.n64` or
  `.v64`. SHA-1 `5f658e88ffa9de23cba6986a8fd3d3a90d7b4340`; other regions and
  hacks are refused.
- The PCVR build `FZeroXVR-Public-PCVR-*.zip` (the LAN copy is
  `FZeroXVR-Public-PCVR-20261018-R12.zip`).
- Windows 10/11, a VR headset with tracked controllers, and a working OpenXR
  runtime (SteamVR or the vendor runtime).
- About 2 GB of disk.

## Steps

1. **Start the headset runtime.** Open SteamVR or the headset's OpenXR runtime;
   if Virtual Desktop is the streaming path, connect it first.
2. **Extract and install.** Unzip the PCVR build into `{{target}}` and run
   `Setup.exe` from the extracted folder (`{{target}}`). Do not run it from
   inside the zip. Setup registers an uninstaller and can launch the port.
3. **Import the ROM.** Launch once; the launcher asks for
   `F-Zero X (USA).z64`. The launcher validates the dump and stays on a test
   scene when the file is wrong.
4. **Pick the runtime.** Confirm the active OpenXR runtime matches the headset
   you are using. A desktop picture with no headset image usually means the
   wrong runtime.
5. **Set comfort and controls.** Choose seated or standing, set the refresh
   rate the PC holds (90 Hz is a safe default), test motion steering, and use
   recenter if the cockpit is offset.
6. **Verify with one race.** Start a single race, check stereo depth, head
   tracking, steering, boost and the HUD. Then try one cup.

## Verification

- The launcher accepts the ROM without a checksum warning.
- The headset shows a stereo image, not a flat mirror.
- Head tracking and steering respond; the HUD is readable.
- The frame rate holds on the chosen refresh rate for a full race.

## Troubleshooting

- **"ROM rejected"**: wrong region, revision or a ROM hack. Use USA Rev 0.
- **Flat image in the headset**: the OpenXR runtime is not the one the headset
  uses; set it again and restart the port.
- **Cockpit at the wrong height or facing**: use recenter in the port or the
  runtime.
- **Stutter**: lower render resolution or refresh rate; close desktop capture
  and overlays.
- **No controllers**: pair them before launching, and check the runtime's
  input bindings.

## Notes and honesty

This recipe is a draft: needs verification on the LAN. The release filename
and executable name change between builds; the LAN folder shows the R12 PCVR
zip. Dolphin VR is not an alternative for F-Zero X, and the Quest 3 standalone
build is a separate installation.

## License

F-Zero X belongs to Nintendo. The ROM is user-supplied; the community VR port
ships no game data.
