# LANoMAT native voice client (core slice)

A native desktop client for LANoMAT web voice, written in Rust. It joins the same LiveKit rooms as
the browser page (`/talk`), through the bearer API documented in
[`docs/infra/native-voice-client-api.md`](../../docs/infra/native-voice-client-api.md), and adds what
a browser cannot do: **global push-to-talk** (also on Wayland), a lower floor for audio latency and
a headless latency self-test. Server side, rooms and tuning background:
[`docs/infra/livekit-web-voice.md`](../../docs/infra/livekit-web-voice.md).

A cargo workspace with a UI-free core library, a command line binary and the Tauri v2 desktop app
(see "Desktop app" below).

| Crate | What |
|-------|------|
| `voice-core` | Library: API client (device-code pairing, bearer API v1), credential store, LiveKit session (publish, subscribe, mute), audio traits with `cpal` and synthetic implementations, push-to-talk control socket, latency maths and the two-session latency test. |
| `voice-cli` | Binary `lanomat-voice`: `login`, `rooms`, `join`, `ptt`, `latency-test`, `devices`, `logout`. |
| `app` | Binary `lanomat-voice-app`: Tauri v2 desktop shell with a plain static HTML/CSS/JS front end, tray icon, speaking overlay and the same control socket as the CLI. |

Versions used (Cargo.lock is committed): `livekit` 0.10.0 (with `libwebrtc` 0.3.55, which downloads a
prebuilt libwebrtc at build time), `livekit-api` 0.10.0 (token minting for the dev/latency tooling
only), `cpal` 0.18.2, `reqwest` 0.13 (rustls), `tokio` 1, `wiremock` 0.6 for tests.

## Build and test in the container

