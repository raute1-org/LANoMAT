---
title: Install Unreal Tournament 2004 (with optional UT2004VR)
description: The complete UT2004 install on a LAN PC — the agent, the disc image from the LAN file host, OldUnreal 3374, the optional UT2004VR SteamVR mod, VRM avatars and joining the server. What to click, what the agent runs, and what to do when a step fails.
sidebar_icon: gamepad-2
---

This guide is for a participant with a Windows PC (Linux works for the flat game).
You need a LANoMAT account, the LANoMAT agent, and the UT2004 files on the LAN — the
base game is **required**, the VR mod is **optional**.

The recipe is `unreal-tournament-2004`. The same flow works with one sentence to the
agent:

```text
install ut2004 (ut 2k4 or unreal tournament) for me on my pc
```

## What you need

| Thing | Where it comes from | Required? |
|---|---|---|
| LANoMAT account | Discord login on the LAN server | yes |
| `lanomat-agent` | npm or the LAN download page (see [Install the agent](/docs/guides/install-the-agent)) | yes |
| UT2004 disc image (`UT2004*.iso`, both DVDs or GOG/Steam install) | the event **Files** page, uploaded by you or the Orga | yes |
| OldUnreal patch 3374 64-bit (`OldUnreal-UT2004Patch3374-*.zip` / `.tar.bz2`) | LAN Files, or the OldUnreal release page as a fallback | yes on modern Windows/Linux |
| UT2004VR (`UT2004VR-*.zip`) | LAN Files / [jmkokko/ut2004-vr](https://github.com/jmkokko/ut2004-vr/releases) | no (Windows + SteamVR) |
| VRM avatars (`*.vrm`) | LAN Files, multi-select in the installer | no |
| SteamVR + a headset | Steam | only for VR |

## 1. See what is already on the LAN

Open `/games` and switch **Nur LAN-Spiele** on. A game has the amber **LAN** dot when at
least one required file (or a running server) is already on the LAN. Open
`/games/unreal-tournament-2004` (aliases `ut2k4`, `ut 2k4`, `unreal tournament` also work).

| Dark | Light |
|---|---|
| ![Games library, dark](/screens/games-dark.png) | ![Games library, light](/screens/games-light.png) |
| **`/games`** — the LAN filter, the server list and the one-click round. | |

The page shows the requirements and whether each one has a LAN match. If the disc image is
missing, ask the Orga to upload it to the event **Files** page (the approval is one click for
them). Do not download a random ISO from the internet unless the Orga tells you to.

## 2. Install and connect the agent

```bash
npm install -g @lanomat/agent
lanomat-agent login --url http://lan.local
```

`login` prints a code and opens `/mcp/connect`; approve it while logged in. Then wire the MCP
server and the recipe skills into pi:

```bash
lanomat-agent mcp install
lanomat-agent skills sync
lanomat-agent doctor
```

`doctor` must say the server is reachable and the model/tool-call probe works. See
[Install the agent](/docs/guides/install-the-agent) for the Windows paths, Venice setup and
`OPENAI_MODEL_*` overrides.

## 3. Ask for the plan

```bash
lanomat-agent recipes
lanomat-agent plan ut2004 --os windows
```

The plan is deterministic: `recipe`, `os`, `target_dir`, `steps`, `files`, `downloads`,
`avatars`, `missing`. The default target is `C:\Games\Unreal Tournament 2004`. The plan marks
each file as `matched` (LAN), `external` (needs the internet, ask first) or `missing`.
The UT2004VR step is marked `optional: true`.

## 4. Install the base game

**Windows, disc image:**

1. Mount the ISO (double-click, or `Mount-DiskImage -ImagePath .\UT2004-DVD1.iso`).
2. Run `Setup.exe` from the mounted drive, install to `C:\Games\Unreal Tournament 2004`.
3. Disc 2 is optional (extra maps); the base install works from Disc 1.

**Already own it on GOG/Steam:** point the agent at that folder
(`lanomat-agent install ut2004 --target "D:\Games\UT2004"`) and skip this step. The plan
still needs the patch.

The agent's install command does the downloads and runs the steps:

```bash
lanomat-agent install ut2004 --os windows
```

Use `--dry-run` first if you want to see every command without touching the disk.
It downloads only files marked `lan` by default; `--allow-external` is the explicit opt-in
for the OldUnreal fallback.

## 5. Apply OldUnreal patch 3374

The patch is a **drop-in tree**, not an installer. Extract the whole archive over the game
folder (the `3374-rc3` zip contains `Help/`, `Sounds/`, `System/`, `Textures/`,
`SystemLocalized/`, `Web/`, `SystemARM64/` — copy all of it; do **not** copy the retail
`System` folder from the disc over an existing install).

The patched build is 64-bit (`System\UT2004.exe` on Windows, `System/ut2004-bin-amd64` on
Linux) and removes the disc check. UT2004VR expects this exact build (3374, Preview 23 or
newer; rc3 is tested).

## 6. Optional: UT2004VR (Windows + SteamVR)

1. Download/unzip `UT2004VR-<version>.zip` from the LAN (or the upstream release).
2. Run **`Install.bat`**; it finds the UT2004 folder or asks for it, checks the version,
   copies the mod into `System`, and offers a desktop shortcut.
3. If a `d3d9.dll` from ReShade/dgVoodoo is already there, keep a copy: UT2004VR needs that
   file name and the installer backs up a foreign one to `d3d9.dll.pre-UT2004VR`.
4. Start SteamVR first, then the **UT2004 VR** shortcut (or `System\Play-VR.bat`).

The mod is offline-only in VR (online servers fall back to a flat screen). Hold the left Y
button for a second to recenter; `RenderScale` in `System\UT2004VR_proxy.ini` is the first
knob for low frame rates.

## 7. Avatars (optional, multi-select)

The installer lists every approved `.vrm` on the LAN as a multi-select:

```bash
lanomat-agent avatars --recipe ut2004          # browse the catalogue
lanomat-agent install ut2004 --avatar ayla --avatar brutus
lanomat-agent avatars --all                    # download the whole library
```

Downloads come from the LAN file host, in parallel, resumable and checksum-verified. If an
avatar is not on the LAN yet, the entry is disabled ("not on the LAN yet") — upload the
`.vrm` to the event Files page and ask the Orga to approve it.

The optional bridge to a UT2004 character is **`vrm2ut2004`** (private repo, built on the LAN
server) — or use the deployed converter at **<https://ut.raute1.org/>**: pick a VRM avatar,
convert it, download the finished UT2004 model. VRM selection, conversion and download are
one flow; the source files still come from the LAN.

## 8. Join the LAN server

Open `/games/unreal-tournament-2004` again. The **Server** card shows the live server with
its `unreal://host:port` link and map/player counts. Click **Joinen**, or use the console:

```text
open unreal://lan.raute1.org:7777
```

If no server is listed, the Orga has not started one: use the page's **Runde ankündigen**
button (one click, creates an LFG post) and join the event voice chat from the same card.
Starting a local `Instant Action` match works without a server.

## Verify

- `C:\Games\Unreal Tournament 2004\System\UT2004.exe` starts without the disc.
- The version line says 3374 (64-bit).
- `System\UT2004VR-Uninstall.bat` exists only if the VR mod is installed.
- The game page shows the ISO as **LAN** (not "fehlt") and `lan_ready` is true.
- The server answers with a map/player count, not a timeout.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `lanomat-agent plan` says a file is `missing` | The ISO/patch is not approved on the LAN yet. Upload it to the event Files page; the Orga approves it. |
| `plan` marks the patch `external` | No LAN copy; either add it to the LAN or use `--allow-external` (announced loudly). |
| Setup says "please insert Disc 2" | Disc 2 was requested for the extra maps; cancel or mount Disc 2. The base install is complete. |
| Patched game crashes on start | You copied the retail `System` over the patch; re-extract the whole patch tree. |
| VR: nothing in the headset | Start SteamVR **before** the game, launch via the VR shortcut/`Play-VR.bat`. |
| VR: wrong height/facing | Hold left Y ~1 s to recenter. |
| VR: `d3d9.dll` antivirus warning | It is the mod's unsigned SteamVR bridge; the source is in the repo. |
| `unreal://` link does nothing | The URI handler is not registered; run `lanomat-agent register` (Linux) or the printed `reg add` (Windows), or use the in-game `open` console command. |
| Avatar not offered | The `.vrm` is not approved, or the filename does not match; the matcher is case-insensitive and supports `*`/`?`. |

## Checklist

- [ ] LAN account + agent paired (`lanomat-agent doctor` green)
- [ ] ISO approved on the LAN and matched in the plan
- [ ] Base game installed to a stable folder
- [ ] OldUnreal 3374 extracted (whole tree)
- [ ] UT2004VR installed (optional) and SteamVR starts first
- [ ] Avatars selected (optional) and downloaded from the LAN
- [ ] Server joined, or a round announced in the LFG
- [ ] Voice chat open (`/talk`) if you want to talk while playing
