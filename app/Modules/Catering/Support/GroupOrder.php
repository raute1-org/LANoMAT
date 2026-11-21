<?php

namespace App\Modules\Catering\Support;

use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Support\Money\Euro;

/**
 * The consolidated order for one food round: what to tell the restaurant
 * (kitchen list, no guest names), who owes what (person list), and a plain
 * text to paste into a chat or mail. Pure, IO-free: it only reads the items
 * and their already-loaded `user` relation.
 *
 * All access to the menu (dish name and category) goes through
 * {@see self::dishesOf()}, the one place to change when the menu moves away
 * from the round.
 */
final class GroupOrder
{
    public const OTHER_CATEGORY = 'Weitere';

    private const SEPARATOR = ': ';

    private const MAX_PREFIX_LENGTH = 32;

    /**
     * @param  list<array{label: string, lines: list<array{key: string, name: string, quantity: int, unitCents: int, totalCents: int, notes: list<array{note: string, count: int}>, plain: int, parts: list<array{name: string, count: int}>}>}>  $categories
     * @param  list<array{userId: int, name: string, dishes: list<string>, totalCents: int, includedCents: int, paidCents: int, dueCents: int, state: 'paid'|'open'|'included'}>  $persons
     */
    private function __construct(
        public readonly string $title,
        public readonly array $categories,
        public readonly int $positions,
        public readonly int $totalCents,
        public readonly array $persons,
        public readonly int $dueCents,
        public readonly int $paidCents,
        public readonly int $includedCents,
    ) {}

    public static function for(FoodOrder $order): self
    {
        $order->loadMissing('items.user');

        return self::build($order->title, self::dishesOf($order), array_values($order->items->all()));
    }

