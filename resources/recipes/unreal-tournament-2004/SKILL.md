---
name: ut2004
description: 'Install Unreal Tournament 2004 on a LAN PC from the UT2004 DVD image or an existing GOG/Steam copy, apply OldUnreal patch 3374 64-bit, then start it flat or optionally install UT2004VR with SteamVR and vrm2ut2004 avatars. Always ask flat or VR before installing. Use when the user says install ut2004, ut2k4, ut 2k4, unreal tournament, or asks to set up UT2004 for the LAN.'
---

# Unreal Tournament 2004

UT2004 is the LAN's baseline arena shooter. This skill puts a working 64-bit
install on the PC from the LAN disc image, applies the required OldUnreal
patch, and offers two optional extras: the UT2004VR mod and VRM avatars.

## When to use

Use this skill when the user says any of:

- "install ut2004 for me"
- "install ut2k4", "install ut 2k4", "install ut 2004"
- "install unreal tournament" (when they mean the 2004 LAN game, not UT99)
- "set up UT2004 for the LAN", "patch UT2004", "make UT2004 run on Windows 11"
- "install the VR mod for UT2004"

Do not use it for Unreal Tournament 1999 (different game and patch line) or for
the dedicated server on the game-server host.

## Ask first: flat or VR

Ask one question **before** installing: "Flat on a monitor, or VR?"

- **Flat** is the default and needs nothing beyond the base game and patch. It
  works on Windows and Linux.
- **VR** is Windows 10/11 only and needs SteamVR plus motion controllers. VR is
  offline bots only; joining an online server drops back to a flat screen.

Do not assume VR from a plugged-in headset, and never install VR on Linux.

## What you need

- The UT2004 DVD 1 image on the LAN file host (`ut2004*.iso`), or an existing
  GOG/Steam install. Disc 2 only adds maps. The user supplies the game files.
  A GOG/Steam folder is a manual target: skip the ISO requirement, set
  `{{target}}` to that install and start at the patch step.
- The OldUnreal 3374 archive for the target OS:
  `OldUnreal-UT2004Patch3374-Windows-*.zip` or
  `OldUnreal-UT2004Patch3374-Linux-*.tar.bz2`. The latest release is
  `3374-rc3` (2026-11-03).
- About 5 GB of disk and a current GPU driver. Patch 3374 is 64-bit only:
  Windows 7+ or 64-bit Linux, and no Wine is needed.
- For VR: Windows 10/11, SteamVR, a headset with motion controllers and the
  `UT2004VR-*.zip` release (v0.1.1 is verified).

On Windows run the `powershell` commands and on Linux the `bash` ones; the
installer filters by the machine's shell.

## Steps

1. **Pick the install folder.** `{{target}}` should be short and writable, for
   example `C:\Games\UT2004` or `~/Games/UT2004`. Avoid `Program Files`.
2. **Copy the base game assets from Disc 1.** Mount the ISO and copy every
   disc folder except `System` into `{{target}}`. OldUnreal's patch brings the
   complete `System` tree, and a retail `System` mixed in keeps the patched
   game from starting (on Linux it fails outright). On Linux, unpack the ISO
   with `bsdtar` and copy the same folders; no Wine. Globs are matched
   literally, so the recipe lists the lowercase and uppercase ISO spellings.
3. **Apply OldUnreal patch 3374.** Extract the Windows zip and copy the whole
   extracted tree over `{{target}}`, merging: it also carries `Help/`,
   `Sounds/`, `SystemARM64/`, `SystemLocalized/`, `Textures/` and `Web/`, not
   just `System/`. On Linux extract the `.tar.bz2` into `{{target}}` and
   `chmod +x System/UT2004`. Check that `System/UT2004.exe` is x86-64
   (Windows), `System/D3D11Drv.dll` exists and `System/Build.ini` reports
   `v3374`.
4. **Dismount the ISO.** The patched client does not ask for the DVD. If it
   still does, the patch landed in the wrong folder.
