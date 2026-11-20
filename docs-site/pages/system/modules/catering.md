---
title: Catering
description: Menus, per-person orders, option limits, cost splitting and ready notifications.
sidebar_icon: utensils
---

`Catering` handles group food orders for one event. The orga publishes a menu with options and
prices; participants place one order each; the kitchen gets a list; the group sees a cost split.

## Who uses it

- **Teilnehmer** open the menu, choose options, see their total and mark an order as received.
- **Orga** creates the order window, edits the menu, closes it, exports the kitchen list and marks
  items ready.

## Key screens

- `/events/{slug}/food` — open menus, place or change an order, my total.
- Filament **Food orders** — menu editor, open/close, per-option counts, CSV export.
- Dashboard card — "Essen ist da" when an order is ready.

| Dark | Light |
|---|---|
| ![Catering, dark](/screens/catering-dark.png) | ![Catering, light](/screens/catering-light.png) |
| `CATERING · DARK` — menu, order, total | `CATERING · LIGHT` — same menu |

## Guards and rules

- A non-cancelled event registration is required (`FoodOrderRegistrationRequirement`); the gate is
  re-checked on every write.
- One order per user per food order; changing an option replaces the previous selection.
- Per-option limits and price bounds are validated server-side; the UI hint is not the guard.
- The order window is time-based (`opens_at`/`closes_at`) and status-based; a closed window is
  read-only.
- Costs are stored in cents and rendered as money; no floats.
- Marking an item ready notifies the owner once, not on every edit.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Kitchen list.</strong> The CSV export and the per-option counts come from the same
  read-model as the participant total, so the kitchen and the payer never disagree.
</div>
