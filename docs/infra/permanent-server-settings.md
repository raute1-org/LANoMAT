# Settings page for permanent CS2 servers

The admin page **Server-Einstellungen** (`/admin/server-settings`, navigation group "Turniere und Teams")
lets orga and admins change the permanent CS2 servers (Competitive, Wingman, GunGame) without
opening the Pelican panel: general settings, power, a live map change and, for a GunGame server, the
plugin config. Orga may use the page; creating and removing permanent servers stays admin-only
(`ServerLinkPolicy::configurePermanent` and `configure` versus `managePermanent`).

## How an external server gets a Pelican link

The four servers were registered as `source=External` (A2S probe on `<GAMESERVER_HOST>:27015..27018`)
and have no `pelican_server_id`. They are not re-created. The link is stored on the existing entry:

- `pelican_server_id`: the numeric id of the server in the panel (Application API).
- `pelican_server_identifier`: its short identifier (Client API).

The entry **stays `source=External`**: the registered address and the A2S status probe keep working,
and removing the entry never deletes the panel server (only `source=Pelican` entries do that).
`ServerLink::hasPelicanLink()` is what unlocks settings, power, console and file access.

Adopt an existing entry once, from a shell on the app host:

```bash
php artisan gameservers:link-pelican "<TITLE OR ENTRY ID>" "<PELICAN ID | IDENTIFIER | UUID>" [--gungame]
```

Examples (titles as registered): `"CS2 5v5 Competitive"`, `"CS2 Wingman 1"`, `"CS2 Wingman 2"`,
`"CS2 GunGame" --gungame`. `--gungame` sets `preset_key=cs2-gungame`, which is what shows the plugin
form. Admins can also use the header action **Mit Pelican verknüpfen** on the page.

## Prerequisites on the panel

- The **Client API key's user must be able to see each server** (owner or subuser). The command warns
  when `GET /api/client` does not list the identifier. Otherwise power, console and files answer 404.
- The Application API key needs write access to **servers** (startup update).
- The egg must declare the variables `SERVER_NAME`, `SRCDS_MAP`, `GAME_TYPE`, `GAME_MODE`,
  `MAX_PLAYERS` and `SERVER_PASSWORD`. Variables the egg does not declare are not written, and the
  page names them after saving. `HOST_WORKSHOP_MAP` is optional: if the egg declares it, a workshop map
  is stored there and survives a restart, otherwise it is only applied live (`host_workshop_map`).

## What the page does

| Part             | Panel call                                                                                                                                                           |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Save             | Application API `GET` then `PATCH /servers/{id}/startup` with egg, image, startup command and the complete environment, six keys changed. Applies on the next start. |
| Save and restart | Same, then Client API `POST /servers/{identifier}/power` with `restart`.                                                                                             |
| Change map       | Client API `POST /servers/{identifier}/command`: `changelevel <map>` or `host_workshop_map <id>`.                                                                    |
| Restart round    | Client API `command`: `mp_restartgame 1`, or `gg_restart` on a GunGame server (`preset_key=cs2-gungame`). Needs a running server.                                    |
| Reload map       | Client API `command`: `changelevel <map>` with the map from the last A2S answer, only when it is on the allowlist.                                                   |
| Power            | Client API `power` with `start`, `stop`, `restart`; status from `/resources`.                                                                                        |
| GunGame          | Client API `files/contents` and `files/write` for `game/csgo/cfg/gungame/gungame.json` and `gungame_weapons.json`, then `gg_restart`.                                |

Rules:

- Modes map to `GAME_TYPE`/`GAME_MODE`: casual 0/0, competitive 0/1, wingman 0/2, arms race 1/0,
  demolition 1/1, deathmatch 1/2. The map is one of a fixed allowlist or a 6 to 12 digit workshop id.
- The password allows letters, digits, `-` and `_` up to 30 characters (the egg's rule). A blank field
  leaves it unchanged, "Passwort entfernen" clears it. A changed password is mirrored into the join info
  the participant page shows. It is never read back from the panel, shown, flashed or logged.
- Console commands are built only from fixed templates plus an allowlisted map or a digits-only id.
- GunGame files are edited, not regenerated: unknown keys and `_comment.*` entries are written back
  unchanged, blank fields leave a value alone, the weapon order is renumbered from 1, and a file that is
  not a JSON object is never overwritten. Files are written with two-space indentation.

## Checks after adopting

1. Open the page, pick a server: the form shows the panel's current values (no password).
2. Save a harmless change (for example max players), then "Speichern und neu starten" on a quiet server.
3. GunGame: change one level, save, confirm the plugin reloaded (`gg_restart`) or restart the server.

Open points: the value ranges of the GunGame fields and the egg's `HOST_WORKSHOP_MAP` variable are
assumptions until verified on the real server.

## Admin shortcuts on the participant page

The participant page `/servers` shows an "Admin" block on the row of every Pelican-linked permanent
server, but only to users who may `configure` it (orga and admin). The server decides this per server
(`canManage`) and sends the map list and the admin flags only then; everyone else gets neither. The block
offers "Runde neu starten", "Karte neu laden" (when the current map is known) and a map picker with an
optional workshop id, each behind a confirmation dialog. The routes are `POST /servers/{serverLink}/restart-round`,
`/reload-map` and `/map`; they run the same Actions as the settings page, so the policy and the allowlists
apply again. Result and errors come back as a toast.
