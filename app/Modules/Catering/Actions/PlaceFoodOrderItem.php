<?php

namespace App\Modules\Catering\Actions;

use App\Models\User;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\FoodOrderRegistrationRequirement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class PlaceFoodOrderItem
{
    public function handle(FoodOrder $order, User $user, string $optionKey, ?string $note = null): FoodOrderItem
    {
        return DB::transaction(function () use ($order, $user, $optionKey, $note): FoodOrderItem {
            // Lock EVERY order of the event, in id order, before reading any
            // of them. The per-user item limit is counted across the event's
            // open orders, so two parallel clicks on different orders of the
            // same event must serialize here (F-R2B-13). The fixed lock order
            // avoids deadlocks between concurrent placements.
            $orderId = $order->getKey();
            $eventOrders = FoodOrder::query()
                ->where('event_id', $order->event_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $order = $eventOrders->firstWhere('id', $orderId)
                ?? throw (new ModelNotFoundException)->setModel(FoodOrder::class, [$orderId]);

            if (! $order->isOpenNow()) {
                throw CateringException::notOpen();
            }

            $option = collect($order->menu)->first(fn ($menuOption) => $menuOption->key === $optionKey);

            if ($option === null) {
                throw CateringException::unknownOption($optionKey);
            }

            $event = $order->event()->firstOrFail();

            if (! FoodOrderRegistrationRequirement::satisfiedBy($user, $event)) {
                throw CateringException::registrationRequired();
            }

            $limit = (int) config('lanomat.limits.food_items_per_user', 10);
            $placed = FoodOrderItem::query()
                ->whereIn('food_order_id', $eventOrders->where('status', FoodOrderStatus::Open)->modelKeys())
                ->where('user_id', $user->id)
                ->count();

            if ($placed >= $limit) {
                throw CateringException::itemLimitReached($limit);
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
