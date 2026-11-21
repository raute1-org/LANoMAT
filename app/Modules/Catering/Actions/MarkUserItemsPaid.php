<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Models\FoodOrder;
use Illuminate\Support\Carbon;

/**
 * "Alles bezahlt": marks every due item (not included in the ticket, not yet
 * paid) of one person in the round as paid.
 *
 * @return int number of items marked
 */
class MarkUserItemsPaid
{
    public function handle(FoodOrder $order, int $userId): int
    {
        return $order->items()
            ->where('user_id', $userId)
            ->where('included', false)
            ->whereNull('paid_at')
            ->update(['paid_at' => Carbon::now()]);
    }
}
