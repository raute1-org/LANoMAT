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
| ![Schedule, dark](/screens/schedule-dark.png) | ![Schedule, light](/screens/schedule-light.png) |
| `SCHEDULE · DARK` — now/next, favourites | `SCHEDULE · LIGHT` |

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

`schedule_list` returns one event's timeline in order, with type, time, location and description.
Favouriting and ICS export stay in the app.

<div class="callout-live">
  <strong>Now/next is a read-model.</strong> The schedule projection owns "what is now" and "what is
  next"; the beamer and the participant page use the same projection.
</div>
