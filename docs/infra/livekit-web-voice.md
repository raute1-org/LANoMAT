# Web voice chat with LiveKit

Browser voice and text chat inside LANoMAT (the `WebVoice` module): people talk in the browser, with no separate
client. Voice runs through a self-hosted [LiveKit](https://livekit.io) server (a WebRTC SFU); text chat runs through
the existing Reverb setup and needs no extra server. Design and decisions:
[`../superpowers/specs/2026-10-31-web-voice-design.md`](../superpowers/specs/2026-10-31-web-voice-design.md).

This is **separate from** the Mumble and TeamSpeak setup ([`../teamspeak-setup.md`](../teamspeak-setup.md)): both can
run side by side, nothing about them changes.

> Status: configuration and code are done and tested against a fake server. **Nothing here has been run against a
> real LiveKit server or a real browser yet.** The checklist at the end is the acceptance test.

> A native desktop client uses the same rooms through a separate token API: see [native-voice-client-api.md](native-voice-client-api.md). The Rust client (CLI, push-to-talk over a control socket, headless latency test) and its Tauri desktop app (room list, push-to-talk button, tray, speaking overlay; its "Headset-Modus" switch means the same as the browser's) are in [`clients/voice/`](../../clients/voice/README.md).

## Placeholders

| Placeholder            | Meaning                                                                                            |
| ---------------------- | -------------------------------------------------------------------------------------------------- |
| `<LAN_DOMAIN>`         | the name participants open the app with (see [`lan-tls-certificates.md`](lan-tls-certificates.md)) |
| `<LIVEKIT_DOMAIN>`     | the signaling name, for example `livekit.<LAN_DOMAIN>`, on the same wildcard certificate           |
| `<VENUE_SERVER_IP>`    | LAN address of the host that runs the `livekit` container                                          |
| `<LIVEKIT_API_KEY>`    | any short identifier, chosen by you                                                                |
| `<LIVEKIT_API_SECRET>` | a random string of at least 32 characters, chosen by you                                           |

## Where to run it: at the venue

**Run the `livekit` container on the machine at the venue**, next to Traefik, not on the cloud app server. Voice
latency is the whole point of this module: with the SFU in the cloud every packet takes the internet round trip twice
(up to the server and down again), and a 20 ms codec frame turns into 80 ms or more. At the venue the media path is
participant browser -> switch -> `<VENUE_SERVER_IP>` and back.

The pieces that follow from that:

- `<LIVEKIT_DOMAIN>` must resolve to `<VENUE_SERVER_IP>` **inside the venue** (the same split-DNS or hosts entry that
  makes `<LAN_DOMAIN>` resolve there, see [`app-at-the-venue.md`](app-at-the-venue.md)), and the venue Traefik must
  serve it with the wildcard certificate. A page served over HTTPS cannot open `ws://`, and LiveKit does not accept a
  self-signed certificate.
- UDP `7882` on `<VENUE_SERVER_IP>` must be reachable from participant devices (see the port table).
- The app (Laravel) must reach the SFU's HTTP API: `LIVEKIT_API_URL=http://livekit:7880` when both run in the same
  compose project, otherwise an address the app can route to.
- **Access point / client isolation breaks voice.** Many Wi-Fi setups block device-to-device traffic even inside one
  subnet. The browser talks to `<VENUE_SERVER_IP>`, not to other devices, so plain client isolation is usually fine,
  but a guest network that firewalls UDP to the server is not. Test from a phone on the participant Wi-Fi, not from the
  server.

## Ports

| Port                  | Protocol | Published | Purpose                                                                                             |
| --------------------- | -------- | --------- | --------------------------------------------------------------------------------------------------- |
| `7880`                | TCP      | **no**    | Signaling and HTTP API. Traefik proxies it as `wss://<LIVEKIT_DOMAIN>`; the app calls it internally |
| `7882`                | **UDP**  | yes       | All media (ICE/UDP mux, `rtc.udp_port`). This is the low-latency path                               |
| `7881`                | TCP      | yes       | Last-resort media fallback for clients that cannot use UDP. Adds delay, not the normal path         |
| `50000-60000`         | UDP      | no        | LiveKit's default per-connection range. **Not used**: `rtc.udp_port` replaces it                    |
| TURN (`3478`, `5349`) | UDP/TCP  | no        | Disabled. On a LAN no relay is needed, and it would only add a hop                                  |

Open `7882/udp` (and `7881/tcp` if you want the fallback) on the host firewall for the venue network only. Do not
forward them from the internet.

## Latency settings

All numbers, with the reasoning, are in the design note ("Latency choices"). The ones that live in this runbook's
files:

| Setting (file)                                   | Value                         | Why                                                                         |
| ------------------------------------------------ | ----------------------------- | --------------------------------------------------------------------------- |
| `rtc.udp_port` (`livekit.yaml`)                  | `7882`                        | media direct over UDP, one port, no TURN                                    |
| `rtc.use_external_ip` / `rtc.node_ip`            | `false` / `<VENUE_SERVER_IP>` | advertise the LAN address as the ICE host candidate                         |
| `rtc.tcp_port`                                   | `7881`                        | fallback only                                                               |
| `turn.enabled`                                   | `false`                       | no relay hop                                                                |
| `webvoice.audio.bitrate` (`config/webvoice.php`) | `24000`                       | Opus mono speech, small packets                                             |
| `webvoice.audio.dtx` / `red`                     | `true` / `true`               | no silence packets; redundancy hides single losses with no retransmit delay |
| `webvoice.token_ttl`                             | `600` s                       | the token is only used while connecting                                     |

Not set because no value could be verified: LiveKit's `room.playout_delay` (it drives the RTP playout-delay header
extension, which is meant for video synchronization; it is off), the host's UDP socket buffers (`net.core.rmem_max`),
and Opus frame sizes below the 20 ms browser default (`livekit-client` 2.23 has no packet-time option, and the SDP would
have to be rewritten). The receiver jitter buffer target exists as a switch (`LIVEKIT_JITTER_BUFFER_TARGET_MS`) but is
unset by default: see "Measuring and tuning latency".

If the host has more than one network interface (wired plus a Docker bridge plus a VPN), make sure `node_ip` is the
address participants can reach. `network_mode: host` on the `livekit` service is the lowest-overhead variant (no NAT in
the path), at the price of the container using the host's ports directly; the default bridge mode with a published UDP
port adds only kernel NAT, which is microseconds.

## Setup

1. **Module and keys.** In the deployment's `.env` (never in git):

    ```
    MODULE_WEB_VOICE=true
    LIVEKIT_URL=wss://<LIVEKIT_DOMAIN>
    LIVEKIT_API_URL=http://livekit:7880
    LIVEKIT_API_KEY=<LIVEKIT_API_KEY>
    LIVEKIT_API_SECRET=<LIVEKIT_API_SECRET>
    LIVEKIT_NODE_IP=<VENUE_SERVER_IP>
    LIVEKIT_DOMAIN=<LIVEKIT_DOMAIN>
    ```

    Generate the secret with `openssl rand -base64 36`.

2. **Render the server config** (writes the gitignored `docker/livekit/livekit.yaml`, mode 600):

    ```
    scripts/ops/render-livekit-config.sh --env-file .env
    ```

3. **Start only the SFU** (its profile is not part of `docker compose up -d` and not part of `scripts/deploy/deploy.sh`):

    ```
    docker compose --profile web-voice up -d livekit
    ```

4. **Migrate and restart the app** so the module's tables and routes exist (`php artisan migrate --force`).
   Traefik picks up the `livekit` router from the container labels.

5. **Permissions-Policy.** The Traefik `secure-headers` middleware must allow the microphone for the app origin:
   `microphone=(self)` in `docker/traefik/dynamic.yml` (changed in this release; it used to be `microphone=()`, which
   makes the browser refuse the microphone without a useful error). Recreate Traefik after pulling the change.

6. **Content-Security-Policy.** Nothing to do: when `LIVEKIT_URL` is set, its `wss://` origin is added to
   `connect-src` automatically (report-only today, enforced later).

7. In the admin panel create the general channels (for example "Lobby"): **Turniere & Teams > Sprachkanäle**.

Match rooms appear on their own when a tournament match becomes ready and close after the match (empty rooms at once,
occupied rooms when the last person leaves).

## Measuring and tuning latency

Goal: as close to Mumble on the same LAN as a browser allows. Be clear about the physics first:

- A browser cannot get below roughly **10 ms** mouth-to-ear, and in practice not below the sum of the items that follow.
- The microphone and speaker buffers of the **operating system** (often 10 to 40 ms together, more with Bluetooth
  and some USB devices) are outside what the page can influence.
- Browsers send Opus in **20 ms frames** (10 ms only if negotiated; `livekit-client` 2.23 offers no option, see
  below). The capture side also works in 10 ms chunks.
- The **jitter buffer** trades latency for glitch-free audio: less buffer means less delay and more dropouts on a
  bad network.

There are three measurements, from cheapest to most real. Use them in this order.

### 1. Connection diagnostics (live, orga and admin)

On `/talk`, join a room and open "Verbindungsdiagnose" (collapsed by default, polled once a second only while open).
It reads the real WebRTC stats of the LiveKit peer connections:

| Value                             | Where it comes from                                                                | What to look for                                                                                                          |
| --------------------------------- | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Weg (candidate / protocol)        | selected ICE candidate pair (`host`, `srflx`, `relay`; `udp`, `tcp`)               | On a LAN it must be `host / udp`. `tcp` or `relay` is the classic cause of 100 ms and more; the panel says so in German   |
| Laufzeit hin und zurück           | `currentRoundTripTime` of the pair                                                 | A few ms on a wired LAN; above 30 ms the panel warns (Wi-Fi, far server)                                                  |
| Jitter                            | `jitter` (RFC 3550 estimate)                                                       | A few ms is normal; large values force a deeper jitter buffer                                                             |
| Jitter-Buffer (Mittel)            | `Δ jitterBufferDelay / Δ jitterBufferEmittedCount` over the last second            | The buffering the browser adds right now. 10 to 40 ms measured                                                            |
| Wiedergabepuffer (Mittel)         | `media-playout`: `Δ totalPlayoutDelay / Δ totalSamplesCount`                       | Time from the buffer to the audio device; "nicht gemeldet" where the browser has no such stats                            |
| Paketverlust, Überspielte Samples | `Δ packetsLost`, `Δ concealedSamples / Δ totalSamplesReceived`                     | Concealed samples are the audible glitches. DTX gaps are concealed too, so a few percent is normal when people pause      |
| Datenrate, Codec                  | bytes sent/received, codec stats                                                   | Opus; about 40 kbit/s while speaking with RED                                                                             |
| Paketlänge                        | `ptime=` in the codec's fmtp line                                                  | Usually "nicht gemeldet": Chromium does not report it, and it is not estimated                                            |

All rates are differences of two samples, never the cumulative totals. The numbers describe the **network path**,
not the buffers of your microphone and speakers.

### 2. Latenztest (in the page, orga and admin)

The card "Latenztest" on `/talk` measures encode + network + SFU + jitter buffer + decode of the real publish/subscribe
path **without a microphone or speaker**, repeatably:

1. The page asks for two tokens (`POST /talk/latency-test/token`, orga and admin only) for a private probe room
   `<prefix>latency-u<user id>` with the identities `u<id>-probe-tx` and `u<id>-probe-rx` (different identities, because
   LiveKit disconnects the older connection when one identity joins twice). The room has **no channel row**, so it is
   in no channel list and no Filament table and cannot be moderated; LiveKit drops it when empty.
2. Both connections use exactly the room options of a real call (`resources/js/lib/webVoiceRoomOptions.ts`: speech
   preset 24 kbps, DTX, RED).
3. One connection publishes a synthetic track made with the Web Audio API: a 20 ms 1 kHz burst (amplitude 0.5)
   every second, 3 warm-up bursts and then 20 measured ones, each at a random phase inside the 20 ms packet grid.
4. The other connection receives it. An **AudioWorklet** (a same-origin file, not a `blob:`, because worklets follow
   `script-src`) finds the first sample above the threshold; the delay is `detected - scheduled`, on **one audio
   clock** (generation and detection live in the same `AudioContext`), so no clock sync is needed.
5. The card shows min, median, 95th percentile, max, jitter (the standard deviation of the 20 delays), the mean jitter
   buffer and the share of concealed samples. A select lets you try a jitter buffer target.

What the number includes: the Web Audio to MediaStream bridge at both ends (about one 2.7 ms render block each) and
the 10 ms chunking of the WebRTC audio pipeline. What it **excludes**: microphone and speaker buffers of the OS, the
browser's capture processing (echo cancellation, noise suppression), the human. Because the synthetic track bypasses
`getUserMedia`, **the self-test cannot measure the Headset-Modus**. Run it several times: see the spread below.

### 3. Recording with a third device (the real mouth-to-ear value)

Only a recording includes microphone, speaker and operating system.

1. Two participants, A and B, in the same room on `/talk`, on two different devices. Put a third device (a phone with
   a voice recorder, or a laptop with Audacity) so that **its microphone hears both A's mouth and B's speaker**.
2. A claps once, sharply, close to A's own microphone. The clap travels two ways: through the air straight to the
   recorder, and through the app to B's speaker, where the recorder hears it a second time.
3. Open the recording in Audacity, zoom in on the two spikes and read the time between them with the selection tool.
   That is **mouth-to-ear plus or minus the acoustic path differences** (about 3 ms per metre): keep the recorder within
   a metre of A's mouth and of B's speaker and the error stays small.
4. Repeat ten times and write down the median and the worst value, with the devices, headset or speakers, wired or Wi-Fi.

### Comparing with Mumble

Use **the same procedure, the same two devices, the same recorder position** and run the Mumble client on both devices
(the same transmit mode on both) instead of the browser. Mumble has its own buffers (the client's "audio per packet"
and jitter settings, the server on the same LAN), so note them. The difference of the two medians is the price of
the browser. The Latenztest tells you how much of the browser's number is the network path and the jitter buffer, and
the diagnostics panel tells you whether the path is a clean `host / udp` one. Do not compare a Mumble recording with a
Latenztest number: the self-test leaves out the operating system.

### Tuning options and what they cost

| Option                                                                                         | What it does                                                                                                                         | Cost                                                                                                                          | Status                                                      |
| ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| `LIVEKIT_JITTER_BUFFER_TARGET_MS` (server; `config('webvoice.audio.jitter_buffer_target_ms')`) | sets `RTCRtpReceiver.jitterBufferTarget` on every received audio track where the browser has it (Chromium); empty = browser default | higher values add that much delay; low values did **not** reliably go below the adaptive default (measurements below)        | **unset**: no measurement supports a lower value            |
| "Headset-Modus (niedrigste Latenz)" (per user, `localStorage`)                                 | capture without echo cancellation, noise suppression, automatic gain control and Chromium's voice isolation; asks for 48 kHz mono   | open speakers feed back (others hear themselves twice); only for headsets                                                     | opt-in, off by default; the self-test cannot measure it     |
| Packet time 10 ms                                                                              | not available                                                                                                                        | n/a                                                                                                                           | `livekit-client` 2.23 has no option; SDP rewriting rejected |
| Server `room.playout_delay`                                                                    | RTP playout-delay extension                                                                                                          | meant for video synchronization                                                                                               | off                                                         |
| Push-to-talk                                                                                   | publish the microphone once, then only mute/unmute                                                                                   | none                                                                                                                          | unchanged by all of the above                               |

Switching the Headset-Modus while a microphone is published re-acquires the capture device once and puts the new
track on the **same sender** (`LocalAudioTrack.restartTrack`: no renegotiation, no second publish); a muted track stays
muted, so push-to-talk keeps working. `applyConstraints` was tried first and is not used: Chromium accepts the call and
leaves `echoCancellation`, `noiseSuppression` and `autoGainControl` unchanged on a live track.

### Measured by the author (setup, not a real venue)

Setup: **Chrome for Testing 156.0.8078.4, headless, with fake media devices** (`--use-fake-ui-for-media-stream
--use-fake-device-for-media-stream`) on the developer machine; the app on `php artisan serve`;
`livekit/livekit-server:v1.13.11` in a container with host networking and `node_ip 127.0.0.1` (loopback, so the path is
`host / udp` with an RTT below 0.1 ms), the template's config with TURN off; the Latenztest as described above (20
bursts per run, DTX, RED, 24 kbit/s). The audio devices are Chromium's fake devices, so **the operating-system buffers
of a real machine are not in these numbers**. No phone, no Wi-Fi, no real microphone, no venue server.

