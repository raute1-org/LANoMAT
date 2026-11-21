# Restaurant migration (menus move from the round to the restaurant)

Migration `2026_11_23_100000_create_restaurants_and_link_food_orders` creates `restaurants`, adds
`food_orders.restaurant_id` (nullable, `restrictOnDelete`, indexed) and backfills it.

## Backfill

For every round without a restaurant: one restaurant per distinct (title, menu). Rounds with the same title and a
byte-identical jsonb menu share a restaurant. `name` is the round title, `slug` is made unique (`-2`, `-3`),
`menu` is the raw jsonb copy (dish keys and prices 1:1), `default_payment_*` come from the round. Ordered items are
not touched (they keep `option_key` and the `price_cents` snapshot; they get no `selection.name`, display falls
back to the menu name). The backfill is idempotent and only touches rounds with `restaurant_id IS NULL`.

`food_orders.menu` is kept as a LEGACY column: it is no longer read for rounds with a restaurant, but rounds
without one (and the factory default in tests) still use it.

## Rollback

`php artisan migrate:rollback --step=1` first copies the CURRENT restaurant menu back into `food_orders.menu` of
every linked round (so menu edits made after the migration are not lost), then drops the foreign key and the
`restaurants` table. Participant data is unaffected.

## After the deploy

- `/admin/restaurants` lists one restaurant per previous round; open the live one and check the dish count.
- The live round still shows its dishes, bookings and totals unchanged (group order page, CSV export).
- Optionally add a logo, short description and default payment on the restaurant.
- Old `/catering/dish-image/{round}/{key}` URLs keep working.
