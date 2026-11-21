<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Support\FoodIncluded;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Support\MyRegistration;

/**
 * Re-evaluates the `included` flag of the UNPAID items after the orga changed
 * the round's include-list. Paid items and closed rounds are never touched.
 *
 * @return int number of items whose flag changed
 */
class RecomputeIncludedItems
{
    public function handle(FoodOrder $order): int
    {
        if ($order->status === FoodOrderStatus::Closed) {
            return 0;
        }

        $event = Event::query()->findOrFail($order->event_id);
        $changed = 0;
        $ticketByUser = [];

        $items = $order->items()->whereNull('paid_at')->get();

        foreach ($items as $item) {
            $userId = $item->user_id;

            if (! array_key_exists($userId, $ticketByUser)) {
                $registration = $item->user === null ? null : MyRegistration::find($event, $item->user);
                $ticketByUser[$userId] = $registration?->ticket_type;
            }

            $included = FoodIncluded::for($order, $ticketByUser[$userId]);

            if ($item->included !== $included) {
                $item->included = $included;
                $item->save();
                $changed++;
            }
        }

        return $changed;
    }
}
