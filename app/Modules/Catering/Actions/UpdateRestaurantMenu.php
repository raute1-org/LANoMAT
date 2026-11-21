<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Exceptions\MenuProtectedException;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single write path of a restaurant menu (admin editor, MCP, artisan). It locks the
 * restaurant row (PlaceFoodOrderItem takes the same lock), refuses to drop or re-key any dish
 * that an order references, and optionally reprices the open positions of changed dishes.
 * The protection lives here, not in the UI: every writer gets it.
 */
class UpdateRestaurantMenu
{
    /**
     * @param  list<MenuOption>  $menu  the complete new menu
     * @param  bool  $repriceOpen  also set price_cents of unpaid, not ticket-included items of dishes whose price changed
     * @return array{open_affected: int, repriced: int} open positions touched by a price change, and how many were repriced
     *
     * @throws MenuProtectedException
     */
    public function handle(Restaurant $restaurant, array $menu, bool $repriceOpen = false): array
    {
        $keys = array_map(static fn (MenuOption $o): string => $o->key, $menu);

        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['menu' => 'Two dishes share the same key.']);
        }

        return DB::transaction(function () use ($restaurant, $menu, $repriceOpen): array {
            $locked = Restaurant::query()->whereKey($restaurant->getKey())->lockForUpdate()->firstOrFail();

            $ordered = $locked->orderedCounts();
            $newKeys = array_fill_keys(array_map(static fn (MenuOption $o): string => $o->key, $menu), true);
            $blocked = array_filter($ordered, static fn (int $n, string $key): bool => ! isset($newKeys[$key]), ARRAY_FILTER_USE_BOTH);

            if ($blocked !== []) {
                throw new MenuProtectedException($blocked);
            }

            $oldPrices = [];
            foreach ($locked->menu as $option) {
                $oldPrices[$option->key] = $option->priceCents;
            }

            $changed = [];
            foreach ($menu as $option) {
                if (isset($oldPrices[$option->key]) && $oldPrices[$option->key] !== $option->priceCents) {
                    $changed[$option->key] = $option->priceCents;
                }
            }

            $open = $locked->openCounts();
            $affected = (int) array_sum(array_intersect_key($open, $changed));
            $repriced = 0;

            if ($repriceOpen) {
                foreach ($changed as $key => $cents) {
                    $repriced += FoodOrderItem::query()
                        ->whereIn('food_order_id', $locked->foodOrders()->select('id'))
                        ->whereNull('paid_at')
                        ->where('included', false)
                        ->whereRaw("selection->>'option_key' = ?", [$key])
                        ->update(['price_cents' => $cents]);
                }
            }

            $locked->menu = $menu;
            $locked->save();
            $restaurant->setRawAttributes($locked->getAttributes(), true);

            return ['open_affected' => $affected, 'repriced' => $repriced];
        });
    }
}
