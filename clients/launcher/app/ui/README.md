# LANoMAT Launcher UI

Static HTML/CSS/ES modules for the Tauri v2 shell in `clients/launcher/app`. No framework, no
build step, no runtime dependency: `index.html` + `app.css` + `js/*.js` are loaded straight from
`frontendDist: "./ui"`.

The UI is German, follows the Signalpult design system (`docs/design.md`), and only renders what
the shell returns. It never fabricates progress, files, versions or server state.

## Files

| File | Job |
|------|-----|
| `index.html` | all four screens, the global shell, the game-card template |
| `app.css` | Signalpult tokens (two tiers) + component styles; no raw hex below the token block |
| `js/main.js` | boot, header, connectivity banner, the tiny stage machine, deep-link routing |
| `js/state.js` | the one store and `showScreen()` |
| `js/nav.js` | keyboard + gamepad navigation and the footer's gamepad indicator |
| `js/connect.js` | device flow: URL, QR, code, poll states, reconnect/logout entry |
| `js/chat.js` | the Start page (main actions + the live-server list with one-click join) and the arcade front desk: greeting, deterministic `games`/`servers` intents, skippable demo, game/server cards |
| `js/library.js` | `get_status` + `list_games`: „Meine Bibliothek" from the local install ledger, the shared catalogue with one primary action per card, `verify_install` record state and reinstall/repair |
| `js/install.js` | `install-progress` listener, phases, bytes/speed/ETA, cancel, errors |
| `js/ready.js` | launch, repair, target path, server browser |
| `js/qr.js` | dependency-free QR encoder (byte mode, ECC M/L) for the phone sign-in |
| `js/util.js` | invoke/listen wrappers, German number formatting, error classification |
| `js/strings.js` | every visible string, German, in one place |
| `fonts/` | Space Grotesk + JetBrains Mono (woff2) and their licences, copied from the voice client |
| `logo-mark.svg` | the node-L mark, shared with the voice client |
| `check-ui.mjs` | dependency-free contract check (ids, strings, commands, tokens, CSP) |

## Shell contract (L4, `clients/launcher/app/src/lib.rs`)

Every call goes through `window.__TAURI__.core.invoke`; the progress event comes through
`window.__TAURI__.event.listen`. `withGlobalTauri` is on, so no `@tauri-apps/api` import is needed.

| UI action | Command | Args | Result used by the UI |
|-----------|---------|------|-----------------------|
| boot, library refresh | `get_status` | – | `{paired, url, user, server, installs[]}` |
| boot, paired | `take_pending_deep_link` | – | `{action, slug}` or `null` (once) |
| Verbinden | `connect_start` | `{server_url}` | DeviceView `{user_code, verification_uri, verification_uri_complete, expires_in, interval, qr_payload}` — `qr_payload` is the link the QR encodes |
| poll while waiting | `connect_poll` | – | `{state: pending, remaining}` / `{state: slow_down, interval}` / `{state: connected, user, server}` / `{state: failed, message}` |
| Escape / gamepad B | `leave_fullscreen` | – | `true` when the fullscreen was the step back; `false` in kiosk mode or when the window is not fullscreen |
| gamepad hot-plug | `set_gamepad` | `{connected}` | mirrors the footer indicator into the terminal heartbeat |
| Abmelden | `logout` | – | local only: deletes the pairing, the server token is revoked on `/mcp/connect` |
| library | `list_games` | – | `RecipeSummary[]` (`slug,title,summary,version,status,platforms,installable,launcher_supported`), the installable pilot first, the rest of the catalogue after it |
| chat → „Welche Server laufen?“ | `list_servers` | – | `{events[], servers[]}`: the aggregated event + permanent server projection, no player names |
| Installieren | `install` | `{slug}` | `InstallRecord` `{slug, target_dir, version, verified[]}` (promise) |
| progress | event `install-progress` | – | `{slug, phase, message, file?, bytes_done, bytes_total, percent?, bytes_per_second, eta_secs?}` |
| Abbrechen | `cancel_install` | – | – |
| record state / `Reparieren` | `verify_install` | `{slug}` | `{ok, exists, missing[]}` — a local check of the recorded target **ohne etwas herunterzuladen**; `exists: false` means the folder was moved or deleted, `missing[]` names requirement groups (alternatives joined by `or`) |
| Starten | `launch` | `{slug, join?}` | executable path; `join` is the `host:port` UT2004 starts on |
| Browser öffnen / Serverbrowser / Spielseite | `open_url` | `{url}` | – |
| header + footer (decoration) | `get_environment` | – | `{version, commit, host, arcade, kiosk}` — `host` is this terminal's name, `arcade`/`kiosk` the start flags |
| footer (update watch) | `check_launcher_update` | – | `{available, latest, page}` or `null` |
| deep link while running | event `deep-link` | – | `{action: "install"\|"launch", slug}` |

