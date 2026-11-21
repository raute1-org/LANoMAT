---
title: Catering
description: Menus, per-person orders, option limits, cost splitting and ready notifications.
sidebar_icon: utensils
---

`Catering` handles group food orders for one event. The menu belongs to a **restaurant** (an event-spanning
restaurant or delivery service); a food round of an event points at it. The orga opens the round, participants
place their orders, the kitchen gets a list and the group sees a cost split.

## Restaurants

- A **restaurant** (Filament *Restaurants*, next to *Essensrunden*) holds name, slug, short description, logo,
  contact (phone, address, website, order link; admin only), a default payment (cash or PayPal link) that
  pre-fills new rounds, notes (orga only) and the **menu**. Rounds keep their own payment settings.
- Participants see a restaurant only in the head of a round: name, logo and short description. Phone, address,
  links, notes and payment defaults never leave the admin (and the Inertia/native payloads are allow-listed).
- The round form picks the restaurant (required for new rounds; inline "create restaurant"); title and payment
  are pre-filled and stay editable. The restaurant of a round cannot be switched once it has orders.
- **Menu editor.** A paged table grouped by the category prefix ("Pizza: Margherita"), with search, category
  filter and one modal per dish (name, price in euros, description, photo). The dish key is generated from the
  name and never changes. A dish that appears in any order cannot be deleted or re-keyed ("N Bestellungen");
  changing its price is allowed and shows "N offene Positionen", with an opt-in checkbox that also reprices unpaid
  positions (paid and ticket-included ones never change). The rule lives in the `UpdateRestaurantMenu` action, so
  the admin, MCP and artisan share it.
- A new order stores the dish name on the item (`selection.name`) next to the price snapshot, so later menu
  changes keep historic orders readable. Names resolve from the item, then the menu, then the key.
- **Schema note.** A dish is a JSON object (`key`, `name`, `price_cents`, `description`, `image`); optional fields
  can be added later (for example `choices` for set menus with selectable parts) without a migration.
- **Legacy.** `food_orders.menu` stays as a legacy column: rounds without a restaurant still read it, and a
  rollback restores it from the restaurants. See `docs/restaurant-migration.md` in the repository.

## Who uses it

- **Teilnehmer** open the menu, choose options, see their total and mark an order as received.
- **Orga** creates the order window, edits the menu, closes it, exports the kitchen list and marks
  items ready.

## Key screens

- `/events/{slug}/catering` — open menus, place or change an order, my total.
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
- **Delivery.** `GET /catering/restaurant-image/{restaurant}/{key}` (logo: `/catering/restaurant-logo/{restaurant}`)
  streams the file with `Cache-Control: public, max-age=86400`. No login (like the public gallery photos), but
  only when a published round of a publicly visible event uses the restaurant and the dish has a photo. The old
  `/catering/dish-image/{round}/{key}` URL stays as an alias; everything else is a 404. The page URL carries `?v=<hash>` so a replaced photo
  is not stale. No `storage:link` is needed.
- **Admin.** The menu editor of a restaurant has a description field and a photo upload per dish (images only,
  max 3 MB).
- **Bulk assignment.** `php artisan catering:import-dish-media {restaurant} {json} --images=<folder> [--dry-run] [--force]`
  (the first argument is the restaurant, slug or id; before restaurants existed it was a round id, so an old call
  `catering:import-dish-media <round-id> ...` must become `catering:import-dish-media - ... --round=<round-id>`, which resolves
  the restaurant of that round)
  reads a JSON list of `{category, name, description, image_file}`, matches the dishes of the restaurant by
  normalised name (narrowed by category through a fixed table), prints what was matched, what has no
  counterpart and what is ambiguous, and writes only `description` and `image` in one transaction. Existing
  values are kept unless `--force`. Run it with `--dry-run` first.
- **MCP.** Descriptions can be imported with `catering_menu_import` (into the restaurant); photos cannot.

## Set menus ("Menü für 2 Personen")

Delivery services sell menus where the customer picks the components. A dish (`MenuOption`) can carry optional
`choices`: up to 5 selection groups `{label, count, allow_repeat, categories | options}`. `count` (1 to 10) is the
exact number of picks; `categories` offers every ordinary dish whose name prefix ("Hähnchen: Korma") is listed,
`options` offers a fixed list of dish keys. Dishes with choices of their own are never offered. The price stays the
menu price; there is no surcharge per pick. Old menus without `choices` and old orders without picks are
unchanged (nothing was migrated).

**Orga setup (example).** In the menu editor open the dish "Menü: Menü für 2 Personen" and expand **Auswahl**:
add the group "Hauptgericht", Anzahl 2, Auswahl aus *Kategorien der Karte*, categories `Hähnchen` and
`Vegetarisch` (the preview shows "N Optionen"), then the group "Beilage", Anzahl 1, *Bestimmte Gerichte*
`Reis` and `Naan`, repeats off. Save; the menu is now orderable with a picker.

- **Ordering.** The page shows "+" as "Menü zusammenstellen": a dialog with one block per group, progress
  ("1 von 2 gewählt"), a radio list for one pick, checkboxes without repeats, steppers with repeats and a search
  for long lists. Ordering is possible only with a complete selection; the server checks count, membership and
  repeats again (`PlaceFoodOrderItem`) and refuses otherwise. The item stores
  `selection.choices: [{group, option_key, name}]` (name snapshot). The receipt shows the picks as indented
  lines under the menu; identical menus with the same picks are grouped, different picks stay separate; removing
  removes the whole menu item.
- **Protection.** A dish that was picked inside an ordered set menu is protected like an ordered dish (no delete,
  no key change); `UpdateRestaurantMenu` also rejects groups that point at missing dishes.
- **Kitchen list and CSV.** The group order counts the picks under the menu ("2× Menü für 2 Personen: 3× Palak
  Mushroom, 1× Dal Palak"), prices stay on the menu; the person list shows the picks per person; the item CSV
  gets an "Auswahl" column once a round has set menus.
- **MCP and native client.** `catering_menu_get/preview/import` take and return `choices` (see MCP tools); the
  native client API adds `choices` to menu entries and items (additive, `api_version` 1).

## Docs for agents

`catering_menu` with `event_slug` lists the event's open and scheduled orders, their menu options and prices in cents.

<div class="callout-live">
  <strong>Kitchen list.</strong> The CSV export and the per-option counts come from the same
  read-model as the participant total, so the kitchen and the payer never disagree.
</div>
