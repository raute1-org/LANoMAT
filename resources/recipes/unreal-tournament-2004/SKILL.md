---
name: ut2004
description: 'Install Unreal Tournament 2004 on a LAN PC from the UT2004 DVD image or an existing GOG/Steam copy, apply OldUnreal patch 3374 64-bit, and optionally install UT2004VR with SteamVR and vrm2ut2004 avatars. Use when the user says install ut2004, ut2k4, ut 2k4, unreal tournament, or asks to set up UT2004 for the LAN.'
---

# Unreal Tournament 2004

UT2004 is the LAN's baseline arena shooter. This skill puts a working 64-bit
install on the PC from the LAN disc image, applies the required OldUnreal patch,
and offers two optional extras: the UT2004VR mod and VRM avatars.

## When to use

Use this skill when the user says any of:

- "install ut2004 for me"
- "install ut2k4", "install ut 2k4", "install ut 2004"
- "install unreal tournament" (when they mean the 2004 LAN game, not UT99)
- "set up UT2004 for the LAN", "patch UT2004", "make UT2004 run on Windows 11"
- "install the VR mod for UT2004"

Do not use it for Unreal Tournament 1999 (different game and patch line) or for
a dedicated UT2004 server on the game-server host.

## What you need

- The UT2004 DVD 1 image from the LAN file host (`ut2004*.iso`), or an existing
  GOG/Steam install. The user supplies the game files.
- The OldUnreal 3374 archive for the target OS:
  `OldUnreal-UT2004Patch3374-Windows-*.zip` or
  `OldUnreal-UT2004Patch3374-Linux-*.tar.bz2`.
- About 5 GB of disk, a GPU with a current driver, and a mouse and keyboard.
- For VR: Windows 10/11, SteamVR, a headset with motion controllers, and the
  `UT2004VR-*.zip` release.
- For avatars: one or more `.vrm` files, and the private `vrm2ut2004` repo on
  the LAN server if the user wants them converted.

## Steps

1. **Pick the install folder.** `{{target}}` should be short and writable, for
   example `C:\Games\UT2004` or `~/Games/UT2004`. Avoid `Program Files`.
2. **Install the base game.** Windows: mount the ISO, run `Setup.exe` from the
   drive letter the mounted image got (not a hardcoded `D:`), choose a full
   install. Linux: run the same installer through Wine/Proton, or copy a
   working install. Keep the disc mounted for now.
3. **Apply OldUnreal patch 3374.** Extract the archive and merge the whole tree
   into `{{target}}`: `Help/`, `Name/`, `Sounds/`, `System/`, `SystemARM64/`,
   `SystemLocalized/`, `Textures/` and `Web/`. The Windows zip has no installer;
   the Linux tarball is the same drop-in tree with `System/ut2004-bin-amd64`.
   On Windows confirm `System/UT2004.exe` is the 64-bit build and
   `System/D3D11Drv.dll` exists; on Linux confirm `System/ut2004-bin-amd64` and
   the `System/UT2004` launcher.
4. **Dismount the ISO.** The patch removes the disc check, so the game no longer
   needs the image. If it still asks for the DVD, the patch went to the wrong
   folder.
5. **Ask about VR.** If the user has a headset, install UT2004VR: unzip the
   release, run `Install.bat`, and let it find `{{target}}`. Back up an existing
   `System\d3d9.dll` first. Start SteamVR before the `UT2004 VR` shortcut.
6. **Ask about avatars.** See the next section. This is a multi-select, not a
   yes/no question.
7. **Smoke test.** Start the game, then Instant Action → Deathmatch → DM-Rankin
   with 4 bots. Confirm video, audio and mouse input.

## Asking about avatars

Avatars are `role: avatar` requirements in the recipe, matched against the LAN
file host. The installer shows every match as a multi-select:

1. List the LAN matches first and recommend them ("Ayla, Brutus, Kestrel, Mako,
   Sable are on the file host"). Prefer LAN copies over downloads.
2. Ask: "Which avatars should I install? Pick any number, or none."
3. If the user picks files that are not on the LAN, ask for the `.vrm` paths or
   let them drop the files into `{{downloads}}`.
4. Only build with `vrm2ut2004` if the user wants converted characters. Clone
   the private repo on the LAN server, follow its README, and copy the built
   packages to each client.
5. Avatars are optional. Never block the base install on them, and never invent
   download URLs for a catalog entry that has none.

## Verification

- Windows: `{{target}}/System/UT2004.exe` and
  `{{target}}/System/D3D11Drv.dll` exist after the patch.
- Linux: `{{target}}/System/ut2004-bin-amd64` and `{{target}}/System/UT2004`
  exist after the patch.
- The main menu starts without the DVD and shows the OldUnreal build.
- A bot match runs at the expected frame rate.
- If installed: `{{target}}/System/UT2004VR-Uninstall.bat` exists, and the VR
  shortcut opens the intro in the headset.

## Troubleshooting

- **"Please insert the UT2004 CD"**: the patch was not applied to the folder
  that contains `System/`. Copy the patch tree over `{{target}}` again.
- **Launcher starts the old 32-bit build**: start `System\UT2004.exe` directly,
  or recreate the shortcut after patching.
- **Black screen or missing renderer**: select the D3D11 renderer in
  Settings → Video; update the GPU driver.
- **VR shows nothing**: start SteamVR first and launch through the `UT2004 VR`
  shortcut or `System\Play-VR.bat`. Hold left Y for a second to recenter.
- **VR stutters**: lower `RenderScale` in `System\UT2004VR_proxy.ini` (for
  example 0.7).
- **Antivirus flags `d3d9.dll`**: it is the UT2004VR SteamVR bridge, not a
  virus. The source is in the mod repository.
- **Linux binary will not start**: check `chmod +x` on `System/UT2004` and
  `System/ut2004-bin-amd64`; install the 64-bit OpenAL libraries shipped in the
  patch archive.

## License

Unreal Tournament 2004 belongs to Epic Games. No game files ship with this
recipe; the user supplies the disc or store copy. UT2004VR is MIT-licensed.
