# Pre-LAN Acceptance Checklist (real-infra "Generalprobe")

**Purpose.** Everything in LANoMAT that talks to the outside world was built
in **Mode A** — real code + config + docs, tested against **fakes**, with the
real-infra verification deliberately deferred (agreed with the user, per the
roadmap's per-phase "Vertagt auf reale Infra" notes). Those verification
points are correct but **scattered** across setup docs and roadmap sections.
This is the **single list** to work through — one system at a time, a quiet
afternoon before November — so the LAN day is *arriving*, not *debugging*.

**How to use.** Each system below has: prerequisites, the concrete checks
(checkboxes), and a link to its detailed setup doc. Tick a box only after you
have seen the real behaviour, not the faked one. Nothing here blocks
day-to-day dev — the app + fakes are complete without it.

> Related robustness work that is **not yet built** (own follow-ups, not
> "verify" items) is indexed in [§8](#8-not-yet-built-robustness-gaps) so this
> stays the one place to look.

---

## 1. Discord — Gateway bot (new)

The REST path + HTTP interactions were already exercised live (see
[`docs/prod-test.md`](prod-test.md)). The **Gateway sidecar** (spec
`docs/superpowers/specs/2026-07-21-discord-gateway-bot-design.md`, plan
`docs/superpowers/plans/2026-07-21-discord-gateway-bot.md`) is new and needs a
first real run.

**Prerequisites (manual, in the Discord Developer Portal):**
- [ ] Enable the **Server Members Intent** (`GUILD_MEMBERS`, privileged) — do this early.
- [ ] Set `DISCORD_GATEWAY_BRIDGE_SECRET` in the prod `.env`.

**Checks:**
- [ ] `docker compose --profile prod up -d --build discord-gateway`; logs show `logged in as <bot#tag>` → the **bot shows online** in the guild.
- [ ] Clear the portal **Interactions Endpoint URL** → invoke `/help` and `/tournament bracket` in the guild; the deferred follow-up appears (interactions now arrive over the Gateway).
- [ ] Join/leave a voice channel → `GET /discord/voice` reflects it (mapped names only, No-PII).
- [ ] **Fallback drill (per JB):** stop `discord-gateway`, re-set the Interactions Endpoint URL → slash commands work again over HTTP. Keep the HTTP endpoint code until this fallback has been proven once. (Plan Task 2 keeps the endpoint dormant, not deleted.)
- [ ] Sidecar health is reachable for the preflight probe (§8, 1.2).

## 2. Pelican + Wings — game servers (M6-T13)

Built entirely against `FakePelicanClient`; **no real Pelican was ever
called.** The app only speaks Pelican's REST API. See the M6 plan
`docs/superpowers/plans/2026-07-16-m6-gameserver-stats.md` (Task 13) and
`docs/architecture.md`.

**Prerequisites:** a running Pelican Panel + a Wings node; `PELICAN_*` env
(base URL, API key) set.

**Checks:**
- [ ] Confirm **Application vs Client API** auth against the current Pelican docs — Pelican differs from its Pterodactyl ancestor; do **not** assume Pterodactyl payloads. Verify the exact auth header/scope `HttpPelicanClient` uses.
- [ ] Verify the **server-create payload**, the **power-action endpoint**, and the **egg/allocation model** shapes against a real panel; adjust `HttpPelicanClient` at the single wire chokepoint if they differ.
- [ ] **Egg spike:** provision a real CS1.6 / UT2004 server end-to-end from a match becoming ready; fill in the real **egg IDs** and decide **one-click vs. manual** per game.
- [ ] Confirm cleanup on `TournamentCompleted` actually stops/removes the server.
- [ ] Write `docs/pelican-setup.md` from the findings (still missing) and extend `docs/prod-test.md`.

## 3. Voice — Mumble (M3/M8)

The Ice-REST sidecar (`docker/mumble-admin`) was built in M3; the app talks to
it via `MumbleClient`. Real occupancy numbers are Mode A (0 in dev).

**Prerequisites:** the `mumble` + `mumble-admin` compose services running with
a real `MUMBLE_ICE_SECRET`.

**Checks:**
- [ ] Bring up `mumble` + `mumble-admin`; a tournament/team/match channel is actually **created** on the real server (not just faked).
- [ ] Join the channel with a Mumble client → real **occupancy count** shows on the live bracket page (cached 5 s, gated to active providers).
- [ ] `mumble://` join links on the match page + Discord embed open the right channel.

## 4. Voice — TeamSpeak (M8)

**Mode A — never run.** The ServerQuery-REST sidecar
(`docker/teamspeak-admin`, FastAPI) + its **ServerQuery command mapping**
(`channelcreate`/… ) is code + config + docs only. See
[`docs/teamspeak-setup.md`](teamspeak-setup.md).

**Prerequisites:** the `teamspeak` + `teamspeak-admin` services (both
`profiles:[prod]`) running; `TEAMSPEAK_QUERY_PASSWORD` + `TEAMSPEAK_ADMIN_TOKEN` set.

**Checks:**
- [ ] Build + start the sidecar; it authenticates to ServerQuery (port 10011, loopback-only) and answers on `127.0.0.1:8086`.
- [ ] Verify the **ServerQuery command mapping**: channel create/delete + occupancy read produce the right effects on a real TeamSpeak server.
- [ ] Occupancy count flows through to the app (same active()-gate + cache as Mumble).

## 5. Music Assistant — jukebox (M11)

**Mode A — never run against a real MA server.** Two things need confirming:
the **wire format** and the **"sync-implies-play" assumption**. `HttpMusicClient`
is the single wire chokepoint. See
[`docs/music-assistant-setup.md`](music-assistant-setup.md) §5.

**Prerequisites:** a real MA server + a configured player (e.g. Snapcast) wired
to the PA; `services.music_assistant.base_url` (`:8095`) + bearer token set.

**Checks:**
- [ ] Confirm the **JSON-RPC envelope** (`{"command":…, "args":…}` body vs. path-segment style) matches `HttpMusicClient::command()`.
- [ ] **sync-implies-play:** call `syncQueue()` with a fresh ordered URI list → the player **actually starts audio** of the first URI (not silent enqueue). If not, add an explicit `resume`/play follow-up to the promotion path in `JukeboxTickCommand`.
- [ ] Confirm `player_queues/move_item` (`pos_shift`/`pos_target`) reorders as `syncQueue()` assumes, against `:8095/api-docs`.
- [ ] Confirm `player_queues/items` response (`current_item`, `media_item`, `elapsed_time`, `state`) matches `nowPlaying()` parsing.
- [ ] Decide whether the `everyMinute` `lanomat:jukebox-tick` cadence feels responsive enough, or shorten it.

## 6. Traefik + TLS (M7-T8)

Config parses and is documented; a **real ACME certificate against a real
public domain** was deferred. See [`docs/traefik-setup.md`](traefik-setup.md).

**Prerequisites:** a real domain pointing at the host (public DNS) for ACME,
or accept the self-signed fallback for a pure-LAN deployment.

**Checks:**
- [ ] `docker compose --profile prod config` parses; `traefik.yml` + `dynamic.yml` load without error.
- [ ] With `APP_DOMAIN` + `ACME_EMAIL` set, Traefik obtains a real **Let's Encrypt cert** for `app` (and `ws.` for reverb) — **or** the `websecure` self-signed default is accepted for LAN-only.
- [ ] This replaces the `cloudflared`/`ngrok` tunnel workaround in `docs/prod-test.md` — `APP_URL`/`APP_DOMAIN` point at the real domain, Discord's HTTPS requirement met without a tunnel.

## 7. Remote hosts: Registry, LanCache, custom Docker servers (M7)

All three are `RemoteHost`s driven only via the `RemoteExecutor` contract
(phpseclib SSH2/SFTP + fake); **no real SSH ran in tests.**

**Prerequisites:** the real hosts (IP + SSH key), registered in the app with
their keys encrypted.

**Checks:**
- [ ] **TOFU (do this first for every host):** run the host once so its SSH host key is **pinned** before any command authenticates against it (documented safeguard).
- [ ] **Registry** ([`docs/registry-setup.md`](registry-setup.md)): a real `v*`-tag CI run **pushes** the image to the registry, and a real prod **pull** succeeds.
- [ ] **LanCache** ([`docs/lancache-setup.md`](lancache-setup.md)): SSH bootstrap runs on the **separate** LanCache host; then **verify DNS resolution from a client machine** (not just the LanCache host) — game domains resolve to the LanCache, Steam re-downloads hit LAN speed after the first client.
- [ ] **Custom Docker game servers** (M7.4): a custom server provisions + starts via `RemoteExecutor` on a real host.
- [ ] Real **Playwright capture run** of the M7 Files/LanCache UI screenshots (deferred with the pipeline).

## 8. Not-yet-built robustness gaps (indexed here on purpose)

These are **follow-ups to build**, not "verify" items — listed so this file is
the one place to look (from JB's Runde-3 input, 2026-07-21):

- **Preflight ampel (1.2):** `php artisan lanomat:preflight` probing every
  external system (Discord API, voice sidecars, Pelican, Music Assistant,
  Reverb, queue worker alive, scheduler ticking, storage writable,
  `failed_jobs` empty) → a status tile in `/admin`. Bonus: a scheduler check
  that raises an orga bell when `failed_jobs > 0` (today they vanish
  silently). *(Own spec.)*
- **Backup + tested restore (1.3):** `pg_dump` via scheduler (hourly during a
  live event) + the storage dir, **additive/timestamped**, and — crucially —
  a **rehearsed restore** with a spot-check hash. An unrehearsed backup is
  only hope. *(Own spec.)*
- **Offline safety (1.4, low priority):** an orga-issued **one-time login QR**
  (reuse the QR-ticket pattern) as insurance for Discord-only login when the
  internet is down + a countdown-page hint "log in beforehand on your own
  device"; and a **local music source** in Music Assistant so the room keeps
  playing without Spotify.
- **Release watcher (1.5):** a weekly upstream-version check for the base
  projects (discord.js, Pelican, Music Assistant, Mumble, TeamSpeak).

## 9. Minor items to confirm (not real-infra)

- **Double-elimination field size:** the bracket engine supports double-elim
  for n ∈ {2, 4, 6, 8, 16} only. If fields **larger than 16** are expected,
  pull forward the already-noted 32-player extension; otherwise leave as-is
  deliberately.
- **Gallery "retract a photo":** an orga can already delete any photo
  (`EventPhotoPolicy::delete` = owner||orga) via the participant gallery.
  Confirm the Filament moderation queue also offers a one-click way to remove
  an **already-approved** photo (someone unwillingly in frame); add an
  un-approve/delete action there if it's missing.

---

*Source notes distilled from: the per-phase "Vertagt auf reale Infra" sections
of `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (M6/M7/M8/M11),
the setup docs linked above, and JB's Feature-Input Runde 3 (2026-07-21).*
