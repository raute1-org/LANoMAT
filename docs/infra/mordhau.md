# Mordhau dedicated server

Mordhau runs as a Pelican server with the community egg and three seeded presets
(`horde`, `deathmatch`, `frontline`). This page covers setup, joining, and the
quirks that cost time during the first live run. All hosts and addresses are
placeholders.

## Setup

1. Import the egg (shipped as `resources/pelican-eggs/mordhau.json`, see the
   [egg README](../../resources/pelican-eggs/README.md) for source and licence):

    ```bash
    php artisan gameservers:import-eggs
    ```

2. Seed the game and its presets (never overwrites presets that already exist):

    ```bash
    php artisan db:seed --class=GamesSeeder
    ```

3. Create the server in the admin panel (permanent servers) or from an event, pick
   the game "Mordhau" and one of the presets. SteamCMD downloads the server
   (Steam app 629800, anonymous login), which takes a few minutes on first start.

## Presets

| Preset       | Mode                      | Default map     |
| ------------ | ------------------------- | --------------- |
| `horde`      | Horde (co-op against AI)  | `Grad/HRD_Grad` |
| `deathmatch` | Free-for-all              | `Grad/FFA_Grad` |
| `frontline`  | Frontline (control zones) | `Grad/FL_Grad`  |

Common values: 32 slots, 4096 MB RAM (the per-instance LANoMAT limit), 30720 MB
disk, 400 % CPU, no readiness query (the game has no usable Source query, the
server counts as ready once it runs), join address `{ip}:{port}`.
`SERVER_NAME`, `DEFAULT_MAP` and `MAX_PLAYERS` can be overridden per server.
Server names are limited to 40 characters by the egg.

### Maps

The map value is `Folder/Map`; the prefix of the map name selects the mode:
`FFA_` deathmatch, `TDM_` team deathmatch, `SKM_` skirmish, `FL_` frontline,
`INV_` invasion, `SG_` siege, `HRD_` horde.

Horde maps from the game files:

| Value                       | Value                       |
| --------------------------- | --------------------------- |
| `Arid/HRD_Arid`             | `MaxMap/HRD_MountainPeak`   |
| `Castello/HRD_Castello`     | `RiverForest/HRD_Totenwald` |
| `Crossroads/HRD_Crossroads` | `TaigaMap/HRD_Taiga`        |
| `DesertMap/HRD_Noria`       | `ThePit/HRD_ThePit`         |
| `DuelCamp/HRD_Camp`         | `Grad/HRD_Grad` (default)   |
| `Dungeon/HRD_Dungeon`       | `FeitoriaMap/HRD_Feitoria`  |

## Joining

In Mordhau open the console (key `~` or `^`) and enter `open <GAME_HOST>:<PORT>`,
with the address LANoMAT shows for the server (main port 17777 by default).
There is no usable server browser entry on a LAN.

## Fixed ports

Mordhau registers itself at PlayFab per IP and port. After a restart the old
registration blocks the same address for about two minutes: the log shows
`RegisterGameServer: Duplicate server`, and players are kicked with "Timed out
while waiting for the session ticket". A stable address makes the wait
predictable, and fixed ports outside the automatic pool (27015-27050) never
collide with pool ports handed to other servers.

The presets use the new preset field **fixed ports** (admin game form, section
"Ports, Bereitschaft und Verbindung"): `main` 17777, `QUERY_PORT` 17778,
`BEACON_PORT` 17779. Not 7777: UT2004 uses 7777 on the same host. Starting the
server makes sure the three allocations exist on the node (missing ones are created,
which needs an API key with write scope on allocations) and are free. A second
server with a Mordhau preset at the same time is refused with the message that a
fixed port is already assigned, because both would need the same ports; stop or
delete the first one.

## Why the server needs internet

Players authenticate through Microsoft PlayFab, and the server registers itself
there on start. There is no offline mode. A LAN party with Mordhau therefore needs
a working internet uplink on the game host and on every player's PC. If the uplink
fails the server keeps running but players cannot join.

## Restart pause

Do not restart the server in quick succession: after every restart with the same
port the PlayFab registration is blocked for roughly two minutes (see above).
Wait before the next start or join attempt.

## AI opponents occupy slots

In Horde mode the AI opponents count against `MaxSlots`. A server with `MaxSlots=6`
reported "Server is full" with only two real players. The presets therefore default
to 32 slots; lower it only after testing with the real number of opponents.

## Admin password

`ADMIN_PASSWORD` is a variable in the Pelican panel (server startup settings), at
most 20 characters. It is not part of the preset and never stored by LANoMAT. Use it
in game through the console (`rcon`-style admin login as described in the Mordhau
documentation).

## Resources and limits

- RAM: 4096 MB, which is the LANoMAT per-instance cap (`PELICAN_MAX_RAM_MB`).
- Horde difficulty with 32 slots is untested; adjust `MAX_PLAYERS` per server when
  needed.
- Only the three modes above are seeded; further modes are a map change through the
  `DEFAULT_MAP` override.

## Linking an already running server to a preset

A server that was created by hand keeps working. To manage it with a preset, link it
to the Mordhau game and set its preset key to `horde` (or another) in the admin
panel; no recreation is needed because the preset only matters at creation time and
for the join text.
