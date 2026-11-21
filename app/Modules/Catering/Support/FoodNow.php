<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Collection;

/**
 * The food block of the "Jetzt" home page: the order round open right now (closing soonest)
 * and the next round that opens later. Shape matches `FoodNowDto` in the Now page props.
 */
final class FoodNow
{
    /**
     * @return array{
     *     open: null|array{title: string, closesAt: string|null, orderCount: int, mine: string|null, href: string},
     *     next: null|array{title: string, opensAt: string}
     * }
     */
    public static function for(Event $event, User $viewer): array
    {
        $rounds = ListedFoodOrders::forEvent($event);

        return [
            'open' => self::open($event, $viewer, $rounds),
            'next' => self::next($rounds),
        ];
    }

    /**
     * @param  Collection<int, FoodOrder>  $rounds
     * @return array{title: string, closesAt: string|null, orderCount: int, mine: string|null, href: string}|null
     */
    private static function open(Event $event, User $viewer, Collection $rounds): ?array
    {
        $order = $rounds
            ->filter(fn (FoodOrder $order): bool => $order->isOpenNow())
            ->sortBy(fn (FoodOrder $order): int => $order->closes_at?->getTimestamp() ?? PHP_INT_MAX)
            ->first();

        if (! $order instanceof FoodOrder) {
            return null;
        }

        $items = FoodOrderItem::query()
            ->where('food_order_id', $order->id)
            ->orderBy('id')
            ->get(['user_id', 'selection']);

        return [
            'title' => $order->title,
            'closesAt' => $order->closes_at?->toIso8601String(),
            'orderCount' => $items->pluck('user_id')->unique()->count(),
            'mine' => self::summarize($order, $items->where('user_id', $viewer->id)),
            'href' => route('catering.show', $event),
        ];
    }

    /**
     * @param  Collection<int, FoodOrder>  $rounds
     * @return array{title: string, opensAt: string}|null
     */
    private static function next(Collection $rounds): ?array
    {
        $order = $rounds
            ->filter(fn (FoodOrder $order): bool => $order->status === FoodOrderStatus::Open
                && $order->opens_at !== null
                && $order->opens_at->isFuture())
            ->sortBy(fn (FoodOrder $order): int => $order->opens_at?->getTimestamp() ?? PHP_INT_MAX)
            ->first();

        if (! $order instanceof FoodOrder || $order->opens_at === null) {
            return null;
        }

        return [
            'title' => $order->title,
            'opensAt' => $order->opens_at->toIso8601String(),
        ];
    }

    /**
     * "2× Margherita, 1× Cola" for the viewer's items, grouped by option in first-ordered order.
     *
     * @param  Collection<int, FoodOrderItem>  $items
     */
    private static function summarize(FoodOrder $order, Collection $items): ?string
    {
        if ($items->isEmpty()) {
            return null;
        }

        $names = collect($order->menu)->mapWithKeys(
            fn (MenuOption $option): array => [$option->key => $option->name],
        );

        return $items
            ->groupBy(fn (FoodOrderItem $item): string => (string) ($item->selection['option_key'] ?? ''))
            ->map(fn (Collection $group, string $key): string => $group->count().'× '.($names[$key] ?? $key))
            ->implode(', ');
    }
}
