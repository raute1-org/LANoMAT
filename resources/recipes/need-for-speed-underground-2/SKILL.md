---
name: nfsu2
description: 'Install Need for Speed Underground 2 1.2 on a LAN PC from the portable nfsu2-portable.zip archive, add the NFS-Online ASI client (net_u2.asi, net_u2.ini, dinput8.dll), point it at the LAN server and start the game on Windows or through Wine on Linux. Use when the user says install NFSU2, NFS Underground 2, NFS U2, or asks to set up Underground 2 for the LAN.'
---

# Need for Speed: Underground 2 + NFS Online

Underground 2 is the LAN's open-city street racer. This skill installs the
user-supplied 1.2 game tree from the portable LAN archive and adds the
community NFS-Online client, so the dead EA Online menu becomes a working LAN
lobby. There is no native Linux build; Linux runs the Windows game through
Wine or Proton.

## When to use

Use this skill when the user says any of:

- "install NFSU2", "install NFS Underground 2", "install NFS U2"
- "set up Underground 2 for the LAN"
- "install the NFS-Online mod for Underground 2"
- "why is NFSU2 online dead?" (install the client and point it at the server)

Do not use it for Most Wanted (2005) or Carbon: NFS-Online supports all three,
but each game has its own archive, ASI plugin and `net_*.ini`.

## What you need

- The portable game archive `nfsu2-portable*.zip` on the LAN file host. It is
  a ready-to-copy 1.2 tree with `SPEED2.EXE`, `CARS/`, `TRACKS/`, `GLOBAL/` and
  usually `dinput8.dll` at the root plus `scripts/`. The user supplies the
  game; no disc and no retail installer are needed.
- Optional but expected if the base is older: the 1.2 patch ZIP
  (`nfsu2*patch*1.2*`). A portable base that already reports 1.2 needs none.
- The NFS-Online client from https://github.com/moio9/NFS-Online-Server:
  `net_u2.asi` and `net_u2.ini`. The server package's
  `clients/underground2/net_u2.ini` is the reference config; build the ASI
  from `source/client-zig` or take the release asset. Client 0.2.22 belongs to
  server 1.1.3.
- The Ultimate ASI Loader as `dinput8.dll` (x86). Many portable trees already
  carry it; if not, take it from the loader release. Do not rename it.
- About 3 GB of disk. On Linux a 32-bit Wine prefix is enough; Proton is an
  optional fallback.
- The LAN server address. The server host runs bootstrap TCP 20921, lobby TCP
  20922, web/PREL TCP 20923 and the race relay on UDP 20000. The current LAN
  host is `10.23.3.220`; use the address the Orga gives you, never `localhost`
  on a player PC.

## Steps

1. **Pick the install folder.** Use a short writable path:
   `C:\Games\NFSU2` on Windows or `~/Games/nfsu2` on Linux. Avoid
   `Program Files`; the game writes `SAVE/` next to `SPEED2.EXE`.
2. **Unpack the base.** Extract `nfsu2-portable*.zip` into `{{target}}`.
   The archive has one wrapper folder (`Need for Speed - Underground 2/`);
   move its contents up so `SPEED2.EXE` and `scripts/` sit directly in
   `{{target}}`. On Linux `bsdtar -xf` is enough, no extra unzip package.
3. **Apply the 1.2 patch only if needed.** If the base is older than 1.2,
   extract the optional patch ZIP over `{{target}}`, merging. Do not keep two
   `SPEED2.EXE` copies in nested folders.
4. **Install the ASI client.** Ensure `{{target}}/scripts/net_u2.asi` exists.
   If the base does not carry it, copy the matched `net_u2.asi` there.
5. **Configure the server.** Edit `{{target}}/scripts/net_u2.ini` and set the
   same host in `[network]` and `[lan]`:
   `host = 10.23.3.220` (or the Orga's address). Keep
   `inject_server = true`, `patches = true` and `enabled = true`. The client
   talks to the LAN server; it never needs an EA account.
6. **Check the ASI loader.** `dinput8.dll` must sit next to `SPEED2.EXE` at the
   game root. On Linux the launch command must set
   `WINEDLLOVERRIDES=dinput8=n,b`; without it Wine ignores the ASI plugin.
7. **Smoke test.** Start the game and confirm the NFS-Online menu replaces the
   EA Online entry. Open the LAN lobby and check that the directory lists the
   server. If it stays empty, the host line or the server ports are wrong.

## Starting the game

- **Windows:** `Start-Process -FilePath "{{target}}/SPEED2.EXE"
-WorkingDirectory "{{target}}"`, or simply `SPEED2.EXE`.
- **Linux, Wine (default):**
  `WINEDLLOVERRIDES="dinput8=n,b" wine "{{target}}/SPEED2.EXE"`.
- **Linux, Proton (optional):**
  `WINEDLLOVERRIDES="dinput8=n,b" proton run "{{target}}/SPEED2.EXE"`.
  Use it only when the machine already has a `proton` command (Steam/Proton
  install); the recipe's default Linux target is Wine.

The launcher's native install path unpacks the base, applies an optional
patch, copies `net_u2.asi`/`net_u2.ini` into `scripts/` and verifies
`SPEED2.EXE`, `scripts/net_u2.asi`, `scripts/net_u2.ini` and `dinput8.dll`
from the plan's `file_exists` checks. Everything else in this skill is for the agent.

## Verification

- `SPEED2.EXE` (matched case-insensitively), `scripts/net_u2.asi`,
  `scripts/net_u2.ini` and `dinput8.dll` exist in the install folder.
- The NFS-Online menu appears instead of EA Online, and the LAN lobby lists
  the server.
- On Linux the game starts through Wine with `dinput8=n,b`; a launch without
  the override stays offline even when the files are present.

## Troubleshooting

- **EA Online is still shown:** `net_u2.asi` is missing, in the wrong folder,
  or `dinput8.dll` is not next to `SPEED2.EXE`. Put the ASI in `scripts/`.
- **Wine ignores the plugin:** start with
  `WINEDLLOVERRIDES=dinput8=n,b`. Check that the prefix is 32-bit-capable and
  that `dinput8.dll` is the x86 build.
- **Empty lobby:** both `host =` lines in `net_u2.ini` must name the LAN
  server; check TCP 20921-20923 and UDP 20000 between client and server.
- **Game starts but crashes after the intro:** remove an older NFS-Online
  plugin and rebuild/recopy `net_u2.asi` from the matching server release.
- **Wrong version / missing cars:** the base was not 1.2; apply the 1.2 patch
  over the flattened tree and start again.
- **Proton command not found:** use the Wine target; Proton is only a
  fallback for machines that already expose a `proton` command.

## License

Need for Speed Underground 2 belongs to EA; the game files are user-supplied.
NFS-Online is AGPL-3.0-or-later. The Ultimate ASI Loader is MIT.
