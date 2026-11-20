---
name: counter-strike-1-6
description: 'Install Counter-Strike 1.6 on the player PC and join the LAN server. Use when the user asks to install cs 1.6, CS 1.6, counter-strike, cstrike, or to join a CS 1.6 LAN game.'
compatibility: 'Windows or Linux (Wine/Proton); ~1 GB disk'
---

# Counter-Strike 1.6

## When to use

Use this skill when the user says **install cs 1.6**, **counter-strike**,
**cstrike**, **cs 1.6 for me**, or asks to join the CS 1.6 server on the LAN.
Also use it when the user wants to start a CS 1.6 round with other people —
the LANoMAT games page has a one-click "Runde ankündigen" and the event voice
chat next to it.

## Prerequisites

- Half-Life (Steam app 70, GOG, or the LAN retail copy).
- The `cstrike` archive or installer from the LAN file host.
- A working keyboard/mouse; a headset for the voice chat (optional).

## Steps

1. **Check for the LAN files.** Call `recipe_get` and `recipe_install_plan` for
   `counter-strike-1-6`. The base Half-Life files and the `cstrike` archive must
   come from the LAN file host. If a required file is missing, say exactly
   which one the Orga still has to upload — do not silently download from the
   internet.
2. **Ask about the edition.** Steam is the least fragile path on Windows:
   `steam://install/70` installs Half-Life, and the `cstrike` folder is copied
   into `steamapps/common/Half-Life`. A retail copy works too; use its own
   installer from the LAN.
3. **Install Half-Life.**
    - Steam: `Start-Process "steam://install/70"` and wait for the client to
      finish.
    - Retail/LAN: run the installer and install to a short path without spaces
      if possible (`C:\Games\Half-Life`).
4. **Install Counter-Strike 1.6.** Unpack the archive so that the `cstrike`
   folder sits **next to `hl.exe`**, not inside another `cstrike` folder. A
   common mistake is `Half-Life/cstrike/cstrike/models`.
5. **Apply the LAN config (optional).** Copy `autoexec.cfg` and a
   `server.cfg` from the recipe's `config` requirement into `cstrike/`.
   `autoexec.cfg` usually sets `rate`, `cl_updaterate`, `cl_cmdrate`, `fps_max`
   and the player name.
6. **Start and join.** Run `hl.exe -game cstrike`, open the server browser, or
   use the `connect <address>:<port>` command from the LANoMAT games page. On
   Steam, `steam://connect/<address>:<port>` jumps straight in.
7. **Voice.** If the event has web voice, join `/talk` in LANoMAT or use the
   Discord channel. The game itself has no built-in voice on this setup.

## Verify

- `hl.exe -game cstrike` reaches the main menu without a missing-file dialog.
- The server appears in the in-game server browser, or the `connect` command
  from the LAN page succeeds.
- Mouse look and WASD work; if not, the config bound the wrong keys.

## Troubleshooting

- **"Could not load library" / missing models:** the `cstrike` archive was
  unpacked into the wrong folder; it belongs next to `hl.exe`.
- **No server listed:** the LAN server may still be starting; the LANoMAT games
  page marks ready servers with a live dot.
- **CD key prompt:** use the LAN copy's key file or ask the Orga.
- **Wrong resolution / black screen:** set `-windowed -w 1280 -h 720` once,
  then change it in the game options.
- **Linux:** run the Windows build through Proton; native GoldSrc builds are
  old and fragile. `steam://connect/...` works from the Steam client.

## Working together

- Announce a round from the game page (one click) so others see it in the LFG
  list; the voice chat link is right next to it.
- Running tournaments for CS 1.6 appear on the same page; join the tournament
  from its own page, not from the server.
- If the LAN has no CS server yet, one participant can host a listen server
  (`New Game` → `LAN`) and share the address; the page shows the join string.

## License

Valve; base game files are user-supplied. Keep the LAN copy to people who own
the game.
