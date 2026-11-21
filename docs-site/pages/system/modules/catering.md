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
| ![Catering menu with pizza options, prices and my order, dark](/screens/catering-dark.png) | ![Catering menu with pizza options, prices and my order, light](/screens/catering-light.png) |
| `CATERING · DARK` — open order, menu, my order | `CATERING · LIGHT` — same menu |
| ![Admin food orders list with status and order window, dark](/screens/admin-food-orders-dark.png) | ![Admin food orders list with status and order window, light](/screens/admin-food-orders-light.png) |
| `ADMIN · FOOD ORDERS` — status, opens at, closes at | `ADMIN · LIGHT` |

## Guards and rules

- A non-cancelled event registration is required (`FoodOrderRegistrationRequirement`); the gate is
  re-checked on every write.
- One order per user per food order; changing an option replaces the previous selection.
- Per-option limits and price bounds are validated server-side; the UI hint is not the guard.
- The order window is time-based (`opens_at`/`closes_at`) and status-based; a closed window is
  read-only.
- Costs are stored in cents and rendered as money; no floats.
- Marking an item ready notifies the owner once, not on every edit.

## Dish descriptions and photos

Every dish can carry an optional short description (plain text, max 600 characters) and a photo. Old menus
without them stay valid; nothing was migrated.

- **Format.** Uploads are decoded and re-encoded as WebP (longest edge 800 px, quality 80, EXIF/GPS dropped) and
  stored on the private `local` disk as `catering/dishes/<sha256-16>.webp`. The name is the content hash, so the
  same photo on several dishes is one file; files are shared and never deleted when a dish drops its photo. The
  menu stores only that relative path, never a URL.
- **Delivery.** `GET /catering/dish-image/{round}/{key}` streams the file with `Cache-Control: public,
  max-age=86400`. No login (like the public gallery photos), but only for a published round of a publicly visible
  event whose dish has a photo; everything else is a 404. The page URL carries `?v=<hash>` so a replaced photo
  is not stale. No `storage:link` is needed.
- **Admin.** The menu editor of a food round has a description field and a photo upload per dish (images only,
  max 3 MB).
- **Bulk assignment.** `php artisan catering:import-dish-media {foodOrder} {json} --images=<folder> [--dry-run] [--force]`
  reads a JSON list of `{category, name, description, image_file}`, matches the dishes of the round by
  normalised name (narrowed by category through a fixed table), prints what was matched, what has no
  counterpart and what is ambiguous, and writes only `description` and `image` in one transaction. Existing
  values are kept unless `--force`. Run it with `--dry-run` first.
- **MCP.** Descriptions can be imported with `catering_menu_import`; photos cannot.

## Docs for agents

`catering_menu` with `event_slug` lists the event's open and scheduled orders, their menu options and prices in cents.

<div class="callout-live">
  <strong>Kitchen list.</strong> The CSV export and the per-option counts come from the same
  read-model as the participant total, so the kitchen and the payer never disagree.
</div>