    /**
     * Dish name by option key. The single seam to the menu storage.
     *
     * @return array<string, string>
     */
    public static function dishesOf(FoodOrder $order): array
    {
        $names = [];

        foreach ($order->menuOptions() as $option) {
            $names[$option->key] = $option->name;
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $dishes  option key => "Category: Dish", in menu order
     * @param  list<FoodOrderItem>  $items
     */
    public static function build(string $title, array $dishes, array $items): self
    {
        $order = array_flip(array_keys($dishes));

        /** @var array<string, array{key: string, name: string, category: string, unit: int, quantity: int, notes: array<string, int>, plain: int, parts: array<string, int>}> $lines */
        $lines = [];
        /** @var array<int, array{name: string, dishes: array<string, int>, total: int, included: int, paid: int, due: int}> $people */
        $people = [];
        $positions = 0;
        $total = 0;
        $paid = 0;
        $due = 0;
        $includedTotal = 0;

        foreach ($items as $item) {
            $key = (string) ($item->selection['option_key'] ?? '');
            $note = self::cleanNote((string) ($item->selection['note'] ?? ''));
            $cents = $item->price_cents;
            $full = DishNames::forItem($item, $dishes);
            [$category, $name] = self::split($full);

            $lineId = $key."\0".$cents;
            $lines[$lineId] ??= ['key' => $key, 'name' => $name, 'category' => $category, 'unit' => $cents, 'quantity' => 0, 'notes' => [], 'plain' => 0, 'parts' => []];
            $lines[$lineId]['quantity']++;
            if ($note === '') {
                $lines[$lineId]['plain']++;
            } else {
                $lines[$lineId]['notes'][$note] = ($lines[$lineId]['notes'][$note] ?? 0) + 1;
            }

            // Set menus: the picked dishes are counted under the menu line; only the menu has a price.
            $parts = [];
            foreach (ItemPicks::of($item) as $pick) {
                $part = self::split($pick['name'])[1];
                $parts[$part] = ($parts[$part] ?? 0) + 1;
                $lines[$lineId]['parts'][$part] = ($lines[$lineId]['parts'][$part] ?? 0) + 1;
            }

            $positions++;
            $total += $cents;

            $userId = $item->user_id;
            $people[$userId] ??= [
                'name' => $item->relationLoaded('user') && $item->user !== null ? $item->user->displayNameFor() : '',
                'dishes' => [], 'total' => 0, 'included' => 0, 'paid' => 0, 'due' => 0,
            ];
            $personDish = $name.self::partsSuffix($parts);
            $people[$userId]['dishes'][$personDish] = ($people[$userId]['dishes'][$personDish] ?? 0) + 1;
            $people[$userId]['total'] += $cents;

            if ($item->included) {
                $people[$userId]['included'] += $cents;
                $includedTotal += $cents;
            } elseif ($item->paid_at !== null) {
                $people[$userId]['paid'] += $cents;
                $paid += $cents;
            } else {
                $people[$userId]['due'] += $cents;
                $due += $cents;
            }
        }

        // Categories in menu order (first dish of the category), unknown dishes last.
        $categories = [];
        uasort($lines, fn (array $a, array $b): int => [$order[$a['key']] ?? PHP_INT_MAX, $a['unit']] <=> [$order[$b['key']] ?? PHP_INT_MAX, $b['unit']]);

        foreach ($lines as $line) {
            $categories[$line['category']] ??= ['label' => $line['category'], 'lines' => []];
            $notes = [];
            foreach ($line['notes'] as $text => $count) {
                $notes[] = ['note' => (string) $text, 'count' => $count];
            }
            $parts = [];
            foreach (self::sortedParts($line['parts']) as $part => $count) {
                $parts[] = ['name' => (string) $part, 'count' => $count];
            }
            $categories[$line['category']]['lines'][] = [
                'key' => $line['key'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unitCents' => $line['unit'],
                'totalCents' => $line['unit'] * $line['quantity'],
                'notes' => $notes,
                'plain' => $line['plain'],
                'parts' => $parts,
            ];
        }

        // "Weitere" (no prefix / unknown) always at the end.
        if (isset($categories[self::OTHER_CATEGORY])) {
            $other = $categories[self::OTHER_CATEGORY];
            unset($categories[self::OTHER_CATEGORY]);
            $categories[self::OTHER_CATEGORY] = $other;
        }

        $persons = [];
        foreach ($people as $userId => $p) {
            $dishList = [];
            foreach ($p['dishes'] as $dish => $count) {
                $dishList[] = $count > 1 ? $count.'× '.$dish : $dish;
            }
            $persons[] = [
                'userId' => $userId,
                'name' => $p['name'],
                'dishes' => $dishList,
                'totalCents' => $p['total'],
                'includedCents' => $p['included'],
                'paidCents' => $p['paid'],
                'dueCents' => $p['due'],
                'state' => $p['due'] > 0 ? 'open' : ($p['paid'] > 0 ? 'paid' : 'included'),
            ];
        }
        usort($persons, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return new self($title, array_values($categories), $positions, $total, $persons, $due, $paid, $includedTotal);
    }

    /**
     * Plain text for the restaurant: German labels, no guest names, no markup.
     */
    public function text(): string
    {
        $out = [self::oneLine($this->title), ''];

        foreach ($this->categories as $category) {
            $out[] = self::oneLine($category['label']);
            foreach ($category['lines'] as $line) {
                $out[] = $this->kitchenLine($line).' ('.Euro::format($line['totalCents']).')';
            }
            $out[] = '';
        }

        $out[] = 'Gesamt: '.$this->positions.' '.($this->positions === 1 ? 'Position' : 'Positionen').', '.Euro::format($this->totalCents);

        return implode("\n", $out)."\n";
    }

    /**
     * "2× Chicken Curry (1× mild, 1× scharf)" for one kitchen line.
     *
     * A set menu lists its counted picks after the name: "2× Menü für 2 Personen: 3× Palak, 1× Dal".
     *
     * @param  array{name: string, quantity: int, notes: list<array{note: string, count: int}>, plain: int, parts?: list<array{name: string, count: int}>}  $line
     */
    public function kitchenLine(array $line): string
    {
        $text = $line['quantity'].'× '.self::oneLine($line['name']);
        $parts = $line['parts'] ?? [];

        if ($parts !== []) {
            $text .= ': '.implode(', ', array_map(fn (array $p): string => $p['count'].'× '.self::oneLine($p['name']), $parts));
        }

        if ($line['notes'] === []) {
            return $text;
        }

        $parts = array_map(fn (array $n): string => $n['count'].'× '.$n['note'], $line['notes']);
        if ($line['plain'] > 0) {
            $parts[] = $line['plain'].'× ohne Anmerkung';
        }

        return $text.' ('.implode(', ', $parts).')';
    }

    /** @return array{0: string, 1: string} */
    public static function split(string $name): array
    {
        $index = strpos($name, self::SEPARATOR);

        if ($index === false || $index <= 0 || $index > self::MAX_PREFIX_LENGTH) {
            return [self::OTHER_CATEGORY, $name];
        }

        $label = trim(substr($name, $index + strlen(self::SEPARATOR)));

        return $label === '' ? [self::OTHER_CATEGORY, $name] : [trim(substr($name, 0, $index)), $label];
    }

    /**
     * @param  array<string, int>  $parts
     * @return array<string, int> most picked first, then by name
     */
    private static function sortedParts(array $parts): array
    {
        uksort($parts, fn (string|int $a, string|int $b): int => [-$parts[$a], (string) $a] <=> [-$parts[$b], (string) $b]);

        return $parts;
    }

    /** @param  array<string, int>  $parts */
    private static function partsSuffix(array $parts): string
    {
        if ($parts === []) {
            return '';
        }

        $text = [];
        foreach (self::sortedParts($parts) as $part => $count) {
            $text[] = $count > 1 ? $count.'× '.$part : (string) $part;
        }

        return ' ('.implode(', ', $text).')';
    }

    private static function cleanNote(string $note): string
    {
        return self::oneLine($note);
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