There is no need for a local Rust toolchain. The image pins `rust:1.91-bookworm` and adds clang 21
(the prebuilt libwebrtc refuses to link with Debian bookworm's clang 14), ALSA headers and cmake.
Cargo's registry and target directory live in named volumes, so the repository stays clean.

```bash
cd clients/voice
docker build --target core -t lanomat-voice-dev -f Dockerfile.dev .

# helper used in the examples below
dc() { docker run --rm --network host -v "$PWD:/work" \
  -v lanomat-cargo-registry:/usr/local/cargo/registry -v lanomat-cargo-target:/work/target \
  -w /work lanomat-voice-dev bash -c "$*"; }

dc cargo fmt --check
dc cargo clippy --all-targets --all-features -- -D warnings
dc cargo test --workspace
dc cargo build --release -p voice-cli       # binary: target/release/lanomat-voice (inside the volume)
```

The first build downloads libwebrtc and compiles for several minutes; later builds are incremental.
Files written by the container are owned by root; `chown` them back if that bothers you.

For a binary you can run on the host, build on the host instead (`cargo build --release -p voice-cli`
needs `clang >= 21`, `cmake`, `pkg-config` and the ALSA development package).

## Use

```bash
lanomat-voice login https://<LAN_DOMAIN>     # prints a code and URL, waits until you approve in the browser
lanomat-voice rooms                          # the rooms you may join (same list as the browser)
lanomat-voice join Lobby                     # by id or name; starts muted (push-to-talk)
lanomat-voice join Lobby --open-mic          # transmit continuously
lanomat-voice join Lobby --input "USB" --output "Headset"   # pick devices by name substring
lanomat-voice devices                        # list audio devices
lanomat-voice logout                         # forget the token locally
```

`login` follows the device authorization flow: it shows a user code and the verification URL, you
open it (logged in via Discord as usual), compare the code and the device name, and approve. The
token is stored in `~/.config/lanomat-voice/credentials.json` (mode 0600, directory 0700) and never
printed or logged. `logout` only removes it locally; to revoke it on the server use **Trennen** under
**Verbundene Geraete** on `/talk/connect` (the v1 API has no revoke route).

`join` uses the audio settings the server sends with the room token (Opus bitrate, DTX, RED, and the
echo cancellation, noise suppression and AGC switches). A moderator mute arrives as `muted: true`
and the session joins listen-only. The browser-only `jitter_buffer_target_ms` has no equivalent in
the Rust SDK and is ignored.

### Exit codes

| Code | Meaning |
|------|---------|
| 0 | success |
| 1 | local error (audio device, files, control socket) |
| 2 | usage error (bad URL, ambiguous room, missing environment) |
| 3 | authentication (not logged in, token revoked or expired, pairing denied or expired) |
| 4 | network or LiveKit connection (unreachable, rate limited, disconnected) |
| 5 | the server refused (room not found or not visible, archived, voice unavailable) |

Network problems never panic; they end in one `error:` line and one of the codes above.

## Push-to-talk and key binds

A running `lanomat-voice join` listens on a unix socket (`$XDG_RUNTIME_DIR/lanomat-voice.sock`, mode
0600; override with `LANOMAT_VOICE_SOCKET`; `--no-socket` disables it). The one-shot command
`lanomat-voice ptt down|up|toggle|status` connects, sends one line and prints `talking` or `muted`.
`down` and `up` are idempotent (X11 key repeat, a missed release), and a stale socket from a crashed
session is replaced, while a live one is refused. No compositor-specific code is involved: any
compositor or window manager that can run a command on key press and release can drive it.

Hyprland (`~/.config/hypr/hyprland.conf`; `bindr` runs on key release):

```ini
# hold the key to talk
bind  = , F13, exec, lanomat-voice ptt down
bindr = , F13, exec, lanomat-voice ptt up
# or toggle on a mouse button
bind  = , mouse:276, exec, lanomat-voice ptt toggle
```

Sway uses the same idea (`bindsym --release`), i3 has no release binding of its own, so use
`xbindkeys`/`sxhkd` for hold-to-talk there, or a toggle:

```text
# i3 / Sway: toggle (works everywhere)
bindsym $mod+m exec lanomat-voice ptt toggle

# Sway only: hold to talk
bindsym F13 exec lanomat-voice ptt down
bindsym --release F13 exec lanomat-voice ptt up

# sxhkd (X11, any window manager): hold to talk
F13
    lanomat-voice ptt down
@F13
    lanomat-voice ptt up
```

Each `ptt` call starts a process, so expect a few milliseconds of key-to-mic delay; that is on top
of nothing else; the mute state is switched immediately in the running session.

On Windows the same protocol runs over a named pipe, see [Windows](#windows).

## Windows

Windows 10/11 x64 is a supported platform (`x86_64-pc-windows-msvc`). The code is the same; only the
transport of the push-to-talk channel and the shutdown signals differ (`cfg(windows)` in
`voice-core/src/control.rs`, `voice-cli` and `app`).

**Push-to-talk channel.** Instead of the unix socket, the running app or `lanomat-voice join` serves a
named pipe `\\.\pipe\lanomat-voice-<your user SID>`. The protocol and the behaviour are identical:
one request line (`down`, `up`, `toggle`, `status`), one reply line; `down` and `up` are idempotent; a
second live session is refused (the pipe is created with the first-instance flag; a pipe has no stale
file because it vanishes with its owner). Only you can open it: the pipe has a protected DACL with
full access for your account and LocalSystem only, and remote clients are rejected. Override the name
with `LANOMAT_VOICE_SOCKET` (a full `\\.\pipe\...` name). Bind your key in whatever tool you like
(AutoHotkey, the keyboard software of your mouse) to run `lanomat-voice.exe ptt down` on press and
`lanomat-voice.exe ptt up` on release. The app's own global shortcut works on Windows too.

**Stopping.** Ctrl-C, Ctrl-Break and closing the console end `join` cleanly; the app also ends on
logoff/shutdown.

**Files.** Credentials and settings live under `%APPDATA%\lanomat-voice\config` (the per-user profile,
which only your account, SYSTEM and administrators can read, so the 0600 handling of the unix build is a
no-op there).

**Autostart.** The app does not register itself. To start it at login, put a shortcut to
`LANoMAT-Voice.exe` into `shell:startup` (Win+R, type `shell:startup`).

**Opening links.** `steam://connect/...` and other links are opened with the system handler
(`rundll32 url.dll,FileProtocolHandler`).

**Install and SmartScreen.** The release is a portable zip (`lanomat-voice-app-windows-x86_64.zip`:
unpack anywhere, start `LANoMAT-Voice.exe`; needs the WebView2 runtime, which Windows 11 and current
Windows 10 have) plus the CLI `lanomat-voice-windows-x86_64.exe`. The files are **not code-signed yet**,
so Windows SmartScreen warns ("Der Computer wurde durch Windows geschuetzt"). Click **Weitere
Informationen**, then **Trotzdem ausfuehren**. Compare the SHA-256 with `SHA256SUMS` from the release
first (`Get-FileHash .\file`). Signing comes later (see
[`docs/infra/native-client-release.md`](../../docs/infra/native-client-release.md)).

**Build.** The Windows binaries are cross-compiled on Linux with `cargo-xwin` (the `windows` stage of
`Dockerfile.dev`); `ci/build.sh` is the whole pipeline and what the release workflow runs:

```bash
cd clients/voice
docker build --target windows -t lanomat-voice-build -f Dockerfile.dev .
docker run --rm -v "$PWD:/work" -v lanomat-xwin-cache:/xwin-cache lanomat-voice-build sh ci/build.sh
```

`ci/windows-smoke.ps1` is the check that runs on a real Windows runner (CLI version, app window, the
pipe round trip). A Windows machine with Rust, MSVC build tools and clang >= 21 can also build natively
with `cargo build --release -p lanomat-voice-app -p voice-cli`.

## Latency test

`lanomat-voice latency-test <room>` mirrors the browser's "Latenztest" (see "Measuring and tuning
latency" in the web voice runbook): two sessions in one process, one publishes a synthetic click train
(20 ms, 1 kHz, amplitude 0.5, one per second, 3 warm-up and 20 measured bursts, each at a random phase
inside the 20 ms packet grid, 24 kbit/s with DTX and RED), the other detects the first sample above
a threshold. The delay is `detected - scheduled` on one `Instant` clock, reported as min, median,
p95 (nearest rank), max, mean and jitter (standard deviation). No microphone or speaker is involved,
so it measures encode, network, SFU, jitter buffer and decode and **excludes** OS device buffers.

It cannot use the LANoMAT bearer API: the API issues exactly one identity per user (`u<id>`), and
LiveKit disconnects the older of two connections with the same identity. The browser test uses a
session-authenticated, orga-only endpoint (`/talk/latency-test/token`) that has no bearer
counterpart. Rather than invent an endpoint, the CLI mints its own probe tokens from a LiveKit key
pair that you provide in the environment (never in a file in this repository):

```bash
export LANOMAT_VOICE_LIVEKIT_URL=wss://<LIVEKIT_DOMAIN>     # or ws://127.0.0.1:7880 for a dev server
export LANOMAT_VOICE_LIVEKIT_API_KEY=<key>
export LANOMAT_VOICE_LIVEKIT_API_SECRET=<secret>
lanomat-voice latency-test probe-room
```

A follow-up server change (a bearer `latency-test/token` route for orga and admin, mirroring the
browser's) would let `latency-test` run against a production deployment with only `login`.

### Loopback against a dev server

```bash
docker run -d --rm --name lanomat-lk-dev --network host \
  livekit/livekit-server:v1.13.11 --dev --bind 127.0.0.1   # logs the dev key pair on start
export LANOMAT_VOICE_LIVEKIT_URL=ws://127.0.0.1:7880 \
       LANOMAT_VOICE_LIVEKIT_API_KEY=<key from the log> LANOMAT_VOICE_LIVEKIT_API_SECRET=<secret from the log>
dc ./target/release/lanomat-voice latency-test probe-room
dc cargo test -p voice-core --features dev-tokens --test live --test live_ptt -- --ignored --nocapture
```

The `#[ignore]`d integration tests in `voice-core/tests/` are driven only by environment variables:
`live.rs` runs the two-session latency test and, with `LANOMAT_VOICE_BASE_URL`,
`LANOMAT_VOICE_TOKEN_FILE` (a `credentials.json` from `login`) and `LANOMAT_VOICE_ROOM`, a listen-only
join of a real room through the real API; `live_ptt.rs` proves that mute and unmute reach the
receiver through the SFU.

### How to read the numbers

- Each end works in **10 ms frames** (WebRTC's pipeline). A frame is stamped when it is handed over
  or pulled, and the click sits somewhere inside it, so every measurement carries up to one frame of
  quantisation at both ends, comparable to the "10 ms chunking" note for the browser test.
- The click starts at full amplitude (cosine phase), but Opus is a lossy codec: the decoded onset is
  smeared slightly and the threshold (10 % of full scale) is crossed a fraction of a millisecond
  after the true onset.
- Numbers from a loaded laptop in a debug build are noisier than from an idle release build; run it
  several times and look at the spread, as the runbook says.
- Echo cancellation, noise suppression and AGC are off in this test, as the synthetic browser track
  bypasses `getUserMedia`; the "Headset-Modus" question is therefore not covered by it.

## Desktop app

`clients/voice/app` is a Tauri v2 shell over `voice-core`. All behaviour sits in
`app/src/engine.rs` (plain async methods, no Tauri types, unit-tested); `app/src/lib.rs` only wires
it to Tauri commands, events, tray, overlay, global shortcut, the control socket and shutdown. The
front end is static HTML, CSS and JS in `app/ui/` with no npm and no build step, bundled by Tauri,
using the Signalpult tokens from `resources/css/app.css` and copies of Space Grotesk and
JetBrains Mono (OFL, licences in `app/ui/fonts/`). UI copy is German.

Versions: Tauri 2.12 (`tauri-build` 2.7, `tauri-plugin-global-shortcut` 2.4), WebKitGTK 4.1 on Linux.
No Tauri CLI is needed: a plain `cargo build -p lanomat-voice-app` produces the binary
(`tauri.conf.json` plus `tauri::generate_context!`).

### Participant screens

The window is a set of small ES modules in `app/ui/js/` (no bundler, no npm; `index.html` loads
`js/main.js` as `<script type="module">`), one file per screen, all German strings in
`js/strings.js`, design tokens in `app.css` and the component styles in `screens.css`. The concept
is [`docs/ux-concept.md`](docs/ux-concept.md), the command contract
[`docs/commands.md`](docs/commands.md).

| File | Screen |
|------|--------|
| `connect.js`, `onboarding.js` | **Verbinden** (device code, countdown, denied/expired/unreachable with a retry) and the **first-run guide** after a fresh pairing: headset, a moving input meter, a test tone, push-to-talk key or open mic, then straight to Jetzt |
| `now.js` | **Jetzt**: the prioritised cards of `now_feed` (match with *Beitreten*, *Server beitreten*, *Adresse kopieren* and "So kommst du ran", check-in, result to confirm; vote with the options as buttons on the card; order with a one-tap quick order; notifications; schedule) plus the client-side voice card, five at most and the rest under "Mehr anzeigen", polled every 30 s (60 s for the badges on the other tabs, nothing while hidden). Done actions stay as a "Erledigt" card until you leave |
| `voice.js`, `chat.js` | **Sprache**: rooms, the live room panel (hold-to-talk by mouse, Space or the global key, mute, roster) and the text chat beside it (history, Enter sends, 500 characters, the rate limit as a calm hint, live messages with de-duplication, `@name` mentions highlighted, delete for orga and admin, unread badge). On the other tabs a voice dock keeps talk and mute one tap away |
| `play.js`, `matches.js` | **Spielen**: my matches (report with the tie check, confirm, dispute, withdraw, check-in, `lock_version` conflicts answered with a reload), tournaments with my enrollment, event and permanent game servers (status light, players, map, address, offline has no join button) |
| `food.js` | **Essen**: the menu per ordering round with quantity steppers, a running total from integer cents, item-based ordering with the server's refusals shown in the line, my orders with a status timeline and cancel while open (with the reason when not) |
| `more.js`, `polls.js`, `files.js`, `notifications.js`, `settings.js` | **Mehr**: Abstimmungen (one tap, results as the server returns them), Dateien (quota bar, download with progress and the final path, upload by drag and drop or picker with progress, pause, resume, cancel and the moderation note), Benachrichtigungen (history, mark read, a switch per category for the app list and for desktop hints, the "Ruhe" toggle), Einstellungen and Gerät und Konto. **Freunde** and **Profil** are hidden until `list_friends` / `get_profile` exist (probed once, an `unknown command` keeps them out); their pages are placeholders for the follow-up that wires them |
| `nav.js` | the five tabs (a tab or a Mehr entry only when `get_config().modules` says the module runs), badges, the connection indicator and offline banner, the "Ruhe" toggle, a Hilfe sheet per tab, keyboard: arrow keys between tabs, Alt+1 to Alt+5 |

Every list has loading, empty, error (plain message and "Erneut versuchen") and normal states; a
failed refresh keeps the last data and says so. Nothing shows raw JSON. Text is `rem`-based, focus is
visible, `prefers-reduced-motion` is respected, the dark scheme follows the system, and below 560 px
the tabs share the width.

Three small Rust additions came with the screens: `audio_check_start`, `audio_check_stop` and
`play_test_tone` (with the `audio-level` event) for the first-run guide, a `role` in `get_status`
(chat moderation is offered to `orga` and `admin` only), and the four transfer commands became
`async` (a sync command has no Tokio runtime, so *Weiter* on a paused upload used to panic). The
"service unavailable" message no longer talks about voice chat when a feature route is busy.

Screenshots of every screen in its normal, empty and error state are in
[`docs/screenshots/voice-app/`](../../docs/screenshots/voice-app/) (names are numbered by screen:
`01`-`06` connect and first-run guide, `10`-`16` Jetzt, `20`-`23` Sprache, `30`-`34` Spielen,
`40`-`43` Essen, `50`-`63` Mehr, `70-empty-*`, `80-error-*`, `81`, `85-offline`, `90`/`91` modules off
and moderation, `narrow-*`, `dark-*`, and `v*` from the voice smoke: room, talking, settings,
overlay, denied).

### Build and run

```bash
cd clients/voice
# one Dockerfile, two stages on the pinned rust:1.91-bookworm base
docker build --target app -t lanomat-voice-app-dev -f Dockerfile.dev .  # + WebKitGTK, tray, Xvfb, xdotool, ImageMagick

dc() { docker run --rm --network host -v "$PWD:/work" \
  -v lanomat-cargo-registry:/usr/local/cargo/registry -v lanomat-cargo-target:/work/target \
  -w /work lanomat-voice-app-dev sh -c "$*"; }
dc cargo build --release -p lanomat-voice-app     # target/release/lanomat-voice-app (inside the volume)
```

To run it on your desktop, build on the host instead (`cargo build --release -p lanomat-voice-app`;
it needs the system packages of the `app` stage in `Dockerfile.dev` plus `clang >= 21` for libwebrtc). An
`.AppImage` or `.deb` bundle needs `cargo install tauri-cli --version '^2' --locked` and
`cargo tauri build`; it is not part of this slice (`bundle.active` is false).

### Push-to-talk, tray, close behaviour

- **Control socket (always on, also on Wayland).** The app binds the same unix socket as
  `lanomat-voice join` (`$XDG_RUNTIME_DIR/lanomat-voice.sock`, override with
  `LANOMAT_VOICE_SOCKET`). `lanomat-voice ptt down|up|toggle|status` drives the running app, so the
  compositor binds from "Push-to-talk and key binds" above work unchanged. Only one of app and
  `join` can own the socket; a second owner is reported as "nicht aktiv" in the settings, never a
  crash. The socket is removed on exit, including after SIGTERM.
- **Global shortcut (best effort).** Registered with `tauri-plugin-global-shortcut`, configurable in
  the settings (default `Control+Alt+Space`, key down = talk, key up = mute). It works on X11,
  Windows and macOS. **It does not work on Wayland** (applications cannot grab global keys); the
  settings say so and point to the compositor binds. Registration failures (key already taken, no
  X server) show up as a hint in the UI, never as a crash.
- **Hard mute.** "Stumm schalten" (button or tray) blocks transmitting, including open mic and the
  shortcut, until lifted; releasing a key is always allowed.
- **Tray.** Menu: Anzeigen, Stumm schalten / Stummschaltung aufheben, Overlay ein/aus, Verlassen,
  Beenden; the tooltip names the room and whether you are transmitting. If the tray icon cannot be
  created the app logs a warning and keeps working with its window.
- **Closing the window hides it to the tray** and the session keeps running (setting "Schließen
  minimiert in den Tray", on by default); with it off, closing quits. "Beenden" in the tray, SIGTERM
  and SIGINT always quit: the room is left and the control socket removed.
- **Overlay** ("Wer spricht"): a separate frameless, transparent, always-on-top window (top left,
  draggable) showing the room, your state and who is speaking, toggled in the settings or the tray.
  Always-on-top is a hint: Wayland compositors may ignore it, and transparency needs a compositing
  window manager (on a bare Xvfb the transparent corners are black, see `14-overlay.png`).

### Files

Settings: `<config dir>/lanomat-voice/settings.json` (`~/.config/lanomat-voice/settings.json` on
Linux), written atomically, **never containing the token**; unknown keys are ignored and a broken
file falls back to the defaults. The token stays in `credentials.json` in the same directory (0600),
managed by `voice-core`. The settings screen shows both paths.

### Headless smoke (what was run)

`app/tests/` holds the stand-in server and three scripts. The stand-in (`standin_server.py`, standard
library only, loopback) answers the device-code flow and the bearer API like the real server and
mints LiveKit join tokens for a dev SFU; the key pair comes from the environment only.

```bash
docker run -d --rm --name lanomat-lk-app --network host \
  livekit/livekit-server:v1.13.11 --dev --bind 127.0.0.1
# long-lived test container; read the dev key pair from the SFU's log into the environment
docker run -d --name lanomat-app-smoke --network host -v "$PWD:/work" \
  -v lanomat-cargo-registry:/usr/local/cargo/registry -v lanomat-cargo-target:/work/target \
  -e LIVEKIT_API_KEY=<key from the log> -e LIVEKIT_API_SECRET=<secret from the log> \
  -w /work lanomat-voice-app-dev sleep infinity
docker exec lanomat-app-smoke cargo build -p voice-cli -p lanomat-voice-app
docker exec lanomat-app-smoke sh app/tests/smoke-start.sh   # Xvfb, stand-in, app (synthetic audio)
docker exec lanomat-app-smoke sh app/tests/smoke-drive.sh   # drive it, check the SFU side, SIGTERM
docker exec lanomat-app-smoke sh app/tests/smoke-states.sh  # denied, empty, error, overlay shots
```

`LANOMAT_VOICE_SYNTHETIC_AUDIO=1` replaces sound devices with a 440 Hz test tone and a discarding
sink (the container has none). `smoke-drive.sh` clicks through the UI with `xdotool`, holds the
on-screen button and Space, presses the global shortcut with synthetic key events (X11 grab on
Xvfb, both release orders), uses `lanomat-voice ptt` against the app's socket, and has a second user
(the CLI) in the same room on the real SFU, whose log proves that the unmute, the mute and the
active-speaker state reached it; it then closes the window through `WM_DELETE_WINDOW` (hides to the
tray) and sends SIGTERM.

### Headless UI smoke (what was run)

`app/tests/smoke-ui.sh` drives the screens through the real UI: `app/tests/ui-drive.js` runs inside
the app's webview (the same `selftest` seam, plus a test-only `selftest_hook` command that runs
`ui-hook.sh` for screenshots, scenario switches, a second chat user and real key events through
`xdotool`), against the stand-in in `--ui-demo` mode (`standin_demo.py`: stateful matches, servers,
polls, a food round, files, notifications and chat, a rate limit, scenarios `normal`, `empty`, `error`
and `offline`). `app/tests/check-ui.mjs` checks with plain node that every string path used by a module
exists in `strings.js`.

```bash
dc cargo build -p lanomat-voice-app --features selftest
LIVEKIT_API_KEY=... LIVEKIT_API_SECRET=... dc sh app/tests/smoke-ui.sh   # MODE=full: pairing, guide, every flow
MODE=mods dc sh app/tests/smoke-ui.sh       # modules off (no Essen tab, no Dateien), orga role (chat delete)
MODE=empty dc sh app/tests/smoke-ui.sh      # every screen with nothing to show
MODE=errors dc sh app/tests/smoke-ui.sh     # every screen with a failing server, then the retry recovers
MODE=narrow dc sh app/tests/smoke-ui.sh     # 430 px window, text at 125 %
MODE=dark dc sh app/tests/smoke-ui.sh       # dark colour scheme
```

`MODE=full` pairs through the connect screen, walks the first-run guide (the meter must move), votes
and orders on the Jetzt cards, joins the match room (needs the LiveKit dev server of the voice smoke),
copies a server address, sends and receives a chat message (a mention raises the tab badge), runs into
the rate limit, reports, withdraws and confirms results, orders food up to the item limit, downloads a
file (progress, final path) and uploads one by drag and drop with the connection cut in the middle of a
chunk, pause and resume, and checks the evidence on the stand-in side. The voice smoke
(`smoke-drive.sh`, `smoke-states.sh`) uses the new tabs; their coordinates assume the 960x760
window.

## Core features: events, play, chat, notifications, files

Besides voice, `voice-core` and the app talk to the rest of the bearer API (see
[`docs/infra/native-voice-client-api.md`](../../docs/infra/native-voice-client-api.md)). This slice is the
Rust side only; the screens are built against the single contract in
[`docs/commands.md`](docs/commands.md) (every command with arguments and result, every event, the
error object `{kind, message, retryable}`), and a test keeps that document and the registered
commands in step.

| Where | What |
|-------|------|
| `voice-core/src/api/` | Typed clients for events, the "now" feed, tournaments, my matches (check-in, report, confirm, dispute, withdraw), game servers (event and permanent, with `joinUrl` and `copyText`), polls, catering, files (list, quota, delete, signed download link, upload ticket), notifications and preferences, chat, and `config` with `modules` and `realtime`. One error mapping for all of them: 401 `Unauthorized`, 403 `Forbidden`, 404 `NotFound`, 409 `Conflict` (stale `lock_version`), 410 `Gone`, 422 `Validation` (the server's German `message`, its `code` and the field errors), 429 `RateLimited` (with `Retry-After`), 503 `Unavailable`, 5xx `Server`, transport trouble `Network`; `Error::retryable()` says which are worth a retry. |
| `voice-core/src/realtime/` | A Pusher-protocol client over WebSocket (`tokio-tungstenite`, rustls, the shared crypto provider). Private channels are authorised per connection through `POST broadcasting/auth` with the bearer token (the socket id changes on every connect, so nothing is cached), subscribes to `private-web-voice.channel.{room}` and `private-web-voice.directory`, answers pings, detects a silent link, reconnects with backoff, re-authorises and re-subscribes, fetches what it missed, and falls back to polling `GET rooms/{room}/messages` when `realtime` is null or the socket cannot connect (it keeps trying to come back). The consumer sees a stream of `ChatEvent`. |
| `voice-core/src/notify/` | The notification engine and its pure policy: defaults on for match, catering, direct mention and vote closing and off for the rest, at most one system notification per category per minute (the rest a count), do-not-disturb while push-to-talk is held (with an open microphone: while speaking) and by the "Ruhe" toggle (also a tray item; a fullscreen-window check is not implemented), de-duplication by id across restarts (bounded id set plus a `created_at` high-water mark in `settings.json`), the first run only records what is already unread, and a mapping from category to the target tab and card. Polling is calm: 15 s focused, 45 s otherwise, backoff on failures, a refetch only when the count changed. Delivery goes through the `Notifier` trait (`tauri-plugin-notification` in the app, a recording fake in the tests). |
| `voice-core/src/transfer/` | Downloads: streamed to a `.part` file, resumed with HTTP `Range` on `206`, retries with backoff, atomic rename, sanitised names, never overwrites. Uploads: a tus 1.0.0 client over reqwest (creation with `Upload-Metadata`, chunked `PATCH` with an explicit length, `HEAD` to resume at the server's offset, retries like the web app's `useResumableUploads.ts`, no retry on 403, 413, 507 or `ticket_*`), a fingerprint file so an interrupted upload resumes after a restart, and a manager with progress, pause, resume and cancel. The signed link and the ticket are never logged or stored, and the bearer token is never sent to the file host. |
| `app/src/features.rs` | The Tauri-free command layer over all of the above, unit-tested; `app/src/lib.rs` has the one-line commands. Notification, clipboard and dialog plugins are used from Rust only, so the webview gets no plugin permission (a test checks it); `open_join_url` accepts only `steam://connect/<host>:<port>[/<password>]`. |

Versions added: `tokio-tungstenite` 0.29 (already in the tree through LiveKit, `rustls-tls-native-roots`),
`tokio-util` 0.7 and `base64` 0.22 (voice-core); `tauri-plugin-notification` 2.8, `tauri-plugin-clipboard-manager`
2.5, `tauri-plugin-dialog` 2.8 and `time` 0.3 (app); reqwest keeps 0.13 with the `stream` and `query` features.

Settings gained `download_dir`, `notify_categories` and `notify_seen` (the engine's bookkeeping; a save from the UI
cannot overwrite it). Still no token in `settings.json`.

### Headless feature smoke (what was run)

`app/tests/standin_server.py --features` (with `standin_features.py`) answers the documented endpoints, a signed
Pusher server (HMAC of `socket_id:channel`, checked on subscribe), a signed file download with `Range`, and a
tus endpoint that cuts the connection in the middle of the second chunk once. An app built with
`--features selftest` evaluates `app/tests/selftest.js` in its webview, which calls the commands through the real
Tauri IPC with the argument names of `docs/commands.md`:

```bash
dc cargo build -p lanomat-voice-app --features selftest   # `dc` as in "Build and test in the container", app image
dc sh app/tests/smoke-features.sh                         # Xvfb, stand-in, app; prints PASS/FAIL lines, exits non-zero on failure
```

It needs no LiveKit server. The feature is for tests only and is never part of a release build.

## Architecture notes

- `audio::AudioSource` / `AudioSink` are the seams. Everything speaks 48 kHz mono, 10 ms frames.
  `cpal_io` (feature `cpal-audio`) adapts real devices (any rate or channel count, linear
  resampler); `synthetic` provides a deterministic click source and recording sink, which is what
  makes the whole path testable without sound hardware (`join --synthetic-audio` uses them too).
- `session::Session` publishes with `NativeAudioSource` (no internal queue, exact 10 ms frames, lowest
  latency) and plays each remote track through `NativeAudioStream`. Push-to-talk is mute/unmute of
  the published track, and capture frames are dropped while muted.
- `api::PollState` is the pure state machine of the device flow (pending, `slow_down` with the
  server's new interval or +5 s, 429 as back-off, bounded transient retries, expiry).
- Secrets: `Debug` output of tokens, grants and keys is redacted, the credential file is created with
  mode 0600 from the first byte and replaced atomically.

## Honest limits: what was not verified

Verified in the container (see the PR for the exact output): formatting, clippy, 62 unit and
mock-server tests, the device flow and bearer API against a mock server, the CLI against a stand-in
HTTP server (`login`, `rooms`, error paths and exit codes, `join --synthetic-audio` with `ptt`
commands, clean SIGINT shutdown and socket removal), and publish/subscribe, latency statistics and
push-to-talk mute/unmute through a real LiveKit server on loopback.

Not verified:

- **Real audio devices.** The `cpal` capture and playback code compiles and its pure parts (mixer,
  resampler) are unit-tested, but it has never run against sound hardware or a sound server. Device
  formats other than f32/i16/u16/i32 are rejected. Linear resampling is adequate for speech, not
  hi-fi. Expect first-contact problems with exotic devices.
- **A real network.** Only loopback was measured. Packet loss, Wi-Fi, TURN/TCP fallback, reconnect
  after a real outage and a real LANoMAT deployment (TLS, Traefik, the production LiveKit) were not
  exercised. The `join_a_real_room_listen_only` test exists for that and needs credentials.
- **Echo cancellation and noise suppression** are passed to the SDK as the server asks, but their
  effect with real microphones is untested. The SDK's own platform audio module (`PlatformAudio`) was
  not used, to keep the `AudioSource`/`AudioSink` seams.
- **Windows and macOS.** The code is plain Rust and cpal, but the control socket is a unix socket (the
  CLI is unix-only for now; a named pipe or loopback TCP variant would be needed), and nothing was
  built or run there.
- **Mixing and many participants.** Playback mixes streams by summing with saturation; loud rooms with
  many speakers were not tried.
- **Global key capture on Wayland** is left to the compositor/WM binds above. The desktop app adds
  a global shortcut that works where the platform allows it (X11, Windows, macOS) and never on Wayland.

Additionally not verified for the desktop app: the global shortcut on anything but X11 (it was
exercised with synthetic key events on Xvfb only), the tray icon as a user sees it (the icon is
created in the container without a warning, but no StatusNotifier host displays it, and the menu was
never clicked), the overlay on a real compositor (Wayland
always-on-top and transparency), the dark colour scheme, Windows and macOS, and real audio devices
(the smoke uses the synthetic tone). The UI was driven with `xdotool`, not with `tauri-driver`.

Not verified for the feature slice: the **real server** (every route was exercised against wiremock fixtures taken
from the API document and against the Python stand-in, never against Laravel, Reverb or tusd, so a deviation of the real
wire format from the document would only show there); a real Reverb handshake over **TLS** (`wss`; the tests use
plain `ws`); the real file host (signed links, `Range` behaviour of the real host, tusd's hooks and its chunk limits);
**system notifications** as a person sees them (the delivery call was made, but a headless container has no notification
daemon, and the desktop plugin offers no click callback, hence `take_notification_target`); the clipboard and the
Steam URL handler on a real desktop (the container has no handler, the validation and the error path are covered);
the file picker dialogs (compiled and registered, never shown); Windows and macOS for all of it; very large files
and a real flaky network for the transfers (loopback drops only).

Not verified for the participant screens: **real audio** (the first-run guide's meter and tone ran
against the synthetic source and sink; `cpal` capture and playback behind them are the same untested
code as before), a **real OS drag and drop** (the drop is delivered as the window event that Tauri
sends, emitted by the script; xdotool cannot drag a file in from outside), the **file picker** and
**folder picker** dialogs, **system notifications** and their click target, the **tray and overlay on
Wayland**, **Windows and macOS**, the **real server** (only the stand-in), and **Friends and Profil**
(hidden by design until the other branch's commands exist; the probe names `list_friends` and
`get_profile` are the ones in that branch's contract).

## Next steps

Per-user volume and more tuning knobs in the app, a portal-based global shortcut on Wayland
(`xdg-desktop-portal` GlobalShortcuts, compositor support is still patchy), Windows and macOS builds
(the control socket needs a named pipe or loopback variant), packaged bundles, and the bearer
`latency-test/token` route mentioned above.
