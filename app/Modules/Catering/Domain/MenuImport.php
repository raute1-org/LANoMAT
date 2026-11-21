<?php

namespace App\Modules\Catering\Domain;

use App\Support\Money\Euro;
use App\Support\SafeText;
use Illuminate\Support\Str;

/**
 * Pure, IO-free validation of a menu that an external agent read from a PDF, a
 * web page or a photo. LANoMAT never parses those sources itself; it only
 * accepts structured data and treats every string in it as untrusted text:
 * control characters are flattened, the text is never interpreted.
 *
 * Accepted item shape: {name, price_cents | price_eur, key?, description?}. The data
 * model has no categories or allergen flags, so such fields are reported as ignored
 * instead of being dropped silently. Photos are never imported here (they are uploaded
 * in the admin panel or assigned with `catering:import-dish-media`).
 */
final class MenuImport
{
    public const MAX_ITEMS = 300;

    public const MAX_NAME_LENGTH = 255;

    public const MAX_KEY_LENGTH = 64;

    /** Prices above this are accepted (up to MenuOption::MAX_PRICE_CENTS) but flagged as unusual. */
    public const UNUSUAL_PRICE_CENTS = 5_000;

    private const KNOWN_FIELDS = ['key', 'name', 'price_cents', 'price_eur', 'description'];

    /** Echoed by catering_menu_get; accepted and dropped without a warning so get -> import round-trips. */
    private const SILENT_FIELDS = ['has_image'];

    /**
     * @param  array<int|string, mixed>  $items
     */
    public static function normalize(array $items): MenuImportResult
    {
        $errors = [];
        $warnings = [];

        if ($items === []) {
            return new MenuImportResult([], ['items: the menu has no dishes.'], []);
        }

        if (! array_is_list($items)) {
            return new MenuImportResult([], ['items: must be a list of dishes.'], []);
        }

        if (count($items) > self::MAX_ITEMS) {
            return new MenuImportResult([], ['items: at most '.self::MAX_ITEMS.' dishes per menu ('.count($items).' given).'], []);
        }

        /** @var list<array{name: string, price: int, key: ?string, description: ?string, at: int}> $rows */
        $rows = [];
        $explicitKeys = [];

        foreach ($items as $index => $item) {
            $at = "items[{$index}]";

            if (! is_array($item)) {
                $errors[] = "{$at}: must be an object with name and price.";

                continue;
            }

            $unknown = array_values(array_filter(
                array_map('strval', array_keys($item)),
                static fn (string $field): bool => ! in_array($field, [...self::KNOWN_FIELDS, ...self::SILENT_FIELDS], true),
            ));

            if ($unknown !== []) {
                $shown = implode(', ', array_map(static fn (string $field): string => (string) SafeText::line($field, 30), array_slice($unknown, 0, 5)));
                $warnings[] = "{$at}: ignored fields ({$shown}); the menu stores name, price and an optional description. Put qualifiers such as \"vegetarisch\" into the name.";
            }

            $name = self::name($item['name'] ?? null, $at, $errors, $warnings);
            $price = self::price($item, $at, $errors, $warnings);
            $key = self::key($item['key'] ?? null, $at, $errors);
            $description = self::description($item['description'] ?? null, $at, $errors, $warnings);

            if ($key !== null) {
                if (isset($explicitKeys[$key])) {
                    $errors[] = "{$at}.key: duplicate key \"{$key}\" (also used by items[{$explicitKeys[$key]}]).";
                } else {
                    $explicitKeys[$key] = $index;
                }
            }

            if ($name !== null && $price !== null) {
                $rows[] = ['name' => $name, 'price' => $price, 'key' => $key, 'description' => $description, 'at' => (int) $index];
            }
        }

        $seen = [];
        foreach ($rows as $row) {
            $fingerprint = mb_strtolower($row['name']);

            if (isset($seen[$fingerprint])) {
                $same = $seen[$fingerprint]['price'] === $row['price'];
                $warnings[] = "items[{$row['at']}]: duplicate dish name \"{$row['name']}\" (also items[{$seen[$fingerprint]['at']}]"
                    .($same ? ', same price' : ', different price').').';

                continue;
            }

            $seen[$fingerprint] = $row;
        }

        if ($errors !== []) {
            return new MenuImportResult([], $errors, $warnings);
        }

        $used = array_fill_keys(array_keys($explicitKeys), true);
        $menu = [];

        foreach ($rows as $row) {
            $key = $row['key'] ?? self::uniqueKey($row['name'], $used);
            $used[$key] = true;
            $menu[] = new MenuOption($key, $row['name'], $row['price'], $row['description']);
        }

        return new MenuImportResult($menu, [], $warnings);
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function name(mixed $raw, string $at, array &$errors, array &$warnings): ?string
    {
        if (! is_string($raw)) {
            $errors[] = "{$at}.name: required text.";

            return null;
        }

        $name = SafeText::line($raw, self::MAX_NAME_LENGTH + 1);

        if ($name === null) {
            $errors[] = "{$at}.name: must not be empty.";

            return null;
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = "{$at}.name: too long (max ".self::MAX_NAME_LENGTH.' characters).';

            return null;
        }

        if ($name !== trim($raw)) {
            $warnings[] = "{$at}.name: control characters or repeated whitespace were flattened.";
        }

        if (strip_tags($name) !== $name) {
            $warnings[] = "{$at}.name: contains markup; it is stored and shown as plain text.";
        }

        return $name;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function description(mixed $raw, string $at, array &$errors, array &$warnings): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            $errors[] = "{$at}.description: must be text.";

            return null;
        }

        // Measured on the flattened text before the bound is applied, so truncation is reported.
        $flat = SafeText::line($raw, PHP_INT_MAX);

        if ($flat === null) {
            return null;
        }

        $description = $flat;

        if (mb_strlen($flat) > MenuOption::MAX_DESCRIPTION_LENGTH) {
            $description = MenuOption::cleanDescription($flat);
            $warnings[] = "{$at}.description: longer than ".MenuOption::MAX_DESCRIPTION_LENGTH.' characters ('.mb_strlen($flat).'); it was shortened.';
        }

        if ($flat !== trim($raw)) {
            $warnings[] = "{$at}.description: control characters or repeated whitespace were flattened.";
        }

        if (strip_tags($flat) !== $flat) {
            $warnings[] = "{$at}.description: contains markup; it is stored and shown as plain text.";
        }

        return $description;
    }

