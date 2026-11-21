---
name: ut2004
description: 'Install Unreal Tournament 2004 on a LAN PC from a portable 3374 archive, the UT2004 DVD image, an installer ZIP or an existing GOG/Steam copy, apply OldUnreal patch 3374 on Windows, Linux or macOS, then start it flat or optionally install UT2004VR with SteamVR and vrm2ut2004 avatars. Always ask flat or VR before installing. Use when the user says install ut2004, ut2k4, ut 2k4, unreal tournament, or asks to set up UT2004 for the LAN.'
---

# Unreal Tournament 2004

UT2004 is the LAN's baseline arena shooter. This skill puts a working 64-bit
install on the PC from a portable 3374 archive (preferred) or the LAN disc
image plus the required OldUnreal patch, and offers two optional extras: the
UT2004VR mod and VRM avatars. It covers Windows, Linux and macOS.

## When to use

Use this skill when the user says any of:

- "install ut2004 for me"
- "install ut2k4", "install ut 2k4", "install ut 2004"
- "install unreal tournament" (when they mean the 2004 LAN game, not UT99)
- "set up UT2004 for the LAN", "patch UT2004", "make UT2004 run on Windows 11"
- "install the VR mod for UT2004"

Do not use it for Unreal Tournament 1999 (different game and patch line) or for
the dedicated server on the game-server host.

## What you need

- **Preferred:** the portable 3374 base archive for the player's OS on the LAN
  file host, already carrying OldUnreal's patched game tree:
  `ut2004-portable-3374.zip` (Windows),
  `ut2004-portable-3374-linux*` (Linux, `.zip` or `.tar.bz2`) or
  `ut2004-portable-3374-macos*` (macOS, `.zip` or `.tar.bz2`, contains
  `UT2004.app` plus the asset folders). It needs no ISO and no patch step.
- **Fallback:** the UT2004 DVD 1 image (`ut2004*.iso`), an installer ZIP such
  as `ut2k4+keygen.zip`, or an existing GOG/Steam install. Disc 2 only adds
  maps. The user supplies the game files. `ut2k4+keygen.zip` is the nosTEAM
  installer, not a portable base: the recipe treats it like the ISO and the OS
  patch stays mandatory. A GOG/Steam folder is a manual target: set
  `{{target}}` to that install and start at the patch step.
- The OldUnreal 3374 archive for the target OS:
  `OldUnreal-UT2004Patch3374-Windows-*.zip`,
  `OldUnreal-UT2004Patch3374-Linux-*.tar.bz2` or
  `OldUnreal-UT2004Patch3374-macOS*.dmg` (macOS universal). The latest release
  is `3374-rc3` (2026-11-03).
- About 5 GB of disk and a current GPU driver. Patch 3374 is 64-bit only:
  Windows 7+, 64-bit Linux or macOS, and no Wine is needed.
- For VR: Windows 10/11, SteamVR, a headset with motion controllers and the
  `UT2004VR-*.zip` release (v0.1.1 is verified).

On Windows run the `powershell` commands, on Linux and macOS the `bash` ones;
the installer filters by the machine's shell. The native launcher ignores the
steps on macOS; the agent follows them.

## Steps

1. **Pick the install folder.** `{{target}}` should be short and writable, for
   example `C:\Games\UT2004` or `~/Games/UT2004`. Avoid `Program Files`. On
   macOS the app may live in `~/Applications/UT2004.app` while the game data
   stays in `~/Library/Application Support/UT2004`.
2. **Prefer the portable 3374 base.** If the matching archive for the OS exists
   on the LAN, unpack it and skip the disc copy and the patch step. Windows:
   unpack into `{{target}}`, check `System/UT2004.exe` and `System/Build.ini`.
   Linux: unpack into `{{target}}`, `chmod +x System/UT2004`, check
   `System/ut2004-bin-amd64`. macOS: unpack, move `UT2004.app` to
   `~/Applications` (or `{{target}}`) and the asset folders to
   `~/Library/Application Support/UT2004`. The archive name carries the OS, so
   a plan never takes another platform's folder.
