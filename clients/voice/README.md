# LANoMAT native voice client (core slice)

A native desktop client for LANoMAT web voice, written in Rust. It joins the same LiveKit rooms as
the browser page (`/talk`), through the bearer API documented in
[`docs/infra/native-voice-client-api.md`](../../docs/infra/native-voice-client-api.md), and adds what
a browser cannot do: **global push-to-talk** (also on Wayland), a lower floor for audio latency and
a headless latency self-test. Server side, rooms and tuning background:
[`docs/infra/livekit-web-voice.md`](../../docs/infra/livekit-web-voice.md).

This is the first working slice: a cargo workspace with a UI-free core library and a command line
binary. The Tauri shell is the next step (see the end), not part of this slice.

| Crate | What |
|-------|------|
| `voice-core` | Library: API client (device-code pairing, bearer API v1), credential store, LiveKit session (publish, subscribe, mute), audio traits with `cpal` and synthetic implementations, push-to-talk control socket, latency maths and the two-session latency test. |
| `voice-cli` | Binary `lanomat-voice`: `login`, `rooms`, `join`, `ptt`, `latency-test`, `devices`, `logout`. |

Versions used (Cargo.lock is committed): `livekit` 0.10.0 (with `libwebrtc` 0.3.55, which downloads a
prebuilt libwebrtc at build time), `livekit-api` 0.10.0 (token minting for the dev/latency tooling
only), `cpal` 0.18.2, `reqwest` 0.13 (rustls), `tokio` 1, `wiremock` 0.6 for tests.

## Build and test in the container

There is no need for a local Rust toolchain. The image pins `rust:1.91-bookworm` and adds clang 21
(the prebuilt libwebrtc refuses to link with Debian bookworm's clang 14), ALSA headers and cmake.
Cargo's registry and target directory live in named volumes, so the repository stays clean.

```bash
cd clients/voice
docker build -t lanomat-voice-dev -f Dockerfile.dev .

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
- **Global key capture** is deliberately left to the compositor/WM binds above; there is no built-in
  hotkey grabber.

## Next step: the Tauri shell

Not built in this slice. The plan is a Tauri app on top of `voice-core`: tray icon with mute state,
a settings UI (device pickers, push-to-talk key, jitter and processing options, per-user volume), a
room list and roster, and a small overlay showing who is speaking. The control socket protocol stays
as the compositor-friendly entry point; the shell adds in-app hotkeys where the platform allows them
(X11, Windows, macOS) and a portal-based global shortcut on Wayland.