Median delay of one run in ms / mean jitter buffer of that run in ms. Each pair is one run of 20 bursts. Inside a run
the 20 delays differed by 0.0 to 0.1 ms on loopback, so the differences below are **between runs**:

| Jitter buffer target | Runs (median ms / mean buffer ms)                                  |
| -------------------- | ------------------------------------------------------------------ |
| browser default      | 85.9/32.4, 81.5/32.4, 61.3/11.1, 61.3/11.1, 56.7/11.2              |
| 0 ms                 | 56.7/11.1, 64.0/11.1, 56.7/11.1, 56.7/11.1, 69.2/32.5, 58.7/11.1   |
| 10 ms                | 64.7/18.7, 64.0/11.1, 75.5/32.4                                    |
| 20 ms                | 56.7/11.1, 85.1/32.4, 74.3/32.4                                    |
| 40 ms                | 86.7/42.4, 69.7/31.1                                               |
| 80 ms                | 120.2/81.0 (one run)                                               |

What this says, and what it does not:

- The browser default, 0, 10 and 20 ms are **indistinguishable**: each produced runs with a mean buffer of about 11 ms
  (path about 57 to 64 ms) and runs with about 32 ms (path about 74 to 86 ms). The state the jitter buffer settles in
  differs from run to run, not with the setting. So **no default target was set**: nothing here shows that a low target
  lowers the delay repeatably.
