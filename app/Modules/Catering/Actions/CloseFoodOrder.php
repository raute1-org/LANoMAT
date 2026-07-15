<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use Illuminate\Support\Facades\DB;

class CloseFoodOrder
{
    public function handle(FoodOrder $order): FoodOrder
    {
        return DB::transaction(function () use ($order): FoodOrder {
            // Lock the FoodOrder row first, mirroring RegisterForEvent /
            // PlaceFoodOrderItem: this serializes concurrent status
            // transitions (and a concurrent placement checking isOpenNow())
            // against this close, so the status guard below is always read
            // after any concurrent writer has committed or rolled back.
            $order = FoodOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->status !== FoodOrderStatus::Open) {
                throw CateringException::invalidTransition($order->status, FoodOrderStatus::Closed);
            }

            $order->status = FoodOrderStatus::Closed;
            $order->save();

            return $order;
        });
    }
}
