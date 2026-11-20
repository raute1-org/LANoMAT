---
name: bloodborne
description: "Install Bloodborne with shadPS4, add the user's PS4 dump and firmware, enable the emulator's patches, configure Vulkan and a controller, and verify the game boots. Use when the user says install Bloodborne on PC for the LAN."
---

# Bloodborne

The PS4 gothic action RPG, running in shadPS4. The LAN collection also has a
native Windows port; this skill covers the emulator path.

## When to use

- "install Bloodborne for me"
- "set up Bloodborne on shadPS4"
- "play Bloodborne on PC"
- "Bloodborne 60 fps"

Not for the native `bbport-windows` build, which has its own launcher.

## What you need

- A Bloodborne PS4 dump: the folder containing `eboot.bin`, or a PKG. USA
  `CUSA00207` and EU `CUSA00900` are the common dumps.
- PS4 firmware `PS4UPDATE.PUP` from the user's own console.
- shadPS4 (current release).
- A Vulkan-capable GPU, a gamepad, and about 50 GB of disk.

## Steps

1. **Install shadPS4.** Extract the release to `{{target}}` or install the
   Linux build; launch once.
2. **Install firmware.** Tools → Install Firmware, select `PS4UPDATE.PUP`,
   and wait for the modules.
3. **Add the game.** Point shadPS4 at the dump folder with `eboot.bin`, or
   install the PKG. Confirm the serial.
4. **Boot at defaults.** Start the game before enabling patches; if it does
   not boot, fix the dump/firmware first.
5. **Patches and graphics.** Vulkan, 1080p–1440p, then enable the 60 fps
   patch from the patch list. Test a lantern and a boss.
6. **Controller.** Map both sticks, triggers, face buttons and touchpad;
   test the parry and transform attacks.
7. **Verify.** Create a hunter, reach the first lantern, die once, respawn and
   save.

## Verification

- The game appears with the expected serial.
- Firmware is installed; no module error on boot.
- The first area renders and the lantern works.
- The save survives a restart; patches are recorded.

## Troubleshooting

- **Missing firmware/modules**: install the PUP; a dump alone is not enough.
- **Black screen at boot**: try the default settings, then a different shadPS4
  build; note which build works.
- **Constant shader stutter**: first-visit compilation; replay the area.
- **60 fps patch breaks physics or cutscenes**: disable it or cap at 30.
- **Controller latency**: use a wired pad and disable overlays.

## Status

Draft recipe, needs verification on the LAN hardware. shadPS4 changes fast;
record the exact build and patch versions. The native port is separate.

## License

Bloodborne belongs to FromSoftware/Sony. The game and firmware are
user-supplied; shadPS4 ships no game data.