5. **Install UT2004VR if the user chose VR.** Unzip the release and call
   `Installer/Install.ps1` with `-GameDir`, `-Unattended` and `-NoShortcut`.
   `Install.bat` is the interactive wrapper and ends in `pause`.
   The installer checks the 64-bit build and the `v3374` build date
   (2026-07-18 or later, so rc3 passes) and refuses to run while UT2004 is
   running.
6. **Handle `d3d9.dll` before the VR install.** That file name is the mod's
   SteamVR bridge. If ReShade or dgVoodoo already owns it, move it to
   `System\d3d9.dll.pre-UT2004VR` first; the installer would otherwise stop at
   its unattended "Continue?" prompt (default: no). The uninstaller restores
   the backup and the other tool is off while the mod is installed.
7. **Ask about avatars.** This is a multi-select, not a yes/no question. See
   the next section.
8. **Smoke test.** Start the game, then Instant Action → Deathmatch → DM-Rankin
   with 4 bots. Confirm video, audio and mouse input.

## Starting the game

- **Flat, Windows:** `System\UT2004.exe`.
- **Flat, Linux:** `System/ut2004-bin-amd64` (a symlink to the 64-bit
  `System/UT2004`).
- **VR, Windows:** start SteamVR, then run the `UT2004 VR` shortcut or
  `System\Play-VR.bat`. VR is on only when started from `Play-VR.bat`; a normal
  start stays flat. `Play-VR.bat DM-Antalus` skips the menus.

VR settings live in `System\UT2004VR_Game.ini`, made from `UT2004.ini` on the
first VR start. Delete it to start over. `RenderScale` in
`System\UT2004VR_proxy.ini` (for example `0.7`) is the frame-rate lever.

## Asking about avatars

Avatars are `role: avatar` requirements matched against the LAN file host. The
installer shows every `.vrm` match as a multi-select:

1. List the LAN matches first and recommend them. Prefer LAN copies; do not
   invent download URLs for an avatar.
2. Ask: "Which avatars should I install? Pick any number, or none."
3. Only build with `vrm2ut2004` if the user wants converted characters. The
   repo is private: clone it on the LAN server with an account that can read
   it, follow its README, and feed it the local `.vrm` paths.
4. Copy the generated UT2004 character packages to each client's `System`
   folder.
5. Avatars are optional. Never block the base install or UT2004VR on them.

Live example: the operator's UT2004VR/VRM deployment, "Raute1 | VRM-UT2004", at
https://ut.raute1.org/ (HTTP/2 200 on 2026-11-21) runs this path in production.

## Verification

- `{{target}}/System/Build.ini` reports `v3374`, the main menu starts without
  the DVD, and a bot match runs at the expected frame rate.
- Windows: `System/UT2004.exe` and `System/D3D11Drv.dll` exist; Linux:
  `System/ut2004-bin-amd64` runs.
- If VR: `System/UT2004VR-Uninstall.bat` exists and the VR shortcut opens.

## Troubleshooting

- **"Please insert the UT2004 CD"**: the patch missed the folder with
  `System/`, or a retail `System` was copied; copy the patch tree again.
- **Old 32-bit build starts, or UT2004VR calls the game 32-bit**: run
  `System\UT2004.exe` directly and apply the 3374 patch; VR needs 64-bit 3374.
- **UT2004VR stops on "Continue?"**: an existing `d3d9.dll` from ReShade or
  dgVoodoo was found. Move it to `d3d9.dll.pre-UT2004VR` and run the install
  again.
- **Black screen or missing renderer**: select the D3D11 renderer in
  Settings → Video (best on Windows 11); update the GPU driver.
- **VR shows nothing**: start SteamVR first and launch through `Play-VR.bat`.
  Hold left Y for about a second to recenter.
- **Antivirus flags `d3d9.dll`**: it is the unsigned UT2004VR SteamVR bridge,
  not a virus; the source is in the mod repository.
- **Linux binary will not start**: check `chmod +x System/UT2004`; the patch
  ships its own OpenAL and SDL3 libraries.

## License

UT2004 belongs to Epic Games; the user supplies the disc. OldUnreal's patch is
free and Epic-approved; UT2004VR is MIT, OpenVR BSD-3-Clause.
