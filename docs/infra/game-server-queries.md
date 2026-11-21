# Game server status queries and the "who is on it" list

Permanent servers (`ServerLink` with `query_protocol`) are asked about their state once a minute
by `gameservers:refresh-permanent` (`PermanentServerRefresher`). The answer lands in
`query_info` (name, map, game, `players`, `max_players`, `bots`, optional `player_list`) and
drives the status (`ready`, `not_responding`, `offline`) and the `players/max` display.

| `query_protocol` | Protocol | Port asked | Player list |
| --- | --- | --- | --- |
| `a2s` | Source A2S_INFO + A2S_PLAYER (UDP) | game port | names, score, connected time |
| `minecraft` | Server List Ping (TCP) | game port | the status sample the server volunteers (names only) |
| `ut2004` | UT2004 query, types 0 (info) and 2 (players) (UDP) | **game port + 1** | names, score |

UT2004: the query port offset lives in one place, `Ut2004Protocol::QUERY_PORT_OFFSET`; callers
and the stored join info always use the game port (for example `7777`, queried on `7778`). The
request is 5 bytes `80 00 00 00 <type>`. Set the protocol on an existing permanent server in the
admin panel (Dauerserver, "Statusabfrage" = "Unreal Tournament 2004") or via tinker
(`query_protocol = 'ut2004'`). The server must allow UDP `<game port + 1>` from the app host.

Queries time out after 3 seconds, validate every length and range, and never throw: a silent
server is treated like an A2S one (`not_responding` or `offline`, never an error page). The
player list is only requested when at least one player is on, and a missing or odd player answer
only means an empty list.

## Who is on it

`query_info.player_list` is a list of `{name, score, seconds}` (at most 64 entries, names cut to
32 characters, control characters and markup removed, best score first; UT2004 colour codes are
stripped). The names are **in-game nicknames**, not LANoMAT accounts, and are never linked to
one. The list only reaches logged-in viewers of `/servers`
(`ServerListProjection::dto(..., withPlayerList: true)`); the guest-safe projections, the
infoscreen, overlays, broadcasts and the native client API never include it.