3. **Otherwise copy the base game assets from Disc 1.** Mount the ISO and copy
   every disc folder except `System` into `{{target}}` (`~/Library/Application
Support/UT2004` on macOS). OldUnreal's patch brings the complete `System`
   tree, and a retail `System` mixed in keeps the patched game from starting
   (on Linux it fails outright). On Linux unpack the ISO with `bsdtar`; on
   macOS use `hdiutil`/`ditto`. No Wine. Globs are matched literally, so the
   recipe lists the lowercase and uppercase ISO spellings. An installer ZIP
   (`ut2k4+keygen.zip`) is unpacked the same way; if it only carries a
   `Setup.exe`, extract the game folders first.
4. **Apply OldUnreal patch 3374.** Windows: extract the zip and copy the whole
   tree over `{{target}}`, merging (it also carries `Help/`, `Sounds/`,
   `SystemARM64/`, `SystemLocalized/`, `Textures/` and `Web/`). Linux: extract
   the `.tar.bz2` into `{{target}}` and `chmod +x System/UT2004`. macOS: mount
   the universal DMG, copy `UT2004.app` to `~/Applications` (or `{{target}}`),
   merge any asset folders into `~/Library/Application Support/UT2004`, then
   optionally `xattr -dr com.apple.quarantine` the app. Check `v3374` in
   `System/Build.ini` (Windows/Linux) or inside the app bundle (macOS).
5. **Dismount the ISO/DMG.** The patched client does not ask for the DVD. If it
   still does, the patch landed in the wrong folder.
6. **Install UT2004VR if the user chose VR.** Unzip the release and call
   `Installer/Install.ps1` with `-GameDir`, `-Unattended` and `-NoShortcut`.
   `Install.bat` is the interactive wrapper and ends in `pause`.
   The installer checks the 64-bit build and the `v3374` build date
   (2026-07-18 or later, so rc3 passes) and refuses to run while UT2004 is
   running.
7. **Handle `d3d9.dll` before the VR install.** That file name is the mod's
   SteamVR bridge. If ReShade or dgVoodoo already owns it, move it to
   `System\d3d9.dll.pre-UT2004VR` first; the installer would otherwise stop at
   its unattended "Continue?" prompt (default: no). The uninstaller restores
   the backup and the other tool is off while the mod is installed.
8. **Ask about avatars.** This is a multi-select, not a yes/no question. See
   the next section.
9. **Smoke test.** Start the game, then Instant Action → Deathmatch → DM-Rankin
   with 4 bots. Confirm video, audio and mouse input.

## Starting the game

- **Flat, Windows:** `System\UT2004.exe`.
- **Flat, Linux:** `System/ut2004-bin-amd64` (a symlink to the 64-bit
  `System/UT2004`).
- **Flat, macOS:** `open "$HOME/Applications/UT2004.app"`, or
  `open "{{target}}/UT2004.app"` when the app stayed in the install target.
- **VR, Windows:** start SteamVR, then run the `UT2004 VR` shortcut or
  `System\Play-VR.bat`. VR is on only when started from `Play-VR.bat`; a normal
  start stays flat. `Play-VR.bat DM-Antalus` skips the menus.

## Verification

- `System/Build.ini` reports `v3374`, the main menu starts without
  the DVD, and a bot match runs at the expected frame rate.
- Windows: `System/UT2004.exe` and `System/D3D11Drv.dll` exist; Linux:
  `System/ut2004-bin-amd64` runs; macOS: `UT2004.app` is in `~/Applications`
  or `{{target}}` and starts without a quarantine prompt.
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
- **macOS says the app is damaged or from an unidentified developer**: it is
  not notarised. Run `xattr -dr com.apple.quarantine ~/Applications/UT2004.app`
  or allow it once under System Settings → Privacy & Security.
- **macOS starts without maps**: the assets belong in
  `~/Library/Application Support/UT2004`, not next to the `.app`. Recopy the
  disc folders (without `System`) or use the portable macOS base archive.

## License

UT2004 belongs to Epic Games; the user supplies the disc. OldUnreal's patch is free and Epic-approved.
