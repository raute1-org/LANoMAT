---
name: zelda-wind-waker
description: "Install The Legend of Zelda: The Wind Waker with Dolphin, import the user's GameCube disc, configure widescreen and an optional HD texture pack, map a controller and verify the game boots. Use when the user says install Wind Waker, WW or TWW for the LAN."
---

# The Legend of Zelda: The Wind Waker

The GameCube Zelda with the Great Sea and cel shading. This skill sets up
Dolphin with the user's own disc, a controller and optional widescreen/HD
textures.

## When to use

- "install Wind Waker for me"
- "set up Zelda Wind Waker on Dolphin"
- "Wind Waker widescreen / HD textures"
- "TWW" or "WW"

Not for the Wii U HD remake or the native BlueWake recomp, which is a separate
optional path.

## What you need

- A The Wind Waker USA disc image (`*.iso` or `*.rvz`).
- Dolphin (current stable/beta; the LAN reference is 2609a).
- A GameCube pad or an Xbox/PlayStation pad.
- Optional: the Hypatia WWHD texture pack from the LAN file host.

## Steps

1. **Install Dolphin.** Extract a portable build to `{{target}}` or install the
   Linux package. Launch once to create its user folder.
2. **Import the disc.** Copy the ISO/RVZ to `{{target}}/Games`, then
   Config → Paths → Add. Confirm the game ID and region.
3. **Graphics.** Set 3x–4x native resolution, enable VSync, keep 4:3 unless
   the user asks for widescreen. Widescreen is a version-matched AR/Gecko code.
4. **Optional HD textures.** Extract the Hypatia pack into
   `Dolphin/Load/Textures/<game-id>/` and enable Load Custom Textures. Check
   VRAM use before enabling a 4K pack.
5. **Controller.** Map Port 1, verify the C-stick camera and analog triggers,
   and save the profile as "LAN pad".
6. **Verify.** Sail out of Outset, open the sea chart and save.

## Verification

- The game list shows the expected disc ID.
- Outset Island and the sea render correctly.
- The camera, shield and targeting respond on the mapped pad.
- The save survives a restart; custom textures load if enabled.

## Troubleshooting

- **Black screen after the intro**: disable "Skip EFB Access from CPU" or the
  widescreen code and retry.
- **HUD stretched**: the AR code, not the aspect-ratio override, is the
  widescreen path; check the version.
- **Custom textures do not load**: the folder must match the disc ID, and the
  option must be enabled in Graphics → Advanced.
- **Stutter when sailing**: disable the HD pack or lower the resolution.
- **Audio crackle**: use a clean dump and the DSP HLE/LTE recompiler settings
  that Dolphin recommends for the build.

## Status

Draft recipe, needs verification on the LAN hardware. The native `BlueWake`
build in the LAN collection is an alternative and is not covered here.

## License

The Wind Waker belongs to Nintendo. The disc image is user-supplied; Dolphin
ships no game data.
