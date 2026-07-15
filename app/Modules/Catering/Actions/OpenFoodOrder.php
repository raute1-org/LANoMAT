<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use DomainException;

class OpenFoodOrder
{
    public function handle(FoodOrder $order): FoodOrder
    {
        if ($order->status !== FoodOrderStatus::Draft) {
            throw new DomainException(
                "Illegal food order status transition from {$order->status->value} to open."
            );
        }

        $order->status = FoodOrderStatus::Open;
        $order->save();

        return $order;
    }
}
