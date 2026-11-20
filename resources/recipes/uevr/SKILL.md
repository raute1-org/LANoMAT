---
name: uevr
description: "Install the UEVR injector on Windows, start an OpenXR runtime, inject into a running Unreal Engine 4/5 game, and save a per-game profile. Use when the user says install UEVR, inject VR into an Unreal game, or asks for VR in a UE4/UE5 title on the LAN."
---

# UEVR

UEVR by praydog adds VR to Unreal Engine 4/5 games by injecting into a running
game. It is a tool, not a game; every title needs its own check.

## When to use

- "install UEVR for me"
- "inject VR into this Unreal game"
- "play <UE4/UE5 game> in VR"
- "set up the UEVR injector on the LAN PC"

Not for Unity games, native VR titles, or anti-cheat multiplayer games.

## What you need

- Windows 10/11 and a VR headset with tracked controllers.
- An OpenXR runtime (SteamVR or the vendor runtime).
- The UEVR release zip (nightly builds exist).
- A UE4/UE5 game the user owns, with no anti-cheat.
- About 1 GB for UEVR; the game's own size is separate.

## Steps

1. **Install UEVR.** Extract the release to `{{target}}`; keep
   `UEVRInjector.exe` and the backend DLLs together.
2. **Start OpenXR.** Launch SteamVR or the headset runtime and confirm
   tracking.
3. **Launch the game.** Start the UE4/UE5 game to its main menu, windowed or
   borderless. No anti-cheat.
4. **Inject.** Run `UEVRInjector.exe`, select the process, choose the runtime,
   press Inject. The headset should go stereo; Insert opens the UEVR menu.
5. **Tune.** Set render mode, resolution scale, snap turn and bindings; save a
   per-game profile.
6. **Verify.** Check menus, gameplay, cutscenes and frame rate; confirm the
   profile reloads on the next launch.

## Verification

- The injector lists the game process.
- The headset shows stereo, head-tracked rendering.
- Controllers aim and move as configured.
- The frame rate is playable; the profile is saved.

## Troubleshooting

- **No process in the list**: start the game first, run the injector as the
  same user, and check the game is UE4/UE5.
- **Flat image**: the OpenXR runtime was not active, or injection failed;
  restart the runtime before the game.
- **Game crashes on inject**: try a different UEVR build (stable vs nightly)
  and a windowed mode.
- **HUD or cutscenes broken**: per-game profile settings; not every title is
  fully supported.
- **Anti-cheat warning**: do not inject; EAC/BattlEye titles can ban accounts.

## Status

Draft recipe, needs verification on the LAN hardware. The LAN collection has a
UEVR folder with profiles and a HOTAS helper; record the build and profile used
per game.

## License

UEVR is MIT-licensed. The injected games are user-supplied and remain the
property of their publishers.
