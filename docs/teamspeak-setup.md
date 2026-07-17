# TeamSpeak — ServerQuery-REST sidecar setup (M8, Voice-Multiprovider)

> **Mode A — unverified until real hardware.** Everything below (the
> `docker/teamspeak-admin` sidecar, the `teamspeak`/`teamspeak-admin`
> compose services, the ServerQuery command mapping) is code + config + docs
> written this phase, but has **not** been run against a real TeamSpeak
> server. `HttpTeamSpeakClient` itself is fully implemented and tested (via
> `Http::fake()`, never a real sidecar), but the sidecar side of the contract
> needs a real-verify pass on real hardware before relying on it in
> production — see the checklist at the end of this document.

TeamSpeak is the second voice backend `VoiceClient` supports alongside
Mumble (`app/Modules/Voice/HttpMumbleClient.php`). Both providers can run
**simultaneously**; a team picks its preferred one, and channels are
mirrored on every active provider (see the M8 plan,
`docs/superpowers/plans/2026-07-17-m8-voice-multiprovider.md`).

## Why a sidecar instead of a PHP ServerQuery library

`planetteamspeak/ts3-php-framework` (the standard PHP ServerQuery client)
declares PHP 8.1/8.2/8.3 only (latest release 1.3.0) and would break
`composer install` on this project's PHP 8.4. Per the M8 plan's binding
constraint, LANoMAT does **not** add it. Instead, `docker/teamspeak-admin/`
is a small, purpose-built FastAPI sidecar that speaks TeamSpeak's
ServerQuery protocol directly (a simple line-based text protocol over a TCP
socket) and exposes the exact same REST contract as the existing
`docker/mumble-admin` sidecar. `HttpTeamSpeakClient` talks to this sidecar
over plain HTTP, so the PHP side stays dependency-free and fully
`Http::fake()`-testable — the same shape of decision M3 made for Mumble
(`murmur-rest` rejected as unmaintained → own `mumble-admin` sidecar).

## REST contract (identical to mumble-admin)

| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/channels` | – | `[{id,name,parent,temporary,occupants}, ...]` |
| POST | `/channels` | `{name, parent, temporary}` | `{id,name,parent,temporary,occupants}` (201) |
| PATCH | `/channels/{id}` | `{name}` | `{id,name,parent,temporary,occupants}` |
| DELETE | `/channels/{id}` | – | 204 |
| GET | `/healthz` | – | `{"status": "ok"}` |

All routes except `/healthz` require `Authorization: Bearer <TEAMSPEAK_ADMIN_TOKEN>`.
`parent: 0` means "root channel" (`HttpTeamSpeakClient` normalizes this to
`null` on the PHP side, same as Mumble's `parent: 0`). `occupants` is the
number of clients currently connected to that channel.

See `docker/teamspeak-admin/README.md` for the full environment-variable
reference and the ServerQuery command mapping (`channelcreate`/
`channeledit`/`channeldelete`/`channellist`/`clientlist`).

## 1. Compose services (prod profile only)

Unlike `mumble`/`mumble-admin` (always-on shared infra since M3), the
`teamspeak`/`teamspeak-admin` services in `compose.yml` are `profiles:
[prod]`-only — this keeps the mode-A, not-yet-real-verified sidecar out of
the default dev stack (`docker compose up -d`, no profile) entirely, so
nothing changes for day-to-day dev work until this is real-verified.

```bash
docker compose --profile prod up -d teamspeak teamspeak-admin
```

Ports (non-default host ports, per the project's compose convention):

| Service | Host port | Purpose |
|---|---|---|
| `teamspeak` | `9989/udp` | Voice (TeamSpeak's own default is `9987`; shifted to avoid a local collision, same reasoning as Mumble's non-default ports) |
| `teamspeak` | `30033/tcp` | File transfer (TeamSpeak's own default, kept as-is) |
| `teamspeak-admin` | `127.0.0.1:8086` | ServerQuery-REST sidecar, loopback-only (mirrors `mumble-admin`'s `8085`) |

ServerQuery itself (port `10011`) is **not** published to the host — only
`teamspeak-admin`, on the same compose network, needs it, exactly like
Mumble's Ice port (`6502`) above it in `compose.yml`.

## 2. `.env` configuration

See `.env.example`'s TeamSpeak section for the full set with inline
comments. Summary:

| Variable | Meaning |
|---|---|
| `TEAMSPEAK_QUERY_PASSWORD` | ServerQuery query-account password (see below) |
| `TEAMSPEAK_ADMIN_TOKEN` | Bearer token the `teamspeak-admin` sidecar's REST API requires |
| `TEAMSPEAK_HOST` | Hostname the Laravel app resolves for user-facing links (`localhost` in dev) |
| `TEAMSPEAK_PORT` | Voice port for `ts3server://` join links |
| `TEAMSPEAK_ADMIN_REST_URL` | Where `HttpTeamSpeakClient` reaches the sidecar (`http://teamspeak-admin:8000` inside compose) |

