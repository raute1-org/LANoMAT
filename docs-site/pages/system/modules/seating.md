---
title: Seating
description: The seat grid, claim/release, and the network metadata behind every seat.
sidebar_icon: armchair
---

`Seating` is the chart participants stare at on arrival. A grid of seats, claimed by registered
participants, with network metadata for the orga. It reads like a control diagram on purpose:
labels and ports are mono, state is colour, and nothing moves unless someone acts.

## Who uses it

- **Teilnehmer** claim a free seat and see their own seat on the dashboard and ticket.
- **Orga** generates the grid, edits seats, blocks broken places and records switch port / IP /
  socket metadata per seat.

## Key screens

- `/events/{slug}/seating` — the participant grid, with claim/release.
- Filament **Seats** resource — standalone, not an event tab: bulk-create a grid, edit labels and
  positions, set per-seat network metadata.

| Dark | Light |
|---|---|
| ![Participant seat grid with claimed and free seats, dark](/screens/seating-dark.png) | ![Participant seat grid with claimed and free seats, light](/screens/seating-light.png) |
| `SEATING · DARK` — claimed, free, blocked | `SEATING · LIGHT` — same grid, paper tokens |
| ![Admin seat list with label, row, column and occupant, dark](/screens/admin-seats-dark.png) | ![Admin seat list with label, row, column and occupant, light](/screens/admin-seats-light.png) |
| `ADMIN · SEATS` — grid, label, row, column, occupant | `ADMIN · LIGHT` |

## Guards and rules

- Only a registered participant may claim; the seat page sends unregistered viewers to the event
  registration while it is open.
- One seat per registration: `seat_assignments` is unique on both `seat_id` and `registration_id`.
- A cancelled registration releases its seat (`ReleaseSeatOnCancellation`), so a place does not stay
  blocked by someone who is not coming.
- Claim and release are row-locked actions; two people tapping the same seat cannot both win.
- `meta` is a JSON bag for network data — never rendered to guests.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Why a dedicated resource?</strong> A seat grid is bulk-created and edited far away from
  a single event's form. The standalone Filament resource keeps grid work fast and the event edit
  page calm.
</div>
