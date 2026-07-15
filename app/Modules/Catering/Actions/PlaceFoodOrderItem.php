<?php

namespace App\Modules\Catering\Actions;

use App\Models\User;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use Illuminate\Support\Facades\DB;

class PlaceFoodOrderItem
{
    public function handle(FoodOrder $order, User $user, string $optionKey, ?string $note = null): FoodOrderItem
    {
        return DB::transaction(function () use ($order, $user, $optionKey, $note): FoodOrderItem {
            // Lock the parent FoodOrder row first, mirroring
            // RegisterForEvent: this serializes all placements against a
            // concurrent open/close transition so isOpenNow() below is
            // always read after any concurrent status-changing writer has
            // committed or rolled back.
            $order = FoodOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $order->isOpenNow()) {
                throw CateringException::notOpen();
            }

            $option = collect($order->menu)->first(fn ($menuOption) => $menuOption->key === $optionKey);

            if ($option === null) {
                throw CateringException::unknownOption($optionKey);
            }

            $item = new FoodOrderItem([
                'food_order_id' => $order->id,
                'user_id' => $user->id,
                // price_cents is always copied from the resolved MenuOption,
                // never from client input — the Action signature doesn't
                // even accept a price argument.
                'price_cents' => $option->priceCents,
                'selection' => [
                    'option_key' => $optionKey,
                    'note' => $note,
                ],
            ]);
            $item->save();

            return $item;
        });
    }
}
