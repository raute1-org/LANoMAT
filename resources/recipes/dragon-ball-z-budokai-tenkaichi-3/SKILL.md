---
name: dragon-ball-z-budokai-tenkaichi-3
description: "Install Dragon Ball Z: Budokai Tenkaichi 3 with PCSX2, add the user's PS2 disc and BIOS, configure two controllers and graphics, and verify a local versus match. Use when the user says install Budokai Tenkaichi 3, BT3 or Tenkaichi 3 for the LAN."
---

# Dragon Ball Z: Budokai Tenkaichi 3

The PS2 arena fighter. This skill sets up PCSX2 with the user's own disc and
BIOS, and gets two pads working for local versus.

## When to use

- "install Budokai Tenkaichi 3 for me"
- "set up BT3 on PCSX2"
- "play Tenkaichi 3 with two controllers"
- "DBZ BT3 on the LAN"

Not for the Wii version, Tenkaichi 2, or the native Tenkaichi3Decomp build in
the LAN collection.

## What you need

- A Budokai Tenkaichi 3 PS2 USA disc image (ISO or CHD).
- A PlayStation 2 BIOS dump from the user's own console.
- PCSX2 (current stable).
- Two gamepads for versus.
- About 4 GB of disk.

## Steps

1. **Install PCSX2.** Extract to `{{target}}` or install the Linux build; run
   the wizard.
2. **Add the BIOS.** Copy the dump to `{{target}}/bios`, select it in
   Settings → BIOS, and match the region.
3. **Import the disc.** Put the ISO/CHD in `{{target}}/Games`, add the folder,
   and confirm the serial.
4. **Graphics.** Vulkan, 3x–4x, default frame limit. Lower it if beam clashes
   drop frames.
5. **Optional 60 fps patch.** Only for the exact revision, and only after
   testing input timing.
6. **Controllers.** Map Ports 1 and 2; test melee, ki, guard and
   transformation in a two-player match.
7. **Verify.** Finish a versus match and save.

## Verification

- The game list shows Budokai Tenkaichi 3 with the USA serial.
- The BIOS boots and the memory card works.
- Both pads control their own fighter.
- The save survives a restart.

## Troubleshooting

- **Wrong game imported**: Tenkaichi 2 and the Wii release are different
  discs; check the serial.
- **No BIOS**: PCSX2 will not boot; add the user's dump.
- **Slowdown during ultimates**: lower resolution, disable expensive
  upscaling, and check the GPU driver.
- **60 fps patch desyncs inputs**: disable it; the game's timing is tied to
  30 fps.
- **Second pad does nothing**: map Port 2, not a second device on Port 1.

## Status

Draft recipe, needs verification on the LAN hardware. The native decomp build
is a separate path.

## License

Budokai Tenkaichi 3 belongs to Bandai Namco. The disc and BIOS are
user-supplied; PCSX2 ships no game data.