`install` rejects with a plain string (`cancelled`, `missing LAN files: …`, `the recipe has no base
file …`, …). The UI maps the known shapes to German and shows the raw string only for unknown ones
rather than guessing or repeating it.

`get_plan` exists in the shell but the library screen does not call it: the pilot shows the
published summary and the install record only.

### Command mismatch with L4 / the wave brief

The wave brief for L5 names `start_device`; the merged L4 shell exposes `connect_start` (there is
no `start_device` command). The brief also calls cancel simply `cancel`; L4 exposes
`cancel_install`. This UI uses the names L4 actually registers:

- `connect_start`, not `start_device`
- `cancel_install`, not `cancel`
- `get_status` (the brief's "status"), `list_games`, `install`, `launch`, `open_url`, `logout`

Deep links (`lanomat://install/<slug>`, `lanomat://launch/<slug>`,
`lanomat://join/<slug>?address=host:port` or `?url=<encoded join URL>`) arrive as
the `deep-link` event with `{action, slug, address?, url?}`. The UI routes them
only while paired:

- `install`: refresh `list_games`, find the recipe, then `install.begin(recipe, {join})`. A slug
  the server does not publish gets a calm toast and the library.
- `launch`: check the install records (refreshing `get_status` once if needed), then
  `ready.show(slug, {launch: true, join})`. Without a record the toast says so and the library opens.
- `join`: the website's one-click action. An installed recipe goes straight to
  `ready.show(..., {launch: true, join})`; a missing installable recipe runs the install wizard and
  auto-launches on the same server afterwards; a browser/an older launcher falls back to the
  server's allowlisted join URL. The address is passed to UT2004's executable, the URL is opened
  after a generic launch.
- while an install runs, every link keeps the install screen (progress, file, cancel stay
  visible) and answers with the `S.deepLink.busy` toast instead of switching screens.
- unpaired: the UI stores nothing, shows a "zuerst verbinden" toast, and lets the shell replay
  its pending link after pairing (the shell emits `deep-link` again at that point).

On boot, after a successful `get_status` while paired, the UI calls `take_pending_deep_link` once
and routes the result through the same function. An older shell without the command is ignored.
The shell validates the slug (`[a-z0-9-]{1,120}`) before it ever reaches the UI.

## Screens and states

One stage at a time: `boot → connect → chat → library → install → ready`. `chat` is the home
once paired — the **Start** page with the main actions and the live-server list, plus the
arcade chat below it; `library` is one big game tile for the UT2004 pilot; `install` returns
to `library` on error/cancel and to `ready` on success.

- **Connect**: editable server URL (default `https://lan.raute1.org`), `Verbinden` → a large,
  central QR of `verification_uri_complete` with the label `Mit Handy scannen`, plus the code,
  verification link and `Browser öffnen` as the fallback. The phone signs in with the player's
  own account and this terminal becomes their session. States: pending with a mono countdown,
  slow-down notice, expired, denied, failed (raw shell message), network (keeps polling until the
  code expires). `Abbrechen` stops the flow; `Serveradresse ändern` returns to the form;
  `Abmelden` in the header appears only while paired and disconnects just this machine:
  it deletes the local pairing, while the bearer token stays valid on the server until it
  is revoked there on `/mcp/connect` (the button's tooltip and description say so). The next
  player starts on the same QR screen.
- **Start (the screen after pairing)**: the main actions `Spiele`, `Serverbrowser` and `Chat`,
  then **Jetzt live** — every `ready`/`not_responding` server from `list_servers`, one row per
  server. The row's primary action is `Beitreten`: a missing installable recipe runs the install
  wizard and auto-launches on that server; an installed recipe launches on it immediately;
  every other game opens its own allowlisted join URL (steam/ut2004/https) or the server page.
  Loading, empty, error and normal states are explicit, and the list loads on the first show
  instead of waiting for a chat question. Below it sits the chat: greeting with the player's
  name, a skippable demo (typing/backspacing a suggestion, instant under `prefers-reduced-motion`),
  suggestion chips and a text input. Intents are deterministic and local: `games` asks
  `list_games` + `get_status`, `servers` asks `list_servers`. Answers are cards, not prose: the
  game tile with `Installieren`/`Starten`, and one aggregated server grid across every
  active/recent event plus the permanent servers. `Neu starten` clears the transcript,
  `Zu den Spielen` skips straight to the library.
- **Library**: two lists in one screen. „Meine Bibliothek" is the local install ledger from
  `get_status.installs` — one card per installed game with title, `Ordner` (folder, mono, quiet),
  verified file count and one big `Starten` that launches the local executable (no network, so it
  works with the server switched off). It is painted before the catalogue is judged, so an
  offline server never hides what is already installed. „Spiele vom Server" is the shared
  catalogue from `list_games`: every published recipe, the installable pilot as the hero tile and
  the rest below. A card with `launcher_supported: true` gets `Installieren` (or `Starten` once a
  record exists); every other card says `Bald im Launcher` and links to the game's
  `<paired server>/games/{slug}` page through `open_url` instead of showing a dead install
  button. `installable: false` (or an absent flag on an older server) never hides the card.
  A record is **never a dead end**: next to `Starten` both the local tile and the catalogue card
  keep a quiet `Neu installieren` / `Reparieren`. The label comes from `verify_install`, which
  checks the recorded target on disk before either list is painted: `ok` labels the record
  `bereit`, a present but incomplete target says `Reparatur nötig` and offers `Reparieren`, a
  moved/deleted folder says `Installationsordner fehlt` and offers `Neu installieren`. The
  action re-runs the same `install` command (download resumes, extraction is idempotent) and
  lands in the normal install → ready flow. An older shell without `verify_install` leaves the
  check unknown: the record keeps the quiet `Neu installieren` and nothing is downloaded.
  Loading skeletons, empty, error and content as before; `Chat` returns to the front desk.
- **Install**: six phases (Planen, Herunterladen, Entpacken, Patchen, Prüfen, Fertig), current
  file, one large `bytes_done / bytes_total` bar with a big percent, speed, ETA. `Abbrechen`
  calls `cancel_install`. The promise rejection and the `error` phase both settle the screen;
  `cancelled` gets its own calm state. No fake progress: an unknown percent is indeterminate.
  The current step carries `aria-current="step"` and every step an `aria-label` with its state
  ("erledigt", "aktueller Schritt", "fehlgeschlagen", "ausstehend"), so the phase rail is not
  colour-only. A failure stops at the step it reached ("Gestoppt bei „Herunterladen“.").
  `begin()` settles in `try/finally`: a failing `event.listen` or `install` call surfaces as the
  normal error state and never leaves `starting` or a stale listener behind. A logout during a
  run keeps the connect screen (no Startklar without a paired server), and a deep link never
  replaces the running install. Error/cancel move focus to their retry action, `Starten` takes
  focus back after a launch.
- **Ready**: installed game, target folder, optional version, `Starten`, a quiet `Reparieren`
  (re-runs `install`, so a moved folder or an incomplete `System/` is fixed without leaving the
  screen), `Serverbrowser` (`<paired server>/servers`, hidden while the paired server has no
  http(s) base), `Zur Bibliothek`. When `launch` fails because the target is gone or
  `System/` is incomplete, the button becomes the clear, focused `Installation reparieren` and
  takes the primary role; every other launch error stays a plain error with the repair still
  available. Empty when no record.
- **Footer**: the running build (`get_environment`, mono, decorative for support reports) and,
  when the server publishes a newer launcher build, one link to the launcher page
  (`check_launcher_update` + `open_url`). Both are best-effort: a failing command leaves the
  footer quiet, never a screen error.
- **Header**: `Dieses Gerät: <host>` from `get_environment.host` (mono, tooltip explains the
  rules below). Visible before pairing too, so a room full of launchers can be told apart.

## Terminals, installs and the shared catalogue

Every terminal pairs **on its own** (its own device code, its own bearer token, its own entry on
the server's approved-devices list), installs **locally** (`installs.json` + the game folder on
that machine) and only **shares the event catalogue** the orga publishes on the server. That is
why the header names the machine, why `Starten` needs no server, and why the catalogue can be
empty or offline without touching the local library.

`launcher_supported` is the shell's verdict, not the server's: only `unreal-tournament-2004` has a
verified install engine today (`LAUNCHER_ENGINE_SLUG` in `src/lib.rs`). `installable` is the
server's answer about the recipe (requirements, steps and a launch target); `null`/absent means an
older server and stays "unknown", never "no". Once the engine is generic, the flag follows the
plan's verify steps and no UI change is needed.

`Starten` on the library card opens Ready and launches immediately, so the button's label is
true. Ready's own `Starten` repeats the launch. `Reparieren` and `Neu installieren` never touch
another slug's record: `installs::save` replaces only this game's entry and keeps the rest of
`installs.json`, so a repair on one terminal leaves every other install alone.

Every screen ships loading, empty (where a list can be empty), error and normal. Network failures
also raise the global offline banner with a retry that re-runs the current screen's action; it
disappears on the next successful command.

## Arcade terminals (`--arcade`, `--kiosk`)

A cabinet is the same binary with `--arcade` (or `LANOMAT_ARCADE=1`). The flag is parsed once in
`main.rs` (`ArcadeMode::detect`), reaches the window through `get_environment` and the shell puts
the window into fullscreen in `setup` (`arcade::enter`, idempotent: an already-fullscreen restart
does not flicker). `set_fullscreen` needs the Tauri window permission
`core:window:allow-set-fullscreen` in `capabilities/default.json`; `core:default` alone does not
grant it. The same file grants `core:window:allow-is-fullscreen` and
`core:window:allow-set-always-on-top` (the kiosk keeps its window in front).

- The UI adds one class, `html.arcade`: larger type, a larger QR, no footer build info. The
  connect screen leads with the QR — **Mit Handy scannen** — then the short code and the link.
  `connect_start` returns `qr_payload`, the exact `verification_uri_complete` the server built
  (or the verification address plus `?code=`), so the scanned link and the printed code cannot
  differ. The phone opens `/mcp/connect?code=…`, approves, and the existing poll pairs the
  terminal.
- **Abmelden / Nächstes Gerät**: the same local-only `logout` as on the desk. It clears this
  machine's pairing, keeps the install records and shows the QR again for the next guest.
- **Keyboard and gamepad**: `js/nav.js` polls `navigator.getGamepads()` (no dependency; a
  controller plugged in later is picked up). D-pad or left stick moves the focus through the
  header and the current screen, A/Start activates, B/Escape goes back. Escape leaves the
  fullscreen first — only when the mode is not kiosk (`leave_fullscreen` refuses in kiosk and the
  UI says why). The arrow keys move the same focus; Tab, Enter and the native form flow stay.
- The footer shows the gamepad indicator (`Gamepad verbunden` / `Gamepad getrennt`). The same
  change goes to `set_gamepad`, so the orga monitor's cabinet row matches the window.

## Design and accessibility

- Signalpult tokens only: tier 1 primitives are defined once inside
  `/* signalpult:primitives:start */ … /* signalpult:primitives:end */`; components use the
  semantic roles (`--primary`, `--live`, `--ok`, `--warn`, `--down`, `--muted-foreground`, …).
  Dark/light follows `prefers-color-scheme`. Two extra roles carry status **text**
  (`--ok-text`, `--destructive-text`) with AA shades; `--ok`/`--destructive` stay for borders,
  dots and fills, where the fill value is enough.
- Space Grotesk for prose, JetBrains Mono for machine data (code, URL, bytes, speed, ETA, paths,
  versions).
- The arcade sign-in QR is drawn locally by `js/qr.js` (no remote image, no external request) as
  dark modules on a light field in both schemes, so a phone camera can read it; the code and
  verification link stay visible as the fallback.
- One signal-amber primary per state: `Verbinden`/`Browser öffnen`, `Installieren`/`Starten`,
  `Starten`, `Erneut versuchen`. Everything else is neutral.
- Visible `:focus-visible` ring, real `<button>`/`<form>`/`<a>` elements, headings focused after a
  stage change, `aria-live="polite"` for stage messages and `role="progressbar"` with
  `aria-valuenow`/`aria-valuetext` only when a percent is known. The connect countdown is
  `aria-hidden` (it ticks every second) and only the wait text is a `role="status"`.
- One calm toast (`#toast`, `role="status"`, auto-hides) carries hints that belong to no screen,
  e.g. a deep link that needs pairing; its status role announces it. The header's who dot mirrors
  the same signal as the offline banner (`ok` while the server answers, `down` while it is gone).
- `prefers-reduced-motion: reduce` removes pulse, slide and width easing.
- Responsive down to 520 px (the shell's `minWidth`); stats and phases fold to two columns.
- No `<style>` blocks, no inline handlers, no external requests: CSP is `default-src 'self'` plus
  the Tauri IPC origin. Fonts, mark and scripts are all local.

## Check

```bash
node clients/launcher/app/ui/check-ui.mjs
```

The script has no dependencies. It verifies the files, every required element id, the German
strings, the exact command and event names above, the token block + no-hex rule, the accessibility
hooks (live region, progressbar, labels, focus/reduced-motion CSS), local-only assets, and that
every `js/*.js` import resolves. Exit code 0 is the L5 proof; it is the static review gate, the
real click-through stays with the L11 shell smoke.

Opening `index.html` directly in a browser shows the connect screen with a calm error
("Diese Oberfläche braucht den LANoMAT Launcher") because the IPC bridge is absent — intentional,
not a blank page.
