<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Models\FoodOrderItem;

/** The picks of a set menu as stored on an order item (name snapshots); empty for an ordinary dish. */
final class ItemPicks
{
    /**
     * @return list<array{group: string, optionKey: string, name: string}>
     */
    public static function of(FoodOrderItem $item): array
    {
        // Stored JSON: read defensively, whatever an older writer left in there.
        $selection = $item->getAttribute('selection');
        $picks = is_array($selection) ? ($selection['choices'] ?? []) : [];
        $out = [];

        foreach (is_array($picks) ? $picks : [] as $pick) {
            if (is_array($pick) && is_string($pick['group'] ?? null) && is_string($pick['option_key'] ?? null)) {
                $out[] = [
                    'group' => $pick['group'],
                    'optionKey' => $pick['option_key'],
                    'name' => is_string($pick['name'] ?? null) ? $pick['name'] : $pick['option_key'],
                ];
            }
        }

        return $out;
    }
}
