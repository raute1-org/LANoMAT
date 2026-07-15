<?php

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Models\FoodOrder;

class CostSplit
{
    /**
     * @return array{
     *     perUser: array<int, array{name: string, totalCents: int, paidCents: int}>,
     *     grandTotalCents: int,
     *     byOption: array<string, array{name: string, count: int, totalCents: int}>,
     * }
     */
    public static function for(FoodOrder $order): array
    {
        $items = $order->items()->with('user')->get();
        $menuByKey = collect($order->menu)->keyBy('key');

        $perUser = [];
        $byOption = [];
        $grandTotalCents = 0;

        foreach ($items as $item) {
            $grandTotalCents += $item->price_cents;

            $userId = $item->user_id;
            $perUser[$userId] ??= [
                'name' => $item->user->name ?? '',
                'totalCents' => 0,
                'paidCents' => 0,
            ];
            $perUser[$userId]['totalCents'] += $item->price_cents;
            if ($item->paid_at !== null) {
                $perUser[$userId]['paidCents'] += $item->price_cents;
            }

            $optionKey = $item->selection['option_key'] ?? null;
            if ($optionKey !== null) {
                $menuOption = $menuByKey->get($optionKey);
                $optionName = $menuOption !== null ? $menuOption->name : $optionKey;
                $byOption[$optionKey] ??= [
                    'name' => $optionName,
                    'count' => 0,
                    'totalCents' => 0,
                ];
                $byOption[$optionKey]['count']++;
                $byOption[$optionKey]['totalCents'] += $item->price_cents;
            }
        }

        return [
            'perUser' => $perUser,
            'grandTotalCents' => $grandTotalCents,
            'byOption' => $byOption,
        ];
    }
}
