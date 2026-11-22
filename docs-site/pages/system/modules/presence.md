---
title: Presence
description: Who is online, playing or free — as a projection, a page and a beamer scene.
sidebar_icon: radar
---

`Presence` answers the oldest LAN question: *where is everybody?* It is a projection over other
modules' read-models (registration, tournaments, voice, game servers), not a table of its own. It
feeds the participant page, the "Wo ist wer" panel in the native client and a beamer scene.

## Who uses it

- **Teilnehmer** see who is online, in which voice room, in which match, or free to play.
- **Orga** uses it to find people at the venue.
- **Beamer** shows a capped presence scene.

## Key screens

- `/events/{slug}/presence` — participants grouped by state, with a friends-only filter.
- Native client **Wo ist wer** — follow a person into voice/game.
- Beamer scene — 5 per column, no scrolling.

| Dark | Light |
|---|---|
| ![Presence, dark](/screens/presence-dark.png) | ![Presence, light](/screens/presence-light.png) |
| `PRESENCE · DARK` — online, playing, free | `PRESENCE · LIGHT` |
| ![Native client Personen tab in the dark theme, people with the room and game they are in plus a follow button](/screens/voice-app/dark-personen.png) | ![Native client Personen tab in the light theme, the same roster with follow buttons](/screens/voice-app/24-sprache-personen.png) |
| `WO IST WER · DARK` — who shares a location, and where | `WO IST WER · LIGHT` — same roster |

## Guards and rules

- The projection is viewer-agnostic; `isFriend` is added only by the presence page controller for
  the current viewer.
- Visibility is one rule (`PresenceVisibility::visibleParticipants`): the event's non-cancelled
  participants, minus everybody who switched `share_location` off and everybody who blocked the
  viewer (either way); the viewer always sees themselves when they are a participant. The server
  rows' **„Hier spielen“** line reads the same rule, so a hidden account is neither listed nor counted
  there — and only the event's own people list may see that line at all (`EventPolicy::viewPeople`: a
  registered participant of that event or orga; an account alone learns nothing).
- Broadcasts carry an **empty** payload on the public channel; the authorized page re-fetches.
- The beamer scene never receives the participant roster with ids, e-mail or network data.
- Voice sources are resolved through each provider's read-model; a provider being down degrades the
  entry to "unknown", not to a 500.
- Location snapshots are refreshed by a queued job (`RefreshLocationSnapshotJob`, built inline on a
  cold cache) and by the scheduled `presence:refresh-locations`, not on every request.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Follow, don't interrupt.</strong> The desktop client can follow a person into a voice room
  or game, but joining remains an explicit action.
</div>
