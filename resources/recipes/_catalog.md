# Recipe catalog

All recipes under `resources/recipes/`. Validate with
`node resources/recipes/schema/validate.mjs`. `published` means verified
end-to-end; `draft` means authored but not yet checked on the LAN hardware.

## Published

| Slug                           | Title                         | Platforms      | Status    | One line                                                                                          |
| ------------------------------ | ----------------------------- | -------------- | --------- | ------------------------------------------------------------------------------------------------- |
| `unreal-tournament-2004`       | Unreal Tournament 2004        | windows, linux | published | UT2004 DVD install + OldUnreal 3374 64-bit patch, optional UT2004VR and VRM avatars.              |
| `need-for-speed-underground-2` | Need for Speed: Underground 2 | windows, linux | published | Portable 1.2 tree + NFS-Online ASI client (net_u2.asi, net_u2.ini, dinput8.dll); Wine on Linux.   |
| `ridge-racer-type-4`           | Ridge Racer Type 4            | windows, linux | published | Self-hosted browser cabinet (EmulatorJS) with 2P netplay, optional native recomp (Windows/Linux). |

## Draft

| Slug                                   | Title                                   | Platforms             | Status    | One line                                                                                                         |
| -------------------------------------- | --------------------------------------- | --------------------- | --------- | ---------------------------------------------------------------------------------------------------------------- |
| `apocalypse`                           | Apocalypse                              | windows, linux        | draft     | 1998 PS1 shooter in DuckStation; native `repocalypse` build exists separately.                                   |
| `azeroth-survivors`                    | Azeroth Survivors                       | windows               | draft     | Warcraft III custom survival map; Reforged 3.0 is online-only, legacy 1.29 for LAN.                              |
| `bloodborne`                           | Bloodborne                              | windows, linux        | draft     | PS4 dump in shadPS4 with optional PS4 system modules; native PC port exists separately.                          |
| `counter-strike-1-6`                   | Counter-Strike 1.6                      | windows, linux        | draft     | Half-Life base plus cstrike archive (or Steam app 10), `steam://connect` server join, HLDS and tournament notes. |
| `crash-bandicoot`                      | Crash Bandicoot                         | windows, linux        | draft     | Original PS1 platformer in DuckStation, first game only.                                                         |
| `crash-team-racing`                    | Crash Team Racing                       | windows, linux        | draft     | PS1 kart racer in DuckStation with up to four local pads.                                                        |
| `dragon-ball-z-budokai-tenkaichi-3`    | Dragon Ball Z: Budokai Tenkaichi 3      | windows, linux        | draft     | PS2 arena fighter in PCSX2 with local two-player versus.                                                         |
| `f-zero-x-vr`                          | F-Zero X VR                             | windows               | draft     | N64 F-Zero X through the community PCVR port, USA Rev 0 ROM.                                                     |
| `god-of-war-iii`                       | God of War III                          | windows, linux        | draft     | PS3 God of War III (BCUS98111/BCES00510) in RPCS3; the PS4 Remastered build is a different, unsupported title.   |
| `hookshot`                             | Hookshot                                | windows, linux        | draft     | Native Ocarina of Time (Switch 2) recompilation from the exact supported dump.                                   |
| `hookshot-standalone`                  | Hookshot Standalone                     | windows               | draft     | Prebuilt Hookshot package with hookshot.exe, game and mods; no dump, builder or compile.                         |
| `minecraft`                            | Minecraft: Java Edition                 | windows, linux, macos | draft     | Portable Prism Launcher 11.1.1 + Minecraft 26.3/Fabric 0.19.5, Microsoft sign-in, LAN server via multiplayer.    |
| `mm3d-pc`                              | Majora's Mask 3D PC                     | windows, linux        | draft     | Native MM3D port (TriAevum) built from the user's decrypted USA v1.1 ROM.                                        |
| `need-for-speed-underground-2-browser` | Need for Speed: Underground 2 (Browser) | windows, linux, macos | published | Zero-install Underground 2 Revived at nfsu2.xyz, WebGPU in Chrome/Brave, one public server, browser saves.       |
| `pokemon-snap`                         | Pokémon Snap                            | windows, linux, macos | draft     | N64 Pokémon Snap through the native Snap64Recomp port, USA ROM.                                                  |
| `shadow-of-the-colossus`               | Shadow of the Colossus                  | windows, linux        | draft     | PS2 original in PCSX2; the LAN collection's PS4 remaster is a different build.                                   |
| `super-mario-galaxy`                   | Super Mario Galaxy                      | windows, linux        | draft     | Wii platformer in Dolphin with emulated Wii Remote, Nunchuk and pointer.                                         |
| `super-mario-sunshine`                 | Super Mario Sunshine                    | windows, linux        | draft     | GameCube platformer in Dolphin; native SMS Launcher exists separately.                                           |
| `uevr`                                 | UEVR                                    | windows               | draft     | Universal Unreal Engine 4/5 VR injector; per-game profiles, no anti-cheat.                                       |
| `uncharted`                            | Uncharted: Drake's Fortune              | windows, linux        | draft     | PS3 Drake's Fortune in RPCS3; the PS4 Nathan Drake Collection is not an RPCS3 title.                             |
| `zelda-majoras-mask-3d`                | The Legend of Zelda: Majora's Mask 3D   | windows, linux        | draft     | 3DS remake in Azahar with the two-screen layout; MM3D-PC is the native path.                                     |
| `zelda-ocarina-of-time-3d`             | The Legend of Zelda: Ocarina of Time 3D | windows, linux        | draft     | 3DS remake in Azahar with a gamepad-friendly touch layout.                                                       |
| `zelda-wind-waker`                     | The Legend of Zelda: The Wind Waker     | windows, linux        | draft     | GameCube Wind Waker in Dolphin with optional widescreen and HD textures.                                         |

## Aliases

Chat aliases are listed in each `recipe.yaml` and unique across the catalog.
The flagship example: "install ut2004 for me" (also `ut2k4`, `ut 2k4`,
`ut 2004`, `unreal tournament`) resolves to `unreal-tournament-2004`.