These feed `config('services.teamspeak.*')` (`config/services.php`), which
`VoiceProviders` (`app/Modules/Voice/VoiceProviders.php`) uses to construct
`HttpTeamSpeakClient` when `teamspeak` is listed in `VOICE_PROVIDERS`.

## 3. Creating the ServerQuery query-account

1. On first boot, the official `teamspeak` image logs the auto-generated
   `serveradmin` ServerQuery password to its container output:
   ```bash
   docker compose --profile prod logs teamspeak | grep -i serveradmin
   ```
   Capture this once as `TEAMSPEAK_QUERY_PASSWORD`.
2. For production, prefer a dedicated, minimally-scoped query login over
   reusing `serveradmin` long-term: connect once via any ServerQuery client
   (or `nc`/`telnet` to port `10011`), `login serveradmin <password>`, `use
   sid=1`, then `clientaddserverquerylogin client_login_name=lanomat` to
   create a scoped account and use that name/password instead.
3. **IP allowlisting:** the official image's ServerQuery allowlist
   (`query_ip_allowlist.txt`) must permit the `teamspeak-admin` container's
   address on the compose network — never open ServerQuery to `0.0.0.0/0` in
   production. On the shared Docker bridge network this is typically the
   compose subnet; pin it down further once real-verified against the
   actual bridge CIDR in use.
4. Anti-flood: repeated failed ServerQuery logins temporarily ban the
   caller's IP (ServerQuery `error id=3331`, mapped by the sidecar to an
   HTTP 502). If `TEAMSPEAK_QUERY_PASSWORD` is wrong while testing, expect a
   short ban before retries succeed again.

## 4. Admin token

`TEAMSPEAK_ADMIN_TOKEN` is a LAN-internal shared secret between the Laravel
app and the sidecar — generate any sufficiently random string (e.g. `openssl
rand -hex 32`) and set the same value for both `TEAMSPEAK_ADMIN_TOKEN`
(consumed by `HttpTeamSpeakClient` via `config('services.teamspeak.token')`)
and the `teamspeak-admin` compose service's own `TEAMSPEAK_ADMIN_TOKEN` env.
Unlike Mumble (where `MUMBLE_ADMIN_TOKEN` defaults to `MUMBLE_ICE_SECRET`),
TeamSpeak's admin token and ServerQuery password are two independently
generated secrets — there is no equivalent "same secret gates both layers"
shortcut for ServerQuery.

## 5. Real-verify checklist (before production use)

This phase (M8, mode A) stops at code + config + docs. Before relying on
TeamSpeak in production, verify on real hardware:

- [ ] Build the sidecar image (`docker compose --profile prod build
      teamspeak-admin`) and confirm it starts cleanly against a running
      `teamspeak` service.
- [ ] Exercise all four REST routes (`GET/POST /channels`,
      `PATCH/DELETE /channels/{id}`) against the live sidecar and confirm the
      JSON shape and status codes match this doc / `docker/mumble-admin`'s
      contract exactly.
- [ ] Confirm `occupants` reflects real connected-client counts (join with a
      real TeamSpeak client, re-`GET /channels`, verify the count changes).
- [ ] Confirm the `channel_flag_temporary` mapping on `POST /channels`
      actually produces the intended auto-cleanup behavior (verify against
      TeamSpeak's own semantics for temporary channels — a session-owned
      channel that disappears once empty and the creating ServerQuery
      session disconnects; this may need a different flag combination than
      what `app.py` currently sets, once tested for real).
- [ ] Confirm the ServerQuery IP allowlist / anti-flood behavior under the
      actual compose network's bridge subnet.
- [ ] Load-test the "one ServerQuery session per HTTP call" design
      (`_ServerQueryConnection` in `docker/teamspeak-admin/app.py`) under the
      expected concurrent provisioning load (whole-bracket channel fan-out,
      M8 Task 8.4) — if this proves too slow, consider a pooled/long-lived
      ServerQuery connection instead.

## Relationship to Mumble

Both providers implement the same `VoiceClient` interface
(`app/Modules/Voice/Contracts/VoiceClient.php`) and are resolved via
`VoiceProviders` (`app/Modules/Voice/VoiceProviders.php`) keyed by
`VoiceProvider::Mumble` / `VoiceProvider::TeamSpeak`. Nothing downstream
(provisioning jobs, join links, Discord embeds) needs to know which backend
it's talking to — see the M8 plan for how the mirrored-provisioning
architecture uses this.
