---
title: Events
description: The aggregate root — lifecycle, current-event context and the event page.
sidebar_icon: calendar
---

`Events` owns the LAN edition itself. Everything organizational hangs off an `Event`; the module
provides CRUD in Filament, the lifecycle transitions, the "current event" context used by the
sidebar and the event page.

## Who uses it

- **Orga** creates the event, sets location, dates, capacity and arrival info, and moves it through
  the lifecycle.
- **Teilnehmer** see the event page only when the event is not `draft`; every event page requires
  login. Guests see the event-free landing at `/`.

## Key screens

- `/events/{slug}` — event page: status, dates, spots left, arrival info, news block, hype
  countdown while `announced`/`registration` and `starts_at` is in the future. Login required.
- `/admin/events` — lifecycle actions, registration relation manager, check-in QR, arrival overview.

| Dark | Light |
|---|---|
| ![Event page, dark](/screens/event-page-dark.png) | ![Event page, light](/screens/event-page-light.png) |
| `EVENT · DARK` — status, spots, arrival info | `EVENT · LIGHT` — same state |

## Guards and rules

- Lifecycle is `draft → announced → registration → live → finished → archived`; transitions are
  actions, not free-form edits.
- A `draft` event is invisible to everyone but orga — the event page 404s.
- Registration availability is decided by `RegistrationAvailability::for($event)`, never by
  comparing the status enum in a controller.
- `max_participants = null` means unlimited; cancelled registrations do not count against it.
- `Event.arrival_info` is display-only logistics text ("how to get here").

## Docs for agents

`events_list` returns every visible event with dates, status and the caller's own registration;
`event_get` returns one event with its tournaments and schedule; `event_register` signs the token
owner up (capacity and the registration window are enforced by the same action as the web form).
`lanomat_me` confirms the identity and which modules are on.

<div class="callout-live">
  <strong>One current event.</strong> The sidebar's "Aktuelle LAN" group appears only when a current
  event exists. Historical events stay reachable through their own pages and the recap.
</div>
