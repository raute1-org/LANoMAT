# Game server join options (UT2004)

A server can offer several ways to join. Each option has a key, a label, a URL and a short hint.
They are not stored: `App\Modules\GameServers\Support\JoinLinkTemplates` builds them from the
server's validated `join_info` (address and port) and the game's slug, and the server lists
(`/servers` with `?event=<slug>`, `/servers/all`) and the match page carry them as `joinOptions`.
`/events/{event}/servers` is kept alive as a redirect into the one overview. Servers of games
without templates have an empty list and behave as before (plain `connectString`).

## Unreal Tournament 2004

The game is recognised by its slug (`unreal-tournament-2004`, also `ut2004`, `ut2k4`,
`unreal-tournament-2k4`); register the permanent server under a game with one of these slugs.

| Key      | URL                                   | Needs                                                                   |
| -------- | ------------------------------------- | ----------------------------------------------------------------------- |
| `steam`  | `steam://run/13230//<address>:<port>` | UT2004 in the player's Steam library (App ID 13230)                     |
| `native` | `ut2004://<address>:<port>`           | A registered `ut2004://` URL handler (set up by the original Setup.exe) |

**Status: untested against a real installation.** Neither form has been tried with a real UT2004.
The Steam form relies on Steam passing everything after `//` to `UT2004.exe` as arguments and on
UT2004 accepting a server address as its first argument. The native form relies on the handler
being registered; a game folder copied by hand (the LAN recipe) normally has none. The UI says so
and always offers "Adresse kopieren" (`address:port`) as the fallback.

## The `ut2004://` handler

The dialog "ut2004:// einrichten" (link "Hilfe" next to the Nativ option) works fully in the
browser:

- Windows: a downloadable `.reg` file writing only `HKCU\Software\Classes\ut2004` with
  `shell\open\command` = `"<path to UT2004.exe>" "%1"`.
- Linux: a downloadable `ut2004-handler.desktop` plus the two commands
  `xdg-mime default ut2004-handler.desktop x-scheme-handler/ut2004` and
  `update-desktop-database ~/.local/share/applications`.

The path is validated strictly (absolute, no control, quote or `..` segments). The pure functions
live in `resources/js/lib/ut2004Handler.ts`. The recipe `unreal-tournament-2004` has the same as
two optional steps (`register-link-handler`, `register-link-handler-linux`).

## Adding another game

Add an entry to `JoinLinkTemplates::TEMPLATES` (slugs plus `{hostport}` URL templates) and the
labels under `gameservers.join_options.<game>.<key>` in `lang/de/gameservers.php`. Only the schemes
in `JoinLinkTemplates::ALLOWED_SCHEMES` are produced, and the UI follows the same allowlist.
