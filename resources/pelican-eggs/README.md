# LANoMAT Pelican eggs

Pelican eggs LANoMAT ships and keeps in the repository, so a venue panel can run
the game server presets without hand-editing eggs. The files are Pelican egg
exports in `PLCN_v3` YAML. The panel matches an egg by its `uuid`, which is also
how LANoMAT presets reference it (numeric egg ids differ per panel).

| File | Egg | uuid |
| ---- | --- | ---- |
| `lanomat-garrys-mod.yaml` | Garrys Mod (LANoMAT) | `7b079b4f-ab83-40ef-98fc-059b4a270688` |

## Import

```bash
php artisan gameservers:import-eggs          # import or update every egg (idempotent, upsert by uuid)
php artisan gameservers:import-eggs --check  # only verify that the eggs exist on the panel
```

The command uses the Application API (`POST /api/application/eggs/import`), which
needs **write scope on Eggs**. With a read-only key it prints the manual steps and
still verifies through the egg list: in the panel admin area open **Eggs > Import
from file** and choose the YAML file, then run the command with `--check`.
Re-importing is safe: an egg with the same uuid is overwritten, other eggs
(including the stock "Garrys Mod") are never touched.

## Garrys Mod (LANoMAT)

A fork of the stock egg (`gmod/egg-garrys-mod.yaml` in
`pelican-eggs/games-steamcmd`) with its own uuid, so importing it never replaces
the stock egg, and without the upstream `update_url`, so a panel-side egg update
cannot overwrite it with the upstream version.

Differences to upstream:

- New variables: `SERVER_HOSTNAME` (the server browser name, passed as
  `+hostname`), `STEAM_WEB_API_KEY` (optional, `-authkey` for Workshop
  collections, hidden from server users and not editable by them) and
  `EXTRA_ARGS` (optional extra launch arguments). The name `HOSTNAME` is avoided
  on purpose: Docker sets it in every container and Pelican reserves it.
- Optional arguments are guarded: `+host_workshop_collection`, `-authkey` and
  `+sv_setsteamaccount` are only passed when their variable is set (an empty stock
  variable renders `+sv_setsteamaccount ` and the engine complains).
- The default `server.cfg` has no `hostname` line, because `server.cfg` runs at
  map load and would override the startup hostname.
- The install script retries SteamCMD up to three times and exits with an error
  when `garrysmod/` is missing, so a failed SteamCMD run (the "Missing
  configuration" cold-start flake) is reported as a failed install instead of a
  successful one. LANoMAT additionally verifies the install through the file
  list and reinstalls once.

Note: `-authkey` appears on the startup command line and therefore in the server
console output. Only give console access to people who may see the key.

## License

The upstream egg repository `pelican-eggs/games-steamcmd` is MIT licensed:

> MIT License
>
> Copyright (c) 2024 Michael Parker and Contributors
>
> Permission is hereby granted, free of charge, to any person obtaining a copy of this
> software and associated documentation files (the "Software"), to deal in the Software
> without restriction, including without limitation the rights to use, copy, modify, merge,
> publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons
> to whom the Software is furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all copies or
> substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
> INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR
> PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE
> FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
> OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
> DEALINGS IN THE SOFTWARE.

The fork keeps this notice as a header comment in the egg file.
