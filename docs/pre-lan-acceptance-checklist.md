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

> To stand up real hosts for these checks, follow
> [`three-server-setup.md`](three-server-setup.md) first.
>
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

- **Preflight ampel (1.2) — built.** `php artisan lanomat:preflight` probes
  every internal check (db/redis/storage/`failed_jobs`/Reverb/scheduler/queue
  worker) plus the external systems (Discord API, voice sidecars, Pelican,
  Music Assistant) and prints an ampel table, exiting non-zero if any check is
  `down`. The same results back a Filament dashboard tile
  (`PreflightStatusWidget`) in `/admin`. The scheduled `lanomat:health-watch`
  command (every 5 min) rings the orga bell, edge-triggered, when a system
  goes `down` or `failed_jobs > 0` (previously these vanished silently).
  Scheduler/queue liveness is fed by `lanomat:heartbeat` (every minute, writes
  a cache tick + dispatches a queue heartbeat job) — run it once for real
  (`php artisan schedule:work` or the `scheduler` compose service) before
  relying on the ampel's scheduler/queue rows.
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

- **Double-elimination field size — done.** The bracket engine now supports
  double elimination for any field from 2 to 64 entrants, matching single
  elimination. The per-size intake-ordering table was replaced by a computed
  search (`LbIntakeOrdering`); non-power-of-two fields are padded with byes,
  which surface in the bracket as "Freilos" (and, for a match that can never
  be played because both its feeder slots are dead byes, "Entfällt"). Above
  size 16 a small, provably unavoidable number of first-losers-bracket-match
  rematches remains — see the counting argument in `LbIntakeOrdering`'s class
  docblock. Property-tested at 11 discrete sizes across the 2..64 range (4, 5,
  6, 7, 8, 16, 17, 23, 31, 32, 64), not every size in it.
- **Gallery "retract a photo":** an orga can already delete any photo
  (`EventPhotoPolicy::delete` = owner||orga) via the participant gallery.
  Confirm the Filament moderation queue also offers a one-click way to remove
  an **already-approved** photo (someone unwillingly in frame); add an
  un-approve/delete action there if it's missing.

## 10. "Wo ist wer" — Standort der Teilnehmer (Presence)

Der Standort (Sprachraum, Spiel) der Teilnehmer, den der native Client unter „Leute" zeigt und dem man mit einem
Klick folgen kann. Alles ist gegen Fakes getestet; echt prüfen:
[`infra/native-voice-client-api.md`](infra/native-voice-client-api.md), Abschnitt „People".

- [ ] `presence:refresh-locations` läuft im Scheduler (alle 30 Sekunden, solange ein Event live ist).
- [ ] **Mumble und TeamSpeak:** die Sidecars neu bauen (sie liefern jetzt `occupant_names`). Zwei Testkonten verbinden
      sich über den Beitrittslink aus dem Client (mit ihrem LANoMAT-Namen): sie erscheinen mit dem richtigen Raum. Ein
      Konto mit anderem Namen erscheint **nicht** (lieber unbekannt als geraten).
- [ ] **Discord:** ein Konto im Sprachkanal erscheint mit Kanalname; ein Discord-Konto ohne LANoMAT-Konto taucht nirgends auf.
- [ ] **LANoMAT-Webvoice:** wer im Raum ist, erscheint sofort beim nächsten Refresh (LiveKit-Teilnehmerliste).
- [ ] **Steam:** mit gesetztem `STEAM_API_KEY` und einem verknüpften Konto (Profil mit sichtbarem Spielstatus) erscheint das
      laufende Spiel; auf einem LANoMAT-Server zusätzlich mit Server und Beitrittslink. Ein privates Profil bleibt unbekannt.
- [ ] **Minecraft:** der Spielername steht in der Spielerliste des Servers (Status-Beispiel, höchstens 12 Namen) und ist
      eindeutig einem Konto zuzuordnen; er erscheint mit dem Server.
- [ ] **Datenschutz:** „Meinen Standort teilen" in den Profil-Einstellungen aus: die anderen sehen die Person nicht mehr
      (sofort), man selbst sieht sich weiterhin. Folgen auf diese Person ist dasselbe „nicht gefunden" wie bei einer unbekannten Nummer.
- [ ] **Folgen:** Mumble-/TeamSpeak-Link öffnet den Client im richtigen Raum mit dem eigenen Namen; Discord-Link öffnet den
      Kanal; Webvoice verbindet über den Raumtoken; ein Match-Raum eines fremden Teams ist nicht beitretbar.

## 11. Launcher — one-click UT2004 install (fresh PC)

The LANoMAT Launcher is the no-terminal path for participants: one download from `/launcher`, one
pairing code, one **Installieren** button for Unreal Tournament 2004. Verify it on hardware that has
never seen this LAN. Requires `MODULE_GAME_RECIPES=true`, `MODULE_FILES=true` and `MODULE_MCP=true`
(the launcher pairs and plans through the bearer API); the page reads
`clients-dist/launcher/manifest.json` (see the deploy runbook's launcher section and
[`docs-site/pages/guides/launcher.mdx`](../docs-site/pages/guides/launcher.mdx)).

- [ ] `/launcher` lists **both builds** — Linux x86_64 tarball and Windows x86_64 portable exe — each
      with size and SHA-256; the page requires the Discord login like the rest of the participant UI.
- [ ] **Fresh-PC run:** on a PC with no LANoMAT login, start the launcher, pair with the code shown in
      the browser, install UT2004 from the LAN and press **Starten** — the game reaches the main menu
      without internet and without administrator rights.
