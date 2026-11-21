---
title: Need for Speed Underground 2
description: Portable 1.2 game tree plus the NFS-Online client for LAN play, with nfsu2.xyz as the browser fallback.
sidebar_icon: car
---

Need for Speed: Underground 2 is the LAN's open-city street racer: career garages, URL races and
free roam. EA's online service is gone, so the recipe adds the community **NFS-Online** client and
points it at the LAN server. The base game is user-supplied; no game files ship with LANoMAT.

<div class="live">LIVE 16:18 · NFSU2 · recipe draft · NFS-Online 1.1.3 · browser fallback nfsu2.xyz</div>

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
| NFSU2 portable 1.2 tree | `base` | **Yes** | LAN file host (`nfsu2-portable*.zip`) |
| NFSU2 1.2 patch | `patch` | No | LAN file host, only for an older tree |
| NFS-Online client `net_u2.asi` | `mod` | No (recommended) | NFS-Online-Server 1.1.3, client 0.2.22 |
| `net_u2.ini` | `config` | No (recommended) | same repo, host set to the LAN server |
| Ultimate ASI Loader `dinput8.dll` | `tool` | No (portable tree usually has it) | ThirteenAG, x86, v10.0.4 |

The recipe is `need-for-speed-underground-2`. The current live record is still **draft**, so the
launcher library does not show the NFSU2 card yet. Until the portable ZIP is on the file host and
the recipe is published, `installable` stays false.

| Native title screen (Proton) | Server status |
|---|---|
| ![NFSU2 title screen over Proton Experimental](/screens/nfsu2/nfsu2-native-title.png) | ![Terminal with NFS-Online status, ports and HTTP checks](/screens/nfsu2/nfs-online-server-status.png) |

| Launcher library (NFSU2 card still missing) | Downloaded archive and hashes |
|---|---|
| ![Launcher library with UT2004 and Apocalypse, no NFSU2 card](/screens/nfsu2/launcher-library.png) | ![Terminal with SHA-256, 1,393 files and SPEED2.EXE hash](/screens/nfsu2/nfsu2-archive-facts.png) |

| Quick Race Car Select (native) | Browser intro |
|---|---|
| ![NFSU2 Quick Race Car Select over Proton](/screens/nfsu2/nfsu2-native-quickrace.png) | ![nfsu2.xyz intro cutscene in Chrome](/screens/nfsu2/browser-intro-chrome.png) |

## Install and launch

1. Unpack the portable ZIP and remove the single wrapper folder, so `SPEED2.EXE`, `CARS/`,
   `TRACKS/` and `scripts/` sit directly in the target.
2. Put `dinput8.dll` (x86 Ultimate ASI Loader) in the game root.
3. Put `net_u2.asi` and `net_u2.ini` in `scripts/`.
4. Set the same `host` in `[network]` and `[lan]` of `net_u2.ini`. On the event LAN that is
   `10.23.0.12`; the server must advertise the same address.
5. Windows: start `SPEED2.EXE`. Linux: start it through Wine with the loader override:

```bash
export WINEDLLOVERRIDES="dinput8=n,b"
wine SPEED2.EXE
```

Without the override Wine uses its built-in `dinput8`, the ASI loader never runs and the game
stays offline. Proton works the same way when a `proton` command or the full Proton path is used.

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

- Live recipe is draft: no launcher card until published.
- The downloaded `NFSU2-portable.zip` is still the raw archive (wrapper folder, no
  `scripts/net_u2.asi`/`net_u2.ini`, older bundled `dinput8.dll`); the flat LAN package is pending.
- No LAN file match yet, so the installer has nothing to download.
- The running test server was started before the host changed to `10.23.0.12`; restart it so the
  advertised address matches the client INI.
- `python nfs_online.py status` reports `Stopped` for a foreground/container start because
  `runtime/services.json` is missing; check ports and `/news` instead.
- A full login/lobby/race handshake with two real clients is still untested.
- Split-screen and the NFS-Online ASI both patch the game. They load together in one process, but a
  2-player race with both is untested.
