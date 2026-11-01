# Room audio: Denon AVR control from LANoMAT — design note

Status: approved direction, not implemented. Placeholders only: `<RECEIVER_IP>`, `<SIDECAR_SECRET>`.

## Goal

The orga steers the room's music from LANoMAT: volume (with a ceiling), mute, power, input and sound
mode of the Denon AV receiver, next to the Jukebox (Music Assistant plays Spotify, SoundCloud and local
files to the receiver over HEOS). Participants keep voting for tracks; they do **not** touch the receiver.

## Decisions (owner, 2026-11-01)

- **Who:** orga only. Every call goes through a Policy; no participant surface.
- **How it is reached:** a small sidecar at the venue (`docker/denon-admin/`, FastAPI like `teamspeak-admin`).
  The sidecar is the only thing that speaks the receiver's telnet control protocol (port 23); LANoMAT
  talks HTTP to it with a shared secret. The receiver IP lives in the sidecar's environment only
  (the repo is public).
- **Protocol reference:** the owner's Omarchy plugin `kleintonno.denon` (its `scripts/denon_bridge.py`):
  one persistent telnet connection, >= 50 ms between commands, queries `PW? ZM? Z2? MV? MU? SI? MS? SSFUN ?`,
  volume 0..98 in 0.5 steps (`MV325`), `MVMAX`, whitelisted commands only, receiver text sanitised.

## Shape

- Optional module `RoomAudio` behind `config/modules.php` (profile `full`, off in `core`), with a
  `ReceiverClient` contract (`state()`, `setPower`, `setVolume`, `setMute`, `setSource`, `setMode`),
  `HttpReceiverClient` (sidecar) and `FakeReceiverClient` (tests). Other modules never call it directly;
  the Jukebox page may show the panel through a `Modules::enabled()` guard.
- Actions per use case (`SetReceiverVolume` ...), each clamping to an orga-set **volume ceiling**
  (stored per event) and rejecting values the receiver cannot take; controllers/Filament stay thin.
- Filament: a standalone "Raumsteuerung" page for orga (state, volume slider, mute, power, input, mode,
  ceiling). State is read through the client (short cache), never trusted from the browser.
- Preflight probe `room_audio` (sidecar reachable, receiver connected) and a "receiver off while the
  Jukebox queue plays" hint.
- Sidecar API (secret-authenticated, never published beyond the venue network): `GET /state`,
  `POST /command` with a whitelisted body; it keeps the telnet session, reconnects, and reports
  `connected: false` instead of failing.

## Out of scope (for now)

Zone 2 as its own player, participant volume votes, Home Assistant, a player picker for Music Assistant
(the player stays config-bound, see the Jukebox decisions).

## Open points to verify on the real receiver

Receiver model and firmware (zone count, `NET` source name), that HEOS input switching on `NET` works
while Music Assistant streams, and the sidecar's network placement at the venue.
