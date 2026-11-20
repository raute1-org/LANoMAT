---
title: Install Counter-Strike 1.6
description: Half-Life + Counter-Strike 1.6 on a LAN PC — Steam apps, the cstrike archive, the LAN config, joining the server, and the tournament/LFG flow.
sidebar_icon: crosshair
---

Counter-Strike 1.6 is the LAN's tactical shooter. The recipe is `counter-strike-1-6`.
The simplest path is Steam; the LAN path uses the Half-Life base and the `cstrike` archive
from the file host.

## What you need

| Thing | Where | Required? |
|---|---|---|
| Half-Life (Steam app 70 or GOG/retail) | Steam or LAN Files | yes (Steam app 10 alone also works) |
| Counter-Strike 1.6 (`cstrike` archive or Steam app 10) | LAN Files / Steam | yes |
| `autoexec.cfg` / `server.cfg` | LAN Files (optional) | no |
| HLDS server (app 90) | LAN server / Orga | for a hosted server |

## Steam path (recommended)

```text
steam://install/70      (Half-Life)
steam://install/10      (Counter-Strike 1.6)
```

Start Half-Life, choose **Counter-Strike** in the mods list, then **Find Servers → LAN**.
`steam://connect/<host>:27015` jumps straight into the LAN server.

## LAN path (cstrike archive)

1. Install Half-Life from the LAN ISO/Steam to `C:\Games\Half-Life`.
2. Unpack the `cstrike` archive so the `cstrike` folder sits **next to `hl.exe`**, not inside
   another `cstrike` (`Half-Life\cstrike\models`, not `Half-Life\cstrike\cstrike\models`).
3. Copy the LAN `autoexec.cfg`/`server.cfg` into `cstrike/` if the Orga provides them.
4. Start `hl.exe -game cstrike`, open the console (`~`) and `connect <host>:27015`.

## Server

The Orga starts an HLDS (Steam app 90) or a listen server (`New Game → LAN`). The game page
`/games/counter-strike-1-6` shows the server with a **In Steam joinen** button
(`steam://connect/...`) or a copyable `connect` command. The default port is `27015`; a
password, if set, is in the server card.

## Tournament and LFG

If a CS 1.6 tournament is running, it appears on the game page with its status and link; join
from the tournament page, not from the server. Use **Runde ankündigen** for a casual round;
the LFG post is visible to the event and the voice chat link sits next to it.

## Verify

- `hl.exe -game cstrike` reaches the menu without a missing-file dialog.
- The server appears in the LAN browser or the `connect` command succeeds.
- Mouse/WASD work; the config did not bind the wrong keys.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Could not load library" / missing models | `cstrike` unpacked into the wrong folder; keep it next to `hl.exe`. |
| CD-key prompt | Use the LAN copy's key or ask the Orga. |
| No server listed | It may still be starting; a ready server has the amber dot. |
| Wrong resolution / black screen | Start with `-windowed -w 1280 -h 720`, then set options. |
| Linux | Run the Windows build through Proton; native GoldSrc builds are old. |
| `steam://connect` does nothing | Steam must be running; use the console `connect` instead. |

## Checklist

- [ ] Half-Life base + cstrike installed (or Steam apps 70/10)
- [ ] `cstrike` next to `hl.exe`
- [ ] LAN config copied (optional)
- [ ] Server joined (`steam://connect` or `connect`)
- [ ] Tournament/LFG checked on `/games/counter-strike-1-6`
