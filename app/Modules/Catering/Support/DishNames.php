<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Models\FoodOrderItem;

/**
 * The one place that turns an ordered item into a dish name: the name snapshotted on the item
 * when it was placed, else the current menu, else the bare key. A restaurant menu may change or
 * lose a dish later; the snapshot keeps historic orders readable.
 */
final class DishNames
{
    /** @param  array<string, string>  $menuNames  option key => name */
    public static function forItem(FoodOrderItem $item, array $menuNames): string
    {
        $key = (string) ($item->selection['option_key'] ?? '');
        $snapshot = $item->selection['name'] ?? null;

        if (is_string($snapshot) && $snapshot !== '') {
            return $snapshot;
        }

        return $menuNames[$key] ?? $key;
    }
}
