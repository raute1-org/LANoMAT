# teamspeak-admin

A minimal ServerQuery-REST sidecar for the TeamSpeak server, mirroring
[`docker/mumble-admin`](../mumble-admin)'s REST contract 1:1 so
`HttpTeamSpeakClient` and `HttpMumbleClient` are interchangeable behind the
`VoiceClient` interface (see `app/Modules/Voice/HttpTeamSpeakClient.php`).

> **// M8-infra-later:** this image is config + docs only this phase (mode
> A) — it has not been built or run against a real TeamSpeak server. See
> `docs/teamspeak-setup.md` for the "unverified until real hardware" note and
> the plan to real-verify it.

## Why a purpose-built sidecar instead of a ServerQuery library

The obvious PHP-side ServerQuery library, `planetteamspeak/ts3-php-framework`,
declares PHP 8.1/8.2/8.3 only (latest release 1.3.0) and would break
`composer install` on this project's PHP 8.4. Rather than add an
unmaintained/uncertain Python ServerQuery wrapper on this side either, the
sidecar speaks ServerQuery directly: it is a small, line-based text protocol
over a plain TCP socket, so a purpose-built client (`_ServerQueryConnection`
in `app.py`) is a couple dozen lines — the same "own minimal sidecar over an
unmaintained/uncertain third-party dependency" call the M3 mumble-admin
sidecar made against `murmur-rest`.

## REST contract

Identical to `docker/mumble-admin`:

| Method | Path              | Body                                | Response                                              |
| ------ | ----------------- | ------------------------------------ | ------------------------------------------------------ |
| GET    | `/channels`       | –                                     | `[{id,name,parent,temporary,occupants}, ...]`           |
| POST   | `/channels`       | `{name, parent, temporary}`           | `{id,name,parent,temporary,occupants}` (201)            |
| PATCH  | `/channels/{id}`  | `{name}`                              | `{id,name,parent,temporary,occupants}`                  |
| DELETE | `/channels/{id}`  | –                                     | 204                                                      |
| GET    | `/healthz`        | –                                     | `{"status": "ok"}`                                      |

`parent: 0` means "root channel", matching TeamSpeak's own `pid=0` for
top-level channels. `occupants` is the number of clients currently connected
to that channel (from `clientlist`), defaulting to `0`.

All routes except `/healthz` require `Authorization: Bearer <TEAMSPEAK_ADMIN_TOKEN>`.

## Environment variables

| Variable                     | Default        | Meaning                                                              |
| ----------------------------- | -------------- | ---------------------------------------------------------------------- |
| `TEAMSPEAK_HOST`               | `teamspeak`    | Hostname of the TeamSpeak server (compose service name in prod)         |
| `TEAMSPEAK_QUERY_PORT`         | `10011`        | ServerQuery raw TCP port (TeamSpeak's own default)                      |
| `TEAMSPEAK_QUERY_USER`         | `serveradmin`  | ServerQuery login name                                                   |
| `TEAMSPEAK_QUERY_PASSWORD`     | *(required)*   | ServerQuery login password                                              |
| `TEAMSPEAK_SERVER_ID`          | `1`            | Virtual server ID this sidecar manages (`use sid=...`)                  |
| `TEAMSPEAK_ADMIN_TOKEN`        | *(required)*   | Bearer token this sidecar's own REST API requires                       |
| `TEAMSPEAK_QUERY_TIMEOUT`      | `10`            | Socket timeout (seconds) for the ServerQuery connection                |

## Setting up the ServerQuery query-account

1. On first boot, the official `teamspeak` image prints the `serveradmin`
   account's auto-generated password to its container logs (`docker compose
   logs teamspeak`) — capture it once and store it as
   `TEAMSPEAK_QUERY_PASSWORD`. Alternatively, connect once with a ServerQuery
   client and run `serverqueryreload` / create a dedicated query-login via
   `login serveradmin <password>` then `clientaddserverquerylogin` for a
   scoped, non-`serveradmin` account.
2. Prefer a dedicated, minimally-privileged query login over reusing
   `serveradmin` in the long run — grant it the `b_virtualserver_modify`,
   `i_channel_*`, and `b_client_info_view` permissions it needs for
   channel CRUD + `clientlist`, nothing more.
3. **ServerQuery IP whitelist:** the official image's `query_ip_allowlist.txt`
   (mounted or baked in) must include the `teamspeak-admin` container's
   address — on the shared compose network this is simplest as the Docker
   bridge subnet (or the specific service IP), never `0.0.0.0/0` in
   production. See `docs/teamspeak-setup.md` for the compose-level detail.
4. ServerQuery has an anti-flood ban: repeated failed logins (e.g. a
   misconfigured `TEAMSPEAK_QUERY_PASSWORD`) will temporarily ban the
   sidecar's IP (`error id=3331`, mapped to a 502 in `app.py`). If you hit
   this while testing manually, use `query_ip_allowlist.txt`'s companion
   `query_ip_denylist.txt` reset process documented by TeamSpeak, or wait out
   the ban.

## Local manual testing (once real-verified)

```bash
curl -H "Authorization: Bearer $TEAMSPEAK_ADMIN_TOKEN" http://localhost:<port>/channels
```
