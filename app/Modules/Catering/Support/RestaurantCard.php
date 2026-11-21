<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Models\Restaurant;

/**
 * The participant-facing view of a restaurant: name, short description and logo, nothing else.
 * Phone, address, links, payment defaults and notes stay in the admin; this allow-list is the
 * only way restaurant data reaches participants (page and native client).
 */
final class RestaurantCard
{
    /** @return array{name: string, description: string|null, logoUrl: string|null}|null */
    public static function for(?Restaurant $restaurant): ?array
    {
        if ($restaurant === null) {
            return null;
        }

        return [
            'name' => $restaurant->name,
            'description' => $restaurant->description,
            'logoUrl' => DishImage::logoUrl($restaurant),
        ];
    }

    /** @return array{name: string, description: string|null, logo_url: string|null}|null */
    public static function forNative(?Restaurant $restaurant): ?array
    {
        $card = self::for($restaurant);

        return $card === null ? null : [
            'name' => $card['name'],
            'description' => $card['description'],
            'logo_url' => $card['logoUrl'],
        ];
    }
}
