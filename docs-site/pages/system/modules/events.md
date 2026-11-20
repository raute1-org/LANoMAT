---
title: Events
description: The aggregate root — lifecycle, current-event context and the public event page.
sidebar_icon: calendar
---

`Events` owns the LAN edition itself. Everything organizational hangs off an `Event`; the module
provides CRUD in Filament, the lifecycle transitions, the "current event" context used by the
sidebar and the public event page.

## Who uses it

- **Orga** creates the event, sets location, dates, capacity and arrival info, and moves it through
  the lifecycle.
- **Teilnehmer and guests** see the public page only when the event is not `draft`.

## Key screens

- `/events/{slug}` — public page: status, dates, spots left, arrival info, news block, hype
  countdown while `announced`/`registration` and `starts_at` is in the future.
- `/admin/events` — lifecycle actions, registration relation manager, check-in QR, arrival overview.

| Dark | Light |
|---|---|
| ![Event page, dark](/screens/event-page-dark.png) | ![Event page, light](/screens/event-page-light.png) |
| `EVENT · DARK` — status, spots, arrival info | `EVENT · LIGHT` — same state |

## Guards and rules

- Lifecycle is `draft → announced → registration → live → finished → archived`; transitions are
  actions, not free-form edits.
- A `draft` event is invisible to everyone but orga — the public page 404s.
- Registration availability is decided by `RegistrationAvailability::for($event)`, never by
  comparing the status enum in a controller.
- `max_participants = null` means unlimited; cancelled registrations do not count against it.
- `Event.arrival_info` is display-only logistics text ("how to get here").

## Docs for agents

`events_list` with `include_finished`, `event_get` by slug (event, my registration, tournaments, schedule) and `event_register` with `slug` plus an optional `ticket_type`.

<div class="callout-live">
  <strong>One current event.</strong> The sidebar's "Aktuelle LAN" group appears only when a current
  event exists. Historical events stay reachable through their own pages and the recap.
</div>
