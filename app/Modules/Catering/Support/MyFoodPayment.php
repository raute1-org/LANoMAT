<?php

namespace App\Modules\Catering\Support;

use App\Models\User;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Support\PaymentLink;
use App\Modules\Registration\Support\PaymentReference;
use Illuminate\Support\Collection;

/**
 * What the viewer owes for one food round and how to pay it. Built only from the
 * viewer's own items (never other users').
 */
class MyFoodPayment
{
    /**
     * @param  Collection<int, FoodOrderItem>  $myItems  the viewer's items of this round
     * @param  EventPaymentSettings|null  $settings  the event's payment settings when the caller already has them (avoids one query per round)
     * @return array{method: string, dueCents: int, url: string|null, reference: string|null}|null
     */
    public static function for(FoodOrder $order, Event $event, User $viewer, Collection $myItems, ?EventPaymentSettings $settings = null): ?array
    {
        if ($myItems->isEmpty()) {
            return null;
        }

        // Item flags are the single source (same as CostSplit, the export and the admin).
        if ($myItems->every(fn (FoodOrderItem $item): bool => $item->included)) {
            return ['method' => 'included', 'dueCents' => 0, 'url' => null, 'reference' => null];
        }

        $dueCents = (int) $myItems
            ->filter(fn (FoodOrderItem $item): bool => ! $item->included && $item->paid_at === null)
            ->sum('price_cents');

        if ($order->payment_method !== FoodPaymentMethod::Paypal) {
            return ['method' => 'cash', 'dueCents' => $dueCents, 'url' => null, 'reference' => null];
        }

        // The round link is only read for paypal rounds (it stays stored when the orga switches to cash).
        $link = $order->payment_link ?: ($settings ?? EventPaymentSettings::forEvent($event))->payment_link;

        return [
            'method' => 'paypal',
            'dueCents' => $dueCents,
            'url' => PaymentLink::for($link, $dueCents),
            'reference' => $dueCents > 0 ? PaymentReference::for($event->name, $viewer->name, $order->title) : null,
        ];
    }
}
