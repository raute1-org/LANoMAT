<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;

/** The public URL of a dish photo (null when the dish has none or its key cannot be routed). */
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
        return route('catering.dish-image', [
            'foodOrder' => $order->id,
            'key' => $option->key,
            'v' => basename($option->image, '.webp'),
        ]);
    }
}
