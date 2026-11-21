<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;

/** The public URL of a dish photo or restaurant logo (null when there is none or the key cannot be routed). */
final class DishImage
{
    public const KEY_PATTERN = '[A-Za-z0-9._-]{1,64}';

    public static function url(FoodOrder $order, MenuOption $option): ?string
    {
        if ($option->image === null || preg_match('/^'.self::KEY_PATTERN.'$/', $option->key) !== 1) {
            return null;
        }

        // The file name is its content hash: using it as `v` busts the 24 h browser cache when a
        // dish gets a different photo under the same key.
        $version = basename($option->image, '.webp');

        // Rounds that predate restaurants (legacy menu column) can only use the per-round route.
        if ($order->restaurant_id === null) {
            return route('catering.dish-image', ['foodOrder' => $order->id, 'key' => $option->key, 'v' => $version]);
        }

        return route('catering.restaurant-image', ['restaurant' => $order->restaurant_id, 'key' => $option->key, 'v' => $version]);
    }

    public static function logoUrl(Restaurant $restaurant): ?string
    {
        if ($restaurant->logo === null) {
            return null;
        }

        return route('catering.restaurant-logo', ['restaurant' => $restaurant->id, 'v' => basename($restaurant->logo, '.webp')]);
    }
}
