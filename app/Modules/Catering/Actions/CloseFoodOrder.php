<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use DomainException;

class CloseFoodOrder
{
    public function handle(FoodOrder $order): FoodOrder
    {
        if ($order->status !== FoodOrderStatus::Open) {
            throw new DomainException(
                "Illegal food order status transition from {$order->status->value} to closed."
            );
        }

        $order->status = FoodOrderStatus::Closed;
        $order->save();

        return $order;
    }
}
