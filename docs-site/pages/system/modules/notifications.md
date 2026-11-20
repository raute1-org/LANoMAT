---
title: Notifications
description: The in-app bell, category preferences, and the Discord DM mirror.
sidebar_icon: bell
---

`Notifications` is the truth channel. Every important event lands in the in-app bell; Discord is a
mirror for the time-sensitive ones, never a replacement. The module owns the notification classes,
the category map and the preference UI.

## Who uses it

- **Teilnehmer** read the bell, mark items read and choose categories per channel.
- **Orga** triggers notifications indirectly through actions (registration opened, check-in opened,
  schedule changed, match ready, food ready).

## Key screens

- Bell dropdown in the app shell — unread count, grouped by category.
- `/settings/notifications` — category × channel preferences (in-app, Discord DM).
- `/notifications` — the full list with read state.

## Guards and rules

- Categories are explicit (`Registration`, `Tournament`, `Schedule`, `Catering`, `Team`,
  `Friends`, `System`). A notification without a category is a bug.
- The database channel is always on for actionable state; Discord is opt-in per category.
- Friend requests are deliberately bell-only — low urgency, no DM.
- The bell is the source of truth: if Discord is down or the DM cannot be delivered, the in-app
  item still exists.
- Read state is per user via Laravel's standard notifications table.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Discord amplifies, never replaces.</strong> Announcements and reminders are deduplicated
  through <code>discord_outbox.dedup_key</code>, so a retry cannot post twice.
</div>
