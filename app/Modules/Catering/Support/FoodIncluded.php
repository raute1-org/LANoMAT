<?php

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Models\FoodOrder;

/**
 * Whether a food round is covered by a ticket type (e.g. a flatrate ticket).
 * Compared by the stable ticket key stored on the registration.
 */
class FoodIncluded
{
    public static function for(FoodOrder $order, ?string $ticketKey): bool
    {
        if ($ticketKey === null || $ticketKey === '') {
            return false;
        }

        return in_array($ticketKey, $order->included_ticket_types, true);
    }
}
