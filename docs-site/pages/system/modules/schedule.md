---
title: Schedule
description: The event timeline, favourites, ICS export, change alarms and tournament sync.
sidebar_icon: calendar-clock
---

`Schedule` is the event's timeline: manual slots (opening, dinner, tombola) plus tournament matches
that are synced automatically. Participants can favourite items and export the plan as an ICS file.

## Who uses it

- **Teilnehmer** read now/next, favourite items, subscribe to the calendar, and get a notification
  when a favourited item changes or starts soon.
- **Orga** creates slots, sets audience and visibility, and reschedules.

## Key screens

- `/events/{slug}/schedule` — the timeline, grouped by day, with now/next markers.
- `/events/{slug}/schedule.ics` — the stable calendar export.
- Filament **Schedule items** — CRUD, timezone-aware input.

| Dark | Light |
|---|---|
| ![Schedule timeline grouped by day with a now card, favourites and ICS export, dark](/screens/schedule-dark.png) | ![Schedule timeline grouped by day with a now card, favourites and ICS export, light](/screens/schedule-light.png) |
| `SCHEDULE · DARK` — now/next, favourites, ICS export | `SCHEDULE · LIGHT` |
| ![Admin schedule list with title, type, start and event, dark](/screens/admin-schedule-dark.png) | ![Admin schedule list with title, type, start and event, light](/screens/admin-schedule-light.png) |
| `ADMIN · SCHEDULE` — slots and synced tournament matches | `ADMIN · LIGHT` |

## Guards and rules

- All instants are stored in UTC and rendered through `resources/js/lib/datetime.ts` in the one
  display timezone (`APP_DISPLAY_TIMEZONE`, default Europe/Berlin). Never `toLocaleString()`.
- Tournament matches are synced by a listener on tournament save; manual items and generated ones
  do not duplicate.
- A changed item notifies favouriters and everyone with the item's audience; the notification names
  the new time.
- The ICS export is stable: identical input yields identical output, so calendar apps do not show
  phantom updates.
- Editing a slot does not move the event's own dates.

## Docs for agents

`schedule_list` with `event_slug` returns the event's schedule in order.

<div class="callout-live">
  <strong>Now/next is a read-model.</strong> The schedule projection owns "what is now" and "what is
  next"; the beamer and the participant page use the same projection.
</div>
