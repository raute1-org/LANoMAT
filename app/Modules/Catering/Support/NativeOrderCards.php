<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use App\Modules\WebVoice\Contracts\OrderCardSource;
use App\Modules\WebVoice\Domain\NowCard;
use Illuminate\Support\Facades\Gate;

/**
 * The native client's "now" cards for Catering: an order round the user could still join
 * (open, closing soon, nothing ordered yet, confirmed registration). There is no "order ready"
 * state on an order, only the `FoodOrderReady` in-app notification, which the feed shows as an
 * ordinary notification card.
 */
class NativeOrderCards implements OrderCardSource
{
    /** Cards appear when the ordering window closes within this many minutes. */
    private const CLOSING_SOON_MINUTES = 45;

    public function cards(User $user, Event $event): array
    {
        if (! FoodOrderRegistrationRequirement::satisfiedBy($user, $event) || Gate::forUser($user)->denies('create', FoodOrderItem::class)) {
            return [];
        }

        $orders = FoodOrder::query()
            ->where('event_id', $event->id)
            ->where('status', FoodOrderStatus::Open->value)
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', now()->addMinutes(self::CLOSING_SOON_MINUTES))
            ->whereDoesntHave('items', fn ($items) => $items->where('user_id', $user->id))
            ->orderBy('closes_at')
            ->get()
            ->filter(fn (FoodOrder $order): bool => $order->isOpenNow());

        return array_values($orders->map(fn (FoodOrder $order): NowCard => new NowCard(
            kind: NowCard::KIND_ORDER,
            id: 'order:'.$order->id,
            state: 'closing_soon',
            priority: 45,
            title: trans('native_client.now.order_closing', ['title' => $order->title]),
            at: $order->closes_at,
            data: [
                'orderId' => $order->id,
                'title' => $order->title,
                'closesAt' => $order->closes_at?->toIso8601String(),
                'menu' => collect($order->menu)->map(fn (MenuOption $option): array => [
                    'key' => $option->key,
                    'name' => $option->name,
                    'priceCents' => $option->priceCents,
                ])->values()->all(),
            ],
        ))->all());
    }
}
