---
name: azeroth-survivors
description: 'Install and launch the Warcraft III custom map Azeroth Survivors on Windows: verify Warcraft III, copy the .w3x into the Maps folder, start a custom game or LAN lobby, and verify the map loads. Use when the user says install Azeroth Survivors or the WC3 survival map for the LAN.'
---

# Azeroth Survivors

A Warcraft III custom map, not a standalone game. This skill verifies a legal
Warcraft III install, puts the `.w3x` where the game can see it, and starts a
custom game or LAN lobby.

## When to use

- "install Azeroth Survivors for me"
- "set up the WC3 survival map"
- "host Azeroth Survivors on the LAN"
- "Azeroth Survivors map for Warcraft 3"

Not for a standalone Warcraft III campaign or a different custom map.

## What you need

- A legal Warcraft III: Reforged install, patched to the current version.
- The Azeroth Survivors `.w3x` map from the LAN file host.
- A Battle.net account for the first launch and patching.
- About 30 GB for Warcraft III; the map is a few MB.
- Optional: the LAN `AzerothSurvivors.exe` wrapper folder, if that is the copy
  the user has.

## Steps

1. **Install Warcraft III.** Use Battle.net, patch to current, and launch once
   so the Documents folders exist.
2. **Place the map.** Copy the `.w3x` to
   `Documents\Warcraft III\Maps\`. Create the folder if needed.
3. **Start the game.** Single Player → Custom Game for solo, or Multiplayer →
   Local Area Network to host for the LAN.
4. **Pick the map.** Select Azeroth Survivors, set the player slots, and start.
   Everyone in a LAN lobby needs the same map version.
5. **Wrapper path (optional).** If the user has the `AzerothSurvivors.exe` +
   `Data/patch.MPQ` folder, run that launcher instead of the Maps folder, and
   record its version.
6. **Verify.** Load the map, pick a hero, and survive the first wave.

## Verification

- Warcraft III is at the current patch and starts.
- The map appears in the Custom Game list.
- The lobby shows the expected player slots.
- The first wave runs without a missing-asset error.

## Troubleshooting

- **Map not listed**: wrong folder, wrong extension, or the game is still
  downloading a patch. Put it in `Documents\Warcraft III\Maps\`.
- **Version mismatch in the lobby**: all players must use the same `.w3x`
  file; compare the file size or checksum.
- **Wrapper starts the wrong game**: the `.exe` is a prebuilt launcher, not a
  map; check its `Data` folder and version.
- **Missing assets**: the map may require a specific WC3 patch; update the
  game.
- **LAN lobby empty**: all players must be on the same network and the same
  game version.

## Status

Draft recipe, needs verification on the LAN hardware. The player count and the
wrapper build are not confirmed.

## License

Warcraft III and Azeroth Survivors belong to Blizzard and the map's author.
The game and map are user-supplied.