- Targets of 40 ms and more do raise the buffer (an 80 ms target gave 81 ms of buffer and a 120 ms path). The option is
  useful upwards, for example against dropouts on a Wi-Fi venue, and it costs exactly what it adds.
- Browser, SFU and browser on loopback took **about 57 to 86 ms with fake devices**, of which the jitter buffer is 11 to
  32 ms. The rest is the 20 ms Opus frame, the 10 ms chunking, SFU forwarding and the two Web Audio bridges. A real call
  adds microphone and speaker buffers on top.
- The run-to-run spread (about 30 ms) is larger than any tuning effect found. Always repeat a measurement before
  concluding anything.
- The share of concealed samples was 8.3 to 8.4 % in every run: those are the DTX gaps between the bursts, so concealment
  could not show whether a low target glitches. Judging "glitch-free" needs speech on a real network, which was not tested.
- Headset-Modus: verified that the constraints change (the live toggle gave `echoCancellation`, `noiseSuppression` and
  `autoGainControl` false on the re-acquired track, a muted track stayed muted, one `getUserMedia` per toggle). The
  **latency benefit was not measured**: the fake device and the synthetic test bypass the processing.
- `livekit-client` requests `voiceIsolation: true` by default; the Headset-Modus switches that off too.

All of it still has to be repeated on the venue server with real devices (checklist below).

