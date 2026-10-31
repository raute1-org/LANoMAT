# Web voice chat with LiveKit

Browser voice and text chat inside LANoMAT (the `WebVoice` module): people talk in the browser, with no separate
client. Voice runs through a self-hosted [LiveKit](https://livekit.io) server (a WebRTC SFU); text chat runs through
the existing Reverb setup and needs no extra server. Design and decisions:
[`../superpowers/specs/2026-10-31-web-voice-design.md`](../superpowers/specs/2026-10-31-web-voice-design.md).

This is **separate from** the Mumble and TeamSpeak setup ([`../teamspeak-setup.md`](../teamspeak-setup.md)): both can
run side by side, nothing about them changes.

> Status: configuration and code are done and tested against a fake server. **Nothing here has been run against a
> real LiveKit server or a real browser yet.** The checklist at the end is the acceptance test.

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

Not set because no value could be verified without a real server and measurements: LiveKit's `room.playout_delay`
(a server-side lower bound for the browser's jitter buffer), the host's UDP socket buffers (`net.core.rmem_max`), and
Opus frame sizes below the 20 ms browser default. They are the first things to look at if the measured delay is higher
than expected.

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
- [ ] Measure mouth-to-ear: record both ends (speak a click, record both devices with a third phone) and note the
      delay. Write the number down in the PR/issue; the design target is under 100 ms.
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
- A measured latency probe (a headless client that joins a room and reports round-trip time) for the preflight page.