    /**
     * @param  array<int|string, mixed>  $item
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function price(array $item, string $at, array &$errors, array &$warnings): ?int
    {
        $hasCents = array_key_exists('price_cents', $item) && $item['price_cents'] !== null;
        $hasEur = array_key_exists('price_eur', $item) && $item['price_eur'] !== null;

        if ($hasCents === $hasEur) {
            $errors[] = $hasCents
                ? "{$at}: give either price_cents or price_eur, not both."
                : "{$at}: price missing (price_cents as integer cents, or price_eur such as \"8,50\").";

            return null;
        }

        $cents = null;

        if ($hasCents) {
            $value = $item['price_cents'];

            if (is_int($value)) {
                $cents = $value;
            } elseif (is_string($value) && preg_match('/^\d{1,7}$/', $value) === 1) {
                $cents = (int) $value;
            }
        } else {
            $value = $item['price_eur'];

            if (is_int($value) || is_float($value)) {
                $value = number_format((float) $value, 2, '.', '');
            }

            $cents = is_string($value) ? Euro::toCents($value) : null;
        }

        if ($cents === null) {
            $errors[] = $hasCents
                ? "{$at}.price_cents: must be a whole number of cents."
                : "{$at}.price_eur: must be an amount with at most two decimals, e.g. \"8,50\".";

            return null;
        }

        if ($cents < 0 || $cents > MenuOption::MAX_PRICE_CENTS) {
            $errors[] = "{$at}: price out of bounds (0 to ".MenuOption::MAX_PRICE_CENTS.' cents).';

            return null;
        }

        if ($cents === 0) {
            $warnings[] = "{$at}: price is 0 (free dish); check that the source really says so.";
        } elseif ($cents > self::UNUSUAL_PRICE_CENTS) {
            $warnings[] = "{$at}: unusually high price ({$cents} cents); check cents versus euros.";
        }

        return $cents;
    }

    /**
     * @param  list<string>  $errors
     */
    private static function key(mixed $raw, string $at, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || preg_match('/^[a-z0-9][a-z0-9_-]{0,'.(self::MAX_KEY_LENGTH - 1).'}$/', $raw) !== 1) {
            $errors[] = "{$at}.key: lowercase letters, digits, - and _ only, max ".self::MAX_KEY_LENGTH.' characters (or omit it).';

            return null;
        }

        return $raw;
    }

    /**
     * @param  array<string, true>  $used
     */
    public static function uniqueKey(string $name, array $used): string
    {
        $base = Str::slug(Str::limit($name, 50, ''), '-');
        $base = $base === '' ? 'item' : $base;
        $key = $base;

        for ($n = 2; isset($used[$key]); $n++) {
            $key = $base.'-'.$n;
        }

        return $key;
    }
}
