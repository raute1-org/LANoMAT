---
title: Need for Speed Underground 2
description: Portable 1.2 game tree plus the NFS-Online client for LAN play, with nfsu2.xyz as the browser fallback.
sidebar_icon: car
---

Need for Speed: Underground 2 is the LAN's open-city street racer: career garages, URL races and
free roam. EA's online service is gone, so the recipe adds the community **NFS-Online** client and
points it at the LAN server. The base game is user-supplied; no game files ship with LANoMAT.

<div class="live">LIVE 16:18 · NFSU2 · recipe published · NFS-Online 1.1.3 · browser fallback nfsu2.xyz</div>

There are two paths, and they are not the same service:

| Path | What runs | Needs |
|---|---|---|
| **Native** | `SPEED2.EXE` 1.2, Ultimate ASI Loader, `net_u2.asi` + `net_u2.ini` | Windows, or Linux with Wine/Proton |
| **Browser** | `https://nfsu2.xyz/` (x86→C→WebAssembly, WebGPU) | Chrome/Brave with WebGPU, internet |

`nfsu2.xyz` is an independent public deployment, not the LANoMAT server. The native path talks to
the NFS-Online server on the LAN.

![nfsu2.xyz start page in Brave, Ready, Play and live player counts](/screens/nfsu2/browser-start-page-brave.png)

## What the recipe installs

| Part | Role | Required? | Source |
|---|---|---|---|
| NFSU2 portable 1.2 tree | `base` (one entry per plan OS) | **Yes** | LAN file host (`nfsu2-portable*.zip`) |
| NFSU2 1.2 patch | `patch` | No | LAN file host, only for an older tree |
| NFS-Online client `net_u2.asi` | `mod` | No (recommended) | NFS-Online-Server 1.1.3, client 0.2.22 |
| `net_u2.ini` | `config` | No (recommended) | same repo; the step writes the plan's LAN host into it |
| Ultimate ASI Loader `dinput8.dll` | `tool` | No (portable tree usually has it) | ThirteenAG, x86, v10.0.4 |
| NFSU2 Split Screen 0.14.1 beta | `mod` | No | LAN file host, local 2–6 players |

The recipe is `need-for-speed-underground-2`. It is **published** in the bundled catalogue and
hand-verified with the launcher's generic engine (`LauncherSupport::VERIFIED_SLUGS`), so its card
appears once the catalogue is synced and the lineup allows it. An install still needs the portable
ZIP on the file host; without a match the plan lists the base as `[MISSING]`.

| Native title screen (Proton) | Server status |
|---|---|
| ![NFSU2 title screen over Proton Experimental](/screens/nfsu2/nfsu2-native-title.png) | ![Terminal with NFS-Online status, ports and HTTP checks](/screens/nfsu2/nfs-online-server-status.png) |

| Launcher library of a terminal | Downloaded archive and hashes |
|---|---|
| ![Launcher library: UT2004 in Meine Bibliothek and in the server catalogue, Apocalypse marked Bald im Launcher](/screens/nfsu2/launcher-library.png) | ![Terminal with SHA-256, 1,393 files and SPEED2.EXE hash](/screens/nfsu2/nfsu2-archive-facts.png) |

| Quick Race Car Select (native) | Browser intro |
|---|---|
| ![NFSU2 Quick Race Car Select over Proton](/screens/nfsu2/nfsu2-native-quickrace.png) | ![nfsu2.xyz intro cutscene in Chrome](/screens/nfsu2/browser-intro-chrome.png) |

## Install and launch

1. Unpack the portable ZIP and remove the single wrapper folder, so `SPEED2.EXE`, `CARS/`,
   `TRACKS/` and `scripts/` sit directly in the target.
2. Put `dinput8.dll` (x86 Ultimate ASI Loader) in the game root.
3. Put `net_u2.asi` and `net_u2.ini` in `scripts/`.
4. Set the same `host` in `[network]` and `[lan]` of `net_u2.ini`. The recipe names no address of
   its own: its Windows and Linux steps use `{{server_host}}`, which LANoMAT fills in from a server
   whose game carries **exactly** this recipe's slug (`need-for-speed-underground-2`, never the
   browser twin), that answers or is at least known to run, and that the viewer may see. Without such
   a server the placeholder is empty and the step leaves the `host` lines as the file carries them,
   so take the address from `/servers` or from the Orga.
5. Windows: start `SPEED2.EXE`. Linux: start it through Wine with the loader override:

```bash
export WINEDLLOVERRIDES="dinput8=n,b"
wine SPEED2.EXE
```

Without the override Wine uses its built-in `dinput8`, the ASI loader never runs and the game
stays offline. Proton works the same way when a `proton` command or the full Proton path is used.

The native NFS-Online server is registered by the lineup seeder as a permanent server, but
only when the installation sets `NFS_SERVER_HOST`; the config has no built-in address, so without the
variable no native NFSU2 server appears. Only the browser variant (`nfsu2.xyz:443`) is seeded without
configuration.

## Server ports (U2)

| Service | Protocol | Port |
|---|---|---:|
| Bootstrap | TCP | 20921 |
| Lobby/auth | TCP | 20922 |
| Control/PREL | TCP | 20923 |
| EA Messenger / control alias | TCP | 13505 |
| Race relay | **UDP** | **20000–20005** |
| Messenger IPC | TCP | 13506 (loopback only) |

All six UDP ports are needed for six racers. The full event-server runbook is
`docs/ops/nfsu2-server.md` in the repository; the browser port is documented in
`docs/ops/nfsu2-browser.md`.

## Browser fallback

![nfsu2.xyz after Play: Create Profile](/screens/nfsu2/browser-profile-create-brave.png)

`nfsu2.xyz` boots without a game install, but needs WebGPU, a secure HTTPS page and internet.
Saves live in the browser. Self-hosting needs the user's own v1.2 copy with this exact file:
`SPEED2.EXE`, 4,800,512 bytes, SHA-256 `f9dd86c054878ce6276beb07c1fd61874f7a1e4bf1f241b084c65b73e24168a7`.
The upstream repository is archived since 2026-11-09; the public server continues privately.

## Known blockers

- The downloaded `NFSU2-portable.zip` is still the raw archive (wrapper folder, no
  `scripts/net_u2.asi`/`net_u2.ini`, older bundled `dinput8.dll`); the flat LAN package is pending.
- No LAN file match yet, so the installer has nothing to download.
- A server that already runs before the host change still advertises the old address; restart it so
  the address the plan fills into `net_u2.ini` and the address the server advertises agree.
- `python nfs_online.py status` reports `Stopped` for a foreground/container start because
  `runtime/services.json` is missing; check ports and `/news` instead.
- A full login/lobby/race handshake with two real clients is still untested.
- Split-screen and the NFS-Online ASI both patch the game. They load together in one process, but a
  2-player race with both is untested.