- [ ] **Manifest SHA256 matches:** in `clients-dist/launcher`, `sha256sum -c SHA256SUMS` passes and each
      value equals the hash printed by the script and shown on `/launcher`; a missing requirement makes
      the launcher name the file (e.g. "UT2004 DVD 1") instead of failing silently.
- [ ] **Two terminals, two devices:** pair a second machine at the same time; both appear under
      **Verbundene Geräte** on `/mcp/connect` as separate rows, each with its own token, and revoking
      one leaves the other connected. The shared catalogue is identical on both, while an install
      exists only on the machine that ran it.
- [ ] **Arcade terminal by QR:** in kiosk/fullscreen mode the launcher shows a QR of
      `/mcp/connect?code=…` plus the code as text; scan it with a phone, sign in and approve — the
      terminal stores its own device token and shows **Verbunden mit &lt;Server&gt; · &lt;Name&gt;**.
      The QR alone does not sign anyone in.
- [ ] **Handover:** **Abmelden / Nächstes Gerät** on the arcade terminal clears the session and
      pairing for the next player; the installed files stay, so the next scan reaches the library
      without a second download. The same account works on every terminal.
- [ ] **Per-terminal monitoring:** `/admin/launcher-terminals` shows **online**, **aktuelles Spiel**
      and **zuletzt gesehen** for each terminal, and the pairing itself is listed under
      **Verbundene Geräte** on `/mcp/connect`; a powered-off machine goes dark without a manual
      refresh.
- [ ] **Offline library:** stop the LANoMAT app (leave the file host reachable or not) — a terminal with
      a verified UT2004 install still offers **Starten**, and the catalogue area says the server is
      offline instead of pretending to be empty.

## 12. Sharing a game, installing it, and the recipe pull request

The end-to-end gaming journey was built against fakes and a fake repository
(`fakeRecipeRepository()`); nothing here was run on real hardware or against the real GitHub API.
Prerequisites: `MODULE_GAME_RECIPES=true` with `Files` and `Mcp` (hard dependencies), a second PC with
a browser on the LAN, and — for the last two checks — `RECIPES_PR_ENABLED`, `RECIPES_PR_REPOSITORY` and
`RECIPES_PR_TOKEN` pointing at the real repository
([`infra/recipe-pull-requests.md`](infra/recipe-pull-requests.md)).

- [ ] **Share from a second PC:** upload a real ready-to-run archive (`.zip` or `.tar.bz2`) on the files
      page, press **"Als Spiel teilen"**, describe it (title, start file `bin/Game.exe` or similar,
      platforms) and submit. The draft appears under **"Deine geteilten Spiele"** with
      **Datei** "wartet auf Freigabe" and **Prüfung** "Die Orga prüft dein Spiel"; `/admin/recipes`
      lists the row and the navigation badge counts it.
- [ ] **Approve and publish in one step:** on the recipe's edit page press
      **"Dateien freigeben und veröffentlichen"**. One notification, the file is "freigegeben", the
      status is `published`, **"In der Spieleliste zeigen"** is on, and the game appears on `/games`
      and in the launcher catalogue **without** a `LAUNCHER_LINEUP` change or a config-cache clear.
- [ ] **Install with the launcher on a fresh PC:** pair the launcher on a machine that has never seen
      this LAN, press **Installieren**, and let it download from the LAN file host; **Starten** reaches
      the game without internet. A shared game counts as installable only when the launcher's generic
      engine can unpack-and-launch it (a required base archive, a file check, launch targets); otherwise
      **Installieren** on the game page is a manual install and the share page is still correct.
- [ ] **Join through the server browser:** a server whose game matches the published recipe shows
      **"Im Launcher beitreten"**; the click installs if needed and starts the game on that exact
      server (UT2004 passes `?address=<host>:<port>`, other games open the server's join URL). The
      row's own **Verbinden** and the **"Anders verbinden"** disclosure stay usable.
- [ ] **Launcher-link notice on a PC without the launcher:** click a `lanomat://` action there. After
      about 2.5 s the notice asks **"Kein Launcher-Fenster aufgegangen?"**, explains the likely reason,
      and offers **"Launcher herunterladen"** and **"Nochmal versuchen"**; it claims no failure and uses
      no amber. On a PC with the launcher, no notice appears.
- [ ] **Pull request against the real repository:** `php artisan lanomat:recipes:propose <slug>` (or a
      share with `RECIPES_PR_TRIGGER=contribute`) opens one pull request on `recipes/<slug>` with
      `recipe.yaml`, `SKILL.md` and the `_catalog.md` row; the **Recipes** workflow is green. A second
      proposal updates the same pull request instead of opening another one.
- [ ] **Merge:** merge in GitHub, then `php artisan lanomat:recipes:pr-status` (or wait for the hourly
      run): the state becomes **"Im Katalog"**, and the share page's **Katalog** step links to the
      pull request.
- [ ] **Public-safety gate:** put an e-mail address or a private IP into a summary, propose again. The
      state is **"Nicht gesendet"**, the panel shows a redacted reason (`line …, an e-mail address
      jo[REDACTED len=…]`), and GitHub saw no new commit. Fix the text and re-propose.
- [ ] **After the merge:** deploy (the image ships `resources/recipes`), or run
      `php artisan lanomat:recipes:sync resources/recipes --only=<slug> --json` on the running instance:
      the recipe is adopted, and the moderation status, the file pins, `in_lineup` and the `pr_*`
      columns survive.

---

*Source notes distilled from: the per-phase "Vertagt auf reale Infra" sections
of `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (M6/M7/M8/M11),
the setup docs linked above, and JB's Feature-Input Runde 3 (2026-07-21).*
