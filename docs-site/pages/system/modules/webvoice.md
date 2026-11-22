---
title: Web voice
description: Browser voice and chat on self-hosted LiveKit, plus the native client bearer API.
sidebar_icon: headphones
---

`WebVoice` is voice in the browser and the backend for the native desktop client. It runs against
a self-hosted LiveKit SFU, keeps a persisted text chat per channel, and creates a general room plus
per-match team rooms.

## Who uses it

- **Teilnehmer** join voice in the browser or through the native client, chat, mute and moderate
  their own devices.
- **Orga** creates channels, moderates, and reads connection diagnostics.
- **The desktop client** uses the bearer API under `/api/voice-client/v1` with device pairing.

## Key screens

- `/talk` — channels, participants, chat, join/leave.
- `/talk/connect` — diagnostics, self-test, headset mode.
- `/app` — the unified download page for the LANoMAT app (**App herunterladen**: the native client in
  the server's own version, plus the launcher while it is a separate build); `/talk/client` and
  `/launcher` redirect there.
- Native client **Spiele** — read-only: the published, lineup-allowed recipes, a game's detail and
  its install plan (`/api/voice-client/v1/recipes`, `…/recipes/{slug}`,
  `…/recipes/{slug}/plan`); installing and starting follow with a later version of the app.
- Filament **Web voice channels** — create, archive, moderate.

| Dark | Light |
|---|---|
| ![Native client Jetzt tab with the voice card, no room joined yet](/screens/voice-app/v03-rooms.png) | ![Native client settings with the "Overlay Wer spricht" toggle and the speaking overlay in the corner](/screens/voice-app/v14-overlay.png) |
| `NATIVE · ROOMS` — occupancy and join | `NATIVE · OVERLAY` — speaking while in game |
| ![Browser voice page in the dark theme, Sprachchat empty state before a match opens a room](/screens/talk-dark.png) | ![Browser voice page in the light theme, the same empty Sprachchat](/screens/talk-light.png) |
| `SPRACHCHAT · DARK` — no channels until a match opens a team room | `SPRACHCHAT · LIGHT` — same empty state |

## Guards and rules

- The browser and the desktop client connect **directly** to LiveKit; the app only issues room
  tokens. Audio bytes never pass through Laravel.
- A match room's access list is the roster snapshot; a non-roster user cannot get a token.
- Chat is persisted; channel visibility is policy-checked on every fetch.
- The native API is bearer-authenticated; pairing is a device-code flow. Every route is in the
  route authorization matrix test.
- The native client's recipe routes (`/api/voice-client/v1/recipes`, `…/{slug}`, `…/{slug}/plan`)
  exist only while `GameRecipes` is routable and read published recipes only: the lineup, an exact
  slug (aliases are an agent convenience), and the installer's plan with signed file-host links.
- The client sends `X-Client-Version`; the server answers `X-Server-Version`. A build is offered
  only when its version equals the server's. `VOICE_CLIENT_MIN_VERSION` can refuse old clients.
- All four app services must be built together, or the version match for the download breaks.
- `REVERB_ALLOWED_ORIGINS` is a host name, no scheme; Reverb refuses a handshake without an
  `Origin`.

## Docs for agents

No MCP tool yet. The native client API is documented in `docs/infra/native-voice-client-api.md`.

<div class="callout-live">
  <strong>Browser parity.</strong> Everything the desktop app does also works in the browser. The
  app adds push-to-talk in-game, a speaking overlay and "Wo ist wer" with follow.
</div>
