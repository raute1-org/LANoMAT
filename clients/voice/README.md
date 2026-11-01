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

Screens:

- **Verbinden**: server URL, then the user code, the verification link (copy, open in the browser),
  a countdown and a waiting state; denied, expired and unreachable end in an error with "Erneut
  versuchen".
- **Räume**: general rooms and your own match rooms (loading, empty, error and normal states), and,
  when joined, a live panel with the connection state (a LiveIndicator-style dot), the roster with
  speaking and mute state, a big hold-to-talk button (mouse, touch or Space), and a hard mute.
- **Einstellungen**: microphone and speaker (substring match like the CLI), open mic,
  "Headset-Modus" (no echo cancellation, noise suppression or AGC, as in the browser; see the web
  voice runbook), the global shortcut with a key capture field, close-to-tray, the overlay, where to
  revoke the device (`/talk/connect`, "Trennen" under "Verbundene Geräte") and "Abmelden".

Screenshots from the headless run are in
[`docs/screenshots/voice-app/`](../../docs/screenshots/voice-app/): `01-connect`, `02-connect-wait`,
`04-room-muted`, `05-talking`, `08-settings`, `10-connect-denied`, `11-rooms-empty`,
`12-rooms-error`, `13-settings-scrolled`, `14-overlay`.

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

## Next steps

Per-user volume and more tuning knobs in the app, a portal-based global shortcut on Wayland
(`xdg-desktop-portal` GlobalShortcuts, compositor support is still patchy), Windows and macOS builds
(the control socket needs a named pipe or loopback variant), packaged bundles, and the bearer
`latency-test/token` route mentioned above.
