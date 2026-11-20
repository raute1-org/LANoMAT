---
title: Registration
description: Sign-up, ticket tiers, QR tickets, self check-in, walk-ins and the arrival plan.
sidebar_icon: ticket
---

`Registration` turns a public event into a list of people who will actually be there. It owns
registrations, ticket labels, QR tokens, check-in, the on-site self check-in and the optional
arrival plan.

## Who uses it

- **Teilnehmer** register, pick a ticket type, show the QR ticket, check themselves in from their
  phone and optionally enter arrival/departure.
- **Orga** checks people in with the camera scanner or token field, prints the event QR, rotates the
  token, and reads the arrival overview.

## Key screens

- `/events/{slug}/register` — the sign-up form, including the optional "Ankunft und Abreise".
- `/ticket` and the dashboard ticket card — QR, ticket label, seat and arrival lines.
- `/checkin/{token}` — phone self check-in (public QR at the entrance).
- `/orga/events/{slug}/checkin` — scanner + token fallback; `/checkin-qr` is the printable sheet.
- Filament: registrations relation manager, **Ankunftsübersicht** with charts.

## Guards and rules

- One non-cancelled registration per user per event.
- Payment is manual: `paid_at` is set by orga; paid/unpaid does not block check-in.
- Self check-in opens when the event is `live`, or `registration` and now is at least 12 hours
  before `starts_at` (`SelfCheckInWindow`). After `finished`/`archived` it is closed.
- Check-in requires a non-cancelled registration; walk-ins can register while the event is `live`
  and spots remain, then check in.
- The QR encodes an absolute URL from `APP_URL`; printing it with the wrong host breaks every
  phone at the venue.
- Endpoints are throttled (30 requests/minute per user); the scanner posts only the token, never
  camera frames.

## Docs for agents

`events_list` and `event_get` include the caller's registration state; `event_register` registers the
token owner for an event (`slug`, optional `ticket_type`). Self check-in itself has no MCP tool.

<div class="callout-live">
  <strong>Privacy.</strong> The self check-in page receives only the event token. A ticket's
  <code>qr_token</code> is never part of the Inertia props.
</div>
