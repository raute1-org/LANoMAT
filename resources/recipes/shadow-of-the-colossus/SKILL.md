---
name: shadow-of-the-colossus
description: "Install Shadow of the Colossus with PCSX2, add the user's PS2 disc and BIOS, configure graphics and a controller, and verify the game boots. Use when the user says install Shadow of the Colossus or SotC for the LAN."
---

# Shadow of the Colossus

The PS2 colossus-climbing adventure. This skill sets up PCSX2 with the user's
own PS2 disc and BIOS. The PS4 remaster in the LAN collection is a different
build and is not covered.

## When to use

- "install Shadow of the Colossus for me"
- "set up SotC on PCSX2"
- "play the PS2 Shadow of the Colossus"
- "Shadow of the Colossus 60 fps"

Not for the PS4 remaster (`CUSA08809`) or the native SotC-PC port.

## What you need

- A Shadow of the Colossus PS2 USA disc image (ISO or CHD).
- A PlayStation 2 BIOS dump from the user's own console.
- PCSX2 (current stable).
- A gamepad.
- About 4 GB of disk.

## Steps

1. **Install PCSX2.** Extract the release to `{{target}}` or install the Linux
   build; run the first-start wizard.
2. **Add the BIOS.** Put the dump in `{{target}}/bios` and select it in
   Settings → BIOS. Match the region to the disc.
3. **Import the disc.** Put the ISO/CHD in `{{target}}/Games`, add the folder,
   and confirm the serial.
4. **Graphics.** Vulkan, 3x–4x resolution, default frame limit. Drop to 2x if
   the GPU cannot hold 30 fps.
5. **Controller.** Map movement, camera, grab, sword and horse; map pressure
   to the analog triggers.
6. **Verify.** Ride to the first colossus, climb it, save and reload.

## Verification

- PCSX2 lists the game with the USA serial.
- The BIOS boots and the memory card is writable.
- The first colossus renders and the grip works.
- The save survives a restart.

## Troubleshooting

- **No BIOS / boot loop**: the BIOS is missing or from the wrong region.
- **Slowdown in open areas**: lower the resolution or use the recommended
  PCSX2 game fixes; SotC is fill-rate bound.
- **60 fps patch breaks physics**: disable it; the game is designed for 30.
- **Blurry image**: raise the internal resolution, not the texture filtering
  alone.
- **Controller pressure missing**: map the analog trigger axes, not digital
  buttons.

## Status

Draft recipe, needs verification on the LAN hardware. The PS4 remaster and the
native port are separate paths.

## License

Shadow of the Colossus belongs to Sony. The disc and BIOS are user-supplied;
PCSX2 ships no game data.
