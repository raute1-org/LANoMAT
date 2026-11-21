<?php

namespace App\Modules\Catering\Actions;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Support\Csv\CsvSafe;

/**
 * One row per ordered item, for the kitchen and the cashier. Written for German
 * Excel: UTF-8 with a BOM, semicolon separated, amounts with a decimal comma.
 *
 * Participants write the note and the name is user-controlled, so cells that a
 * spreadsheet would read as a formula are prefixed with an apostrophe.
 */
class ExportFoodOrderItemsCsv
{
    public function handle(FoodOrder $order): string
    {
        /** @var array<string, MenuOption> $menu */
        $menu = collect($order->menu)->keyBy(fn (MenuOption $option): string => $option->key)->all();

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the food order export.');
        }

        fputcsv($handle, ['Teilnehmer', 'Option', 'Anmerkung', 'Preis (EUR)', 'Bezahlt', 'Im Ticket enthalten'], ';', '"', '', "\r\n");

        $items = $order->items()->with('user')->orderBy('id')->get();

        foreach ($items as $item) {
            $optionKey = (string) ($item->selection['option_key'] ?? '');
            $optionName = isset($menu[$optionKey]) ? $menu[$optionKey]->name : $optionKey;

            fputcsv($handle, [
                CsvSafe::cell((string) ($item->user->name ?? '')),
                CsvSafe::cell($optionName),
                CsvSafe::cell((string) ($item->selection['note'] ?? '')),
                number_format($item->price_cents / 100, 2, ',', ''),
                $item->paid_at !== null ? 'ja' : 'nein',
                $item->included ? 'ja' : 'nein',
            ], ';', '"', '', "\r\n");
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".($csv === false ? '' : $csv);
    }
}
