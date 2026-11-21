---
name: need-for-speed-underground-2-browser
description: 'Play Need for Speed Underground 2 without installing anything: open the Underground 2 Revived browser port at nfsu2.xyz in Chrome or Brave, check WebGPU, join the LAN group in one world, and explain saves, cloud passphrase and the optional self-hosted server. Use when the user says NFSU2 browser, Underground 2 in the browser, nfsu2.xyz, or wants a zero-install fallback to the native NFSU2 recipe.'
---

# Need for Speed: Underground 2 (Browser)

The zero-install path to NFSU2: the community port **Underground 2 Revived**
runs the PC game as WebAssembly, draws with WebGPU and plays sound through Web
Audio. Nothing is downloaded or installed on the player's PC. This skill is
the fallback when the native install (Wine or Windows) is too slow to set up,
when a laptop has no Wine, or when someone just wants to drive now.

## When to use

Use this skill when the user says any of:

- "NFSU2 in the browser", "Underground 2 browser", "nfsu2.xyz"
- "play NFSU2 without installing", "zero-install NFSU2"
- "the native NFSU2 install does not work, is there another way?"
- "can we play Underground 2 on this laptop?"

Use the native `need-for-speed-underground-2` recipe when the user wants the
Windows game with the NFS-Online server, mods or a permanent install. This
browser recipe is not a replacement for that; it is a parallel path.

## What the player needs

- A current Chromium browser: Chrome, Edge, Brave or Opera. Firefox works on
  Windows and on Apple Silicon macOS where it has WebGPU; on Linux Firefox has
  WebGPU off by default, so use Chrome or Brave.
- WebGPU on real graphics. Check `chrome://gpu`; on Linux install the Mesa
  Vulkan driver. Intel Gen12+ and NVIDIA on Wayland work by default; older
  cards may need `chrome://flags/#enable-unsafe-webgpu`.
- About 1 GB of free memory for the game, plus a tab that is allowed to use
  shared WebAssembly memory.
- A secure page: `https://nfsu2.xyz/` qualifies; a plain `http://` LAN address
  does not (only `localhost` is exempt).
- A clean profile or guest window if extensions break cross-origin isolation
  (`PAGE-NOT-ISOLATED`, "Something in this browser changes the page").

No game files, no disc, no Wine, no key. On the public server the operator
supplies the game data. Self-hosting is the only path that needs the user's own
copy.

## Steps

1. **Check the browser.** Open `chrome://gpu` and confirm WebGPU is hardware
   accelerated. On Linux, verify the Vulkan driver. Close memory-hungry tabs.
2. **Open the page.** Go to https://nfsu2.xyz/. Wait for **Ready**. Pick a
   name or leave it empty, then press **Play** or Enter.
3. **Join the group.** Online, World: choose the same world as the LAN group
   (main world, World 1-4, or a custom name). Share the `#room=` link. Worlds
   are a filter, not privacy; the public server still has other players.
4. **Explain saves.** Saves are browser-local. Cloud saves use an 8-word
   passphrase and encrypt before upload; there is no account and no reset.
   Backup/Restore moves profiles between laptops. No 100% save is bundled;
   restore one from a ZIP of the PC game's profile folder.
5. **Offer self-hosting only if asked.** It needs the exact v1.2 NA
   `SPEED2.EXE`, Rust, wasi-sdk 34 and TLS at the venue. See
   `docs/ops/nfsu2-browser.md`.

## Decision: public server or self-host?

| Situation                                    | Use                                                            |
| -------------------------------------------- | -------------------------------------------------------------- |
| Quick fallback, internet at the venue        | The public server, `https://nfsu2.xyz/`                        |
| Closed group, no strangers, LAN-only traffic | Self-host at the venue                                         |
| No internet at all                           | Self-host; the public page needs the internet for a first load |
| One or two weak laptops                      | Public server first; it needs no disk and no Wine              |

The public server is a live multiplayer service. Other players are real, many
races are ranked, and cheat tools are banned. Do not modify the game module or
automate driving.

## Known limits

- **WebGPU only.** No WebGL fallback. Firefox on Linux, old Intel HD graphics
  without Direct3D 12, and some Android GPUs fail.
- **Secure context.** A self-hosted `http://10.x.y.z:8080` page does not start
  the game. Use HTTPS with a trusted certificate, or `localhost` on the host.
- **Bandwidth.** First load is about 7 MB of code; game data streams and can
  be preloaded (~2 GB on disk, ~1 GB over the network). Many fresh laptops at
  once can saturate a venue uplink.
- **Saves are per origin and per browser.** A different host name or a private
  window has different saves. Cloud saves need the passphrase.
- **Upstream is archived.** `th0mk/nfsu2-web` was archived on 2026-11-09;
  nfsu2.xyz keeps running and is developed privately. A self-hosted build is
  frozen at the archived code and may lag behind the public server.
- **Licensing.** Code is MIT, but the generated game module is not licensed
  for redistribution, and the game data remains EA's. Do not copy the module
  or the game folder to other people.

## Troubleshooting

| Symptom                        | Action                                                                                          |
| ------------------------------ | ----------------------------------------------------------------------------------------------- |
| `GPU-OFF`, `GPU-ADAPTER-LINUX` | Install the Vulkan driver, update the browser, or enable `chrome://flags/#enable-unsafe-webgpu` |
| `PAGE-NOT-SECURE`              | Use `https://`, not `http://` (except localhost)                                                |
| `PAGE-NOT-ISOLATED`            | Guest window or disable extensions; the page must be served by the game server                  |
| `PAGE-NO-MEMORY`               | Close tabs, restart the browser, free about 1 GB                                                |
| `PAGE-NO-SIMD`                 | CPU without SSE4.1 or a too-old browser; the game cannot run                                    |
| Game stops (`GPU-LOST`)        | Press Play again; update the graphics driver if it repeats                                      |
| Empty lobby / no other cars    | Everyone must use the same server and world; check the world name                               |
| Offline after a reload         | Download the game data first; free roam and Online still need the server                        |

## Verify before promising it

- The page says **Ready**, not an error code.
- Press Play once and reach the profile or main menu.
- On the actual laptop, check `chrome://gpu` and the screen resolution: the
  game renders at the browser's resolution and may need a lower frame rate or
  resolution scale on weak graphics.
- On the venue network, load the page once from a client VLAN, not only from
  the host.

## Sources

- https://nfsu2.xyz/ (live deployment, start page, changelog)
- https://github.com/th0mk/nfsu2-web (MIT, archived 2026-11-09; README and
  `docs/web/PLAYING.md` are the authoritative player and self-host docs)
- `docs/ops/nfsu2-browser.md` in this repository: verification log, screenshot
  paths, hosting options and open points.