### Trying it locally without a venue

Render a config with a dummy key and `node_ip: 127.0.0.1`, run the `livekit/livekit-server` image with host
networking, set `LIVEKIT_URL=ws://127.0.0.1:7880`, log in as orga and press "Test starten". The Latenztest and the
diagnostics also work against the real venue server: run them from a laptop on the venue Wi-Fi and from a wired one and
compare.

## Health

`php artisan lanomat:preflight` has a "Web-Sprachchat (LiveKit)" probe (reachability of the API port only). It is
skipped until `LIVEKIT_API_KEY` is set. Whether media actually flows is the manual checklist below.

## Manual acceptance checklist (needs a real server and two devices)

- [ ] `docker compose --profile web-voice up -d livekit` starts; `docker compose logs livekit` shows the node IP equal
      to `<VENUE_SERVER_IP>` and the UDP port 7882.
- [ ] From a participant device on the venue network, `https://<LIVEKIT_DOMAIN>` shows a valid certificate (no
      warning).
- [ ] Two devices (laptop plus phone, ideally on different Wi-Fi/wired segments) open `/talk`, join "Lobby": each sees
      the other in the participant list and hears the other after enabling the microphone.
- [ ] The browser asks for the microphone exactly once; after "Block" the page shows the German "Mikrofon blockiert" hint.
- [ ] Speaking indicator: the speaking person's row lights up on both devices.
- [ ] Push-to-talk: Space held transmits, release stops; the first press has no audible delay.
- [ ] In the browser's `chrome://webrtc-internals`, the audio connection's selected candidate pair is `host`/`host`
      over `udp` (not `tcp`, not `relay`), and the round trip time is a few milliseconds.
