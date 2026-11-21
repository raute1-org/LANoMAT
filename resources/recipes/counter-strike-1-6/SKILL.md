---
name: counter-strike-1-6
description: 'Install Counter-Strike 1.6 on a LAN PC from Half-Life (app 70) and the cstrike archive or Steam (app 10), configure rate and controls, join a LAN server with steam://connect, optionally host HLDS, and verify a round. Use when the user says install CS 1.6, cs16, Counter-Strike 1.6 or cstrike for the LAN.'
---

# Counter-Strike 1.6

The GoldSrc tactical shooter, still the default LAN tournament game. CS 1.6 is
a Half-Life mod: a `cstrike` folder inside a Half-Life base, with Steam app 10
as the easy path.

## When to use

- "install CS 1.6 for me"
- "set up Counter-Strike on this PC"
- "join the LAN CS server"
- "host a CS 1.6 server for the tournament"
- "cs16, cstrike, counter strike 1.6"

Not for Counter-Strike 2 (Source 2, app 730), Condition Zero or Source.

## What you need

- A Half-Life base (Steam app 70, the GOTY CD, or the LAN's `Half-Life`
  folder) **or** Steam app 10 on its own: Counter-Strike brings its own
  Half-Life base and `cstrike/`.
- A `cstrike` archive only for a manual install over a separate Half-Life base.
- About 4 GB of disk, a keyboard and a mouse.
- A LAN server address, normally `<host>:27015` from the LANoMAT event page.

## Steps

1. **Install the base.** `steam://install/10` is enough: app 10 installs its
   own Half-Life base and `cstrike/`. Use `steam://install/70` plus the GOTY
   ISO or a LAN copy only for the separate Half-Life game/manual path.
   `{{target}}` is the Half-Life folder with `hl.exe` and `valve/`.
2. **Add cstrike.** Skip with app 10. For a manual install, extract
   `cstrike.zip` into `{{target}}` so that
   `{{target}}/cstrike/liblist.gam` exists.
3. **Configure.** Set resolution, `rate 25000`, `cl_updaterate 101`,
   `cl_cmdrate 101`, `fps_max 100`, mouse acceleration off. Put LAN-wide
   settings in `cstrike/autoexec.cfg`.
4. **Join.** Open `steam://connect/<host>:27015` from the event page, or run
   `connect <host>:27015` in the console (`~`).
5. **Host (optional).** Install HLDS (app 90) and start it with
   `hlds.exe -game cstrike +map de_dust2 +maxplayers 16 +port 27015`.
6. **Verify.** Play a round, buy, check the scoreboard, change the map and
   reconnect.

## Server join

- `steam://connect/<host>:27015` starts the Steam edition and connects in one
  step; LANoMAT's event page builds the link.
- Console: `connect <host>:27015`, then `password <pw>` for a protected
  tournament server (or `connect <host>:27015 <pw>`).
- `retry` after a map change or a dropped connection.
- The portable install has no Steam link: start `hl.exe -game cstrike`, then
  use the console.

## Tournament and LFG

- Tournaments use 5v5 with `mp_timelimit`, `mp_roundtime` and
  `sv_password` from the LANoMAT match config; load it before the first
  round.
- A public server can use 16 or 32 slots; keep `mp_autoteambalance 1` and
  `mp_limitteams 2` for drop-in play.
- LFG groups can join the same server from the event page without a second
  install; the server address is the only thing they need.
- Confirm the map list and the warmup settings with the orga before the
  tournament starts.

## Verification

- `hl.exe` (or `hl`) and `cstrike/liblist.gam` exist.
- The game reaches the main menu and the server browser shows the LAN server.
- A full round works: buy menu, weapons, bomb plant/defuse, scoreboard.
- `steam://connect` from a second PC joins the same server.
- A map change reconnects with `retry`.

## Troubleshooting

- **"Could not load library" / missing models**: the `cstrike` folder is from
  a different build than the base; use one complete 1.6 copy.
- **Server not listed**: connect by address (`connect <host>:27015`); LAN
  discovery is unreliable across subnets and firewalls.
- **Choppy movement on a fast LAN**: set `rate 25000`, `cl_updaterate 101`
  and `cl_cmdrate 101`, and turn off `cl_smooth`.
- **`steam://connect` opens the wrong game**: Steam may associate the link
  with CS2; use the console `connect` as the fallback.
- **Password rejected**: the tournament config set `sv_password`; get the
  current value from the event page.
- **Port blocked**: the host must allow UDP 27015 inbound.

## Status

Draft recipe, needs verification on the LAN hardware. The Steam path, the
portable LAN copy, the `steam://connect` hand-off and the HLDS tournament
config still need a real-venue run.

## License

Counter-Strike and Half-Life belong to Valve. The base game and the `cstrike`
files are user-supplied; no recipe redistributes them.
