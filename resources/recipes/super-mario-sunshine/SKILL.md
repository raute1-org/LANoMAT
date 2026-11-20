---
name: super-mario-sunshine
description: "Install Super Mario Sunshine with Dolphin on Windows or Linux, import the user's GameCube disc image, configure a GameCube controller and optional widescreen, and verify it boots. Use when the user says install Super Mario Sunshine or SMS for the LAN."
---

# Super Mario Sunshine

Super Mario Sunshine is the GameCube platformer with FLUDD and Delfino Plaza.
The LAN baseline is Dolphin with the user's own disc image and a GameCube pad
mapping.

## When to use

- "install Super Mario Sunshine for me"
- "set up SMS on Dolphin"
- "run Mario Sunshine on this PC"
- "Mario Sunshine widescreen / 60 fps"

Not for Super Mario Galaxy (Wii) or the native SMS Launcher build, which is a
separate optional path.

## What you need

- A Super Mario Sunshine USA disc image (`*.iso`, or a lossless `*.rvz`).
- Dolphin (current stable or beta), portable if possible.
- A GameCube controller, a Wii U adapter, or an Xbox/PlayStation pad.
- About 1.5 GB of disk, plus the disc image.

## Steps

1. **Install Dolphin.** Extract the portable build to `{{target}}`, or install
   the Linux package/AppImage. Launch it once so it creates its user folder.
2. **Import the disc.** Copy the ISO to `{{target}}/Games`, then
   Config → Paths → Add that folder. Confirm Dolphin shows the correct game ID
   and region.
3. **Graphics.** Set internal resolution to 3x–4x native, enable VSync, keep
   4:3 by default. For widescreen, enable the AR/Gecko code and check the HUD.
4. **Controller.** Map a pad to GameCube Port 1. Analog trigger pressure
   matters: it controls FLUDD's spray. Save the profile as "LAN pad".
5. **Optional native build.** If the user has the LAN `SMS-Launcher` build,
   point it at the same ISO and use its own saves. Do not mix Dolphin and
   launcher memory cards.
6. **Verify.** Boot to Delfino Plaza, spray water, pause, save, and reload the
   save.

## Verification

- Dolphin lists Super Mario Sunshine with the expected region.
- The intro and Delfino Plaza run without graphical errors.
- FLUDD sprays with analog trigger pressure.
- The memory card save survives a restart.

## Troubleshooting

- **Game list is empty**: the folder was not added in Config → Paths, or the
  file is still inside a zip/7z archive.
- **Wrong region or black screen**: verify the disc image; a trimmed or
  scrubbed ISO can fail.
- **Widescreen stretches the HUD**: disable the code for cutscenes or use the
  widescreen Gecko code rather than the aspect-ratio override.
- **Triggers are on/off only**: the pad is mapped as a digital button; bind the
  analog trigger axis to the GameCube L/R analog input.
- **60 fps cheat breaks physics**: expected; disable it unless the user
  explicitly wants it.

## Status

Draft recipe, needs verification on the LAN hardware. The native
`SMS-Launcher` build in the LAN collection is an alternative, not verified
here.

## License

Super Mario Sunshine belongs to Nintendo. The disc image is user-supplied;
Dolphin ships no game data.