- [ ] Open "Verbindungsdiagnose" on both devices: path `host / udp`, no TCP or relay hint, RTT a few ms. Run the
      "Latenztest" five times from a wired and from a Wi-Fi device and write the medians down.
- [ ] Measure mouth-to-ear with the recording procedure above (third device, ten claps), and the same for Mumble; write
      both medians in the PR/issue. The design target for the browser is under 100 ms.
- [ ] Headset-Modus on a headset: the constraints change, and a recording shows whether it helps on that device.
- [ ] A team member of a ready match sees their team room in the list and **not** the opponent's; admin and orga see both;
      a third participant sees neither; an orga account sees both and can moderate, a helper account behaves like a participant.
- [ ] Admin in a room: mute a participant (their microphone button turns off and stays disabled, also after
      re-joining), remove a participant (disconnected), delete a chat message (disappears for everybody).
- [ ] Text chat: a message from one device shows on the other within a second; 501 characters is refused; sending
      eleven messages in ten seconds is rate limited.
- [ ] Close a match whose room is occupied: the room stays until the last person leaves, then disappears from the list.
- [ ] Stop the `livekit` container: the page shows the "Sprachserver nicht erreichbar" state on join, text chat keeps
      working, `lanomat:preflight` shows the probe down.

## What LANoMAT automation would need

- A `livekit` provisioning step next to the Traefik/Proxmox ones in the `Provisioning` module: render the config, pull
  the pinned image, open `7882/udp` on the venue host, add the DNS record for `<LIVEKIT_DOMAIN>`.
- A LiveKit webhook receiver (`participant_joined/left`) so the channel list can show live occupancy without the app
  polling the server API, and so a mute could follow a participant across rooms.
- A measured latency probe for the preflight page (the in-page Latenztest needs a browser and is orga-only; a headless variant could report to the preflight).
