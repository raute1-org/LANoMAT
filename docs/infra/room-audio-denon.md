# Room audio: Denon AV receiver at the venue

Runbook for the `RoomAudio` module (orga only): the orga steers the room's Denon AV
receiver (power, volume with an orga-set ceiling, mute, input, sound mode) from the
LANoMAT admin page **Raumsteuerung**. Music Assistant plays the tracks to the receiver
over HEOS; LANoMAT only steers the receiver, it never sends audio. Participants vote for
tracks and do not touch the receiver.

The design is in [`docs/superpowers/specs/2026-11-01-room-audio-denon-design.md`](../superpowers/specs/2026-11-01-room-audio-denon-design.md),
the sidecar in [`docker/denon-admin/README.md`](../../docker/denon-admin/README.md).

All addresses below are placeholders: `<RECEIVER_IP>` is the receiver on the venue LAN,
`<TUNNEL_IP>` the venue host's WireGuard address, `<TOKEN>` the shared bearer token. Real
values live only in the venue's `.env` files and never in this repository.

## What runs where

| Part          | Where                              | Role                                                                                 |
| ------------- | ---------------------------------- | ------------------------------------------------------------------------------------ |
| `denon-admin` | venue host, compose profile `prod` | Holds the one telnet session to the receiver (port 23), REST API with bearer auth.   |
| LANoMAT app   | app server (cloud or venue)        | Module `RoomAudio`, talks HTTP to the sidecar through the `ReceiverClient` contract. |
| Receiver      | venue LAN                          | Denon AVR, telnet control on port 23, HEOS input `NET` for Music Assistant.          |

The receiver has no authentication on its telnet port, so the sidecar is the only thing
that talks to it, and it must not be reachable from outside the venue network.

## 1. Enable the module (app side)

`RoomAudio` is an optional module. It is off in the `core` profile and on in `full`:

```dotenv
MODULES_PROFILE=full
# or only this module:
MODULE_ROOM_AUDIO=true
```

Point the app at the sidecar and give it the same token the sidecar holds:

```dotenv
# Sidecar on the same compose network as the app:
DENON_ADMIN_REST_URL=http://denon-admin:8000
# Sidecar reached over the venue tunnel (see section 3):
# DENON_ADMIN_REST_URL=http://<TUNNEL_IP>:8087
DENON_ADMIN_TOKEN=<TOKEN>
```

Without a token the check is skipped, the page shows the receiver as unreachable, and the
orga actions fail calmly. Nothing else is needed.

## 2. Configure and start the sidecar (venue host)

Generate the token once, store it only in the venue's `.env`:

```bash
openssl rand -hex 32
```

Venue `.env` (next to `compose.yml`, never committed):

```dotenv
DENON_HOST=<RECEIVER_IP>
DENON_PORT=23
DENON_ADMIN_TOKEN=<TOKEN>
```

Start the service:

```bash
docker compose --profile prod up -d denon-admin
docker compose --profile prod logs -f denon-admin   # wait for the first connection
```

The compose service binds to loopback only (`127.0.0.1:8087:8000`). Check it from the venue
host with the token; the sidecar answers `connected: true` once it has its telnet session:

```bash
curl -s http://127.0.0.1:8087/healthz
curl -s -H "Authorization: Bearer <TOKEN>" http://127.0.0.1:8087/state | head -c 400
```

`/healthz` answers without auth and reports only `connected`. `/state` requires the token.

## 3. Network placement

The sidecar must open a TCP connection to `<RECEIVER_IP>:23`. Pick the case that matches
where the app server runs.

**App on the venue LAN (or on a host that routes to the receiver).** The default compose
bridge reaches the receiver through NAT on the host. Check it from inside the container:

```bash
docker compose --profile prod exec denon-admin python -c "import socket; socket.create_connection(('<RECEIVER_IP>', 23), 3); print('ok')"
```

If the receiver sits on a VLAN that the Docker bridge cannot route, run the sidecar on the
host network instead. Then the compose port mapping is ignored and the API listens on the
host's port 8000 on every interface, so add a firewall rule that only allows the app
server to reach it, and point `DENON_ADMIN_REST_URL` at the host's address. Do not leave
the host-network variant open to the venue LAN without that rule.

**App in the cloud.** The app server reaches the venue only through the WireGuard tunnel
(see [`wireguard-tunnel.md`](wireguard-tunnel.md)). The loopback binding is not reachable
through the tunnel, so bind the sidecar to the venue host's tunnel address instead:

```yaml
# docker compose override on the venue host, service denon-admin
ports: ['<TUNNEL_IP>:8087:8000']
```

Then restrict the port to the tunnel interface with the host firewall (accept on the
WireGuard interface only, drop everything else on 8087). Set `DENON_ADMIN_REST_URL` on the
app side to `http://<TUNNEL_IP>:8087`. The app's Preflight check runs inside the app
container, so it needs the same route.

## 4. Find the receiver's `NET` source name

The sidecar lists the receiver's inputs as `sources` (code and name) in `/state`:

```bash
curl -s -H "Authorization: Bearer <TOKEN>" http://127.0.0.1:8087/state | jq '.sources'
```

The network input (HEOS, which Music Assistant plays into) is the entry whose name is the
network or HEOS input. On the receiver it is usually the `NET` code. Confirm it before the
event: in the admin page **Raumsteuerung**, switch the input to that source and check that
the receiver changes. If the code differs on your model, use the code the sidecar lists.

## 5. Volume ceiling and safety

The volume range is `0` to `98` in 0.5 steps. The orga sets a **ceiling** in
**Raumsteuerung** (admin panel); every volume command is clamped to `min(98, ceiling)`
on the server, so neither the slider nor a crafted request can exceed it. Set the ceiling
before the event, and check it once with the room's real speakers.

When the receiver is in standby, the page disables volume, mute and input controls and
shows "Receiver ist aus". Power on works from the page.

## 6. Preflight

With the module on, the admin Preflight shows the check `room_audio` (Raum-Audio (Denon)):

- **skipped**: no `DENON_ADMIN_TOKEN` in the app.
- **down**: the sidecar is unreachable or answers with an error. Check the tunnel, the
  compose service and the URL.
- **warn**: the sidecar answers, but the receiver is off or not reachable from the sidecar.
  Check `<RECEIVER_IP>` in the venue `.env`, the receiver's power and its network setting.
- **ok**: the sidecar holds the telnet session.

## 7. Venue checklist

- [ ] `docker compose --profile prod ps denon-admin` shows the service healthy.
- [ ] `/healthz` reports `connected: true` from the venue host.
- [ ] The app server reaches the sidecar URL (Preflight `room_audio` is ok).
- [ ] The network source (`NET`) switches in Raumsteuerung and the receiver follows.
- [ ] Volume and mute work; the ceiling clamps a higher request.
- [ ] Power off and on from the page; the page shows "Receiver ist aus" while off.
- [ ] Music Assistant plays to the receiver over HEOS; LANoMAT only steers, it does not play.
- [ ] The token is only in the venue `.env` and the app's `.env`, not in files or commits.
