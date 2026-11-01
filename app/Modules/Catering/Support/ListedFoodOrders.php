<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which food orders of an event a participant sees: open and closed ones, never drafts,
 * newest first. Shared by the participant page and the native client API.
 */
final class ListedFoodOrders
{
    /**
     * @return Collection<int, FoodOrder>
     */
    public static function forEvent(Event $event): Collection
    {
        return FoodOrder::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [FoodOrderStatus::Open->value, FoodOrderStatus::Closed->value])
            ->orderByDesc('created_at')
            ->get();
    }
}
