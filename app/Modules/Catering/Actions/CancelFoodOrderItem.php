<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use Illuminate\Support\Facades\DB;

class CancelFoodOrderItem
{
    public function handle(FoodOrderItem $item): void
    {
        DB::transaction(function () use ($item): void {
            // Lock the parent FoodOrder row first, same reasoning as
            // PlaceFoodOrderItem: serialize against a concurrent
            // open/close transition before trusting isOpenNow().
            $order = FoodOrder::query()->whereKey($item->food_order_id)->lockForUpdate()->firstOrFail();

            if (! $order->isOpenNow()) {
                throw CateringException::notOpen();
            }

            $item->delete();
        });
    }
}
