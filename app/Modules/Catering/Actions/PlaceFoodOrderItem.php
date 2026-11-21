<?php

namespace App\Modules\Catering\Actions;

use App\Models\User;
use App\Modules\Catering\Domain\MenuChoices;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\FoodIncluded;
use App\Modules\Catering\Support\FoodOrderRegistrationRequirement;
use App\Modules\Registration\Support\MyRegistration;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class PlaceFoodOrderItem
{
    /**
     * @param  list<array{group?: mixed, option_key?: mixed}>|null  $choices  the picks of a set menu (ignored for an ordinary dish)
     */
    public function handle(FoodOrder $order, User $user, string $optionKey, ?string $note = null, ?array $choices = null): FoodOrderItem
    {
        return DB::transaction(function () use ($order, $user, $optionKey, $note, $choices): FoodOrderItem {
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

            // Lock order: the event's rounds, then the restaurant row. The menu-write action takes the
            // same restaurant lock, so "remove this dish" and "order this dish" cannot interleave.
            if ($order->restaurant_id !== null) {
                $order->setRelation('restaurant', Restaurant::query()->whereKey($order->restaurant_id)->lockForUpdate()->first());
            }

            $option = collect($order->menuOptions())->first(fn ($menuOption) => $menuOption->key === $optionKey);

            if ($option === null) {
                throw CateringException::unknownOption($optionKey);
            }

            // A set menu is only ever stored with a complete, valid selection (checked against the
            // menu as it is right now, under the restaurant lock); picks are name snapshots.
            $picked = $option->isSetMenu() ? MenuChoices::resolveSelection($option, $order->menuOptions(), $choices) : [];

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
                    // Name snapshot: later menu edits or removals keep this order readable.
                    'name' => $option->name,
                    'note' => $note,
                    ...($picked === [] ? [] : ['choices' => $picked]),
                ],
            ]);
            $item->included = FoodIncluded::for($order, MyRegistration::find($event, $user)?->ticket_type);
            $item->save();

            return $item;
        });
    }
}
