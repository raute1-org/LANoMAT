<?php

declare(strict_types=1);

namespace App\Modules\Catering\Domain;

use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Support\GroupOrder;
use App\Support\SafeText;

/**
 * Pure rules of set menus ("Menü für 2 Personen"): parsing and validating the selection groups
 * of a menu, resolving which dishes a group offers, and checking a participant's selection.
 * No IO; every string that comes from outside is treated as untrusted text.
 */
final class MenuChoices
{
    private const KNOWN_FIELDS = ['label', 'count', 'allow_repeat', 'categories', 'options'];

    /**
     * Strict parse of the `choices` field of one imported dish.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return list<MenuChoice>
     */
    public static function parse(mixed $raw, string $at, array &$errors, array &$warnings): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors[] = "{$at}.choices: must be a list of selection groups.";

            return [];
        }

        if (count($raw) > MenuChoice::MAX_GROUPS) {
            $errors[] = "{$at}.choices: at most ".MenuChoice::MAX_GROUPS.' selection groups per dish ('.count($raw).' given).';

            return [];
        }

        $groups = [];
        $labels = [];

        foreach ($raw as $i => $item) {
            $group = self::parseGroup($item, "{$at}.choices[{$i}]", $errors, $warnings);

            if ($group === null) {
                continue;
            }

            $fingerprint = mb_strtolower($group->label);

            if (isset($labels[$fingerprint])) {
                $errors[] = "{$at}.choices[{$i}].label: duplicate group label \"{$group->label}\".";

                continue;
            }

            $labels[$fingerprint] = true;
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function parseGroup(mixed $item, string $at, array &$errors, array &$warnings): ?MenuChoice
    {
        if (! is_array($item)) {
            $errors[] = "{$at}: must be an object with label, count and categories or options.";

            return null;
        }

        $unknown = array_values(array_filter(
            array_map('strval', array_keys($item)),
            static fn (string $field): bool => ! in_array($field, self::KNOWN_FIELDS, true),
        ));

        if ($unknown !== []) {
            $shown = implode(', ', array_map(static fn (string $f): string => (string) SafeText::line($f, 30), array_slice($unknown, 0, 5)));
            $warnings[] = "{$at}: ignored fields ({$shown}); a group has label, count, allow_repeat and categories or options.";
        }

        $startErrors = count($errors);
        $label = null;

        if (is_string($item['label'] ?? null)) {
            $clean = SafeText::line($item['label'], MenuChoice::MAX_LABEL_LENGTH + 1);
            $label = $clean !== null && mb_strlen($clean) <= MenuChoice::MAX_LABEL_LENGTH ? $clean : null;
        }

        if ($label === null) {
            $errors[] = "{$at}.label: required text of at most ".MenuChoice::MAX_LABEL_LENGTH.' characters, e.g. "Hauptgericht".';
        }

        $rawCount = $item['count'] ?? null;
        $count = is_int($rawCount) ? $rawCount : (is_string($rawCount) && preg_match('/^\d{1,3}$/', $rawCount) === 1 ? (int) $rawCount : null);

        if ($count === null || $count < MenuChoice::MIN_COUNT || $count > MenuChoice::MAX_COUNT) {
            $errors[] = "{$at}.count: a whole number from ".MenuChoice::MIN_COUNT.' to '.MenuChoice::MAX_COUNT.'.';
        }

        $repeat = $item['allow_repeat'] ?? true;

        if (! is_bool($repeat)) {
            $errors[] = "{$at}.allow_repeat: must be true or false.";
        }

        $categories = self::entries($item['categories'] ?? null, "{$at}.categories", $errors);
        $options = self::entries($item['options'] ?? null, "{$at}.options", $errors);

        if ($categories !== null && $options !== null && ($categories === []) === ($options === [])) {
            $errors[] = $categories === []
                ? "{$at}: give either categories (category prefixes of the menu) or options (dish keys)."
                : "{$at}: give either categories or options, not both.";
        }

        if (count($errors) > $startErrors || $label === null || $count === null || ! is_bool($repeat) || $categories === null || $options === null) {
            return null;
        }

        return new MenuChoice($label, $count, $categories, $options, $repeat);
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>|null null when malformed (an error was added)
     */
    private static function entries(mixed $raw, string $at, array &$errors): ?array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > MenuChoice::MAX_SOURCE_ENTRIES) {
            $errors[] = "{$at}: must be a list of at most ".MenuChoice::MAX_SOURCE_ENTRIES.' texts.';

            return null;
        }

        $out = [];

        foreach ($raw as $entry) {
            $clean = is_string($entry) ? SafeText::line($entry, MenuImport::MAX_NAME_LENGTH) : null;

            if ($clean === null) {
                $errors[] = "{$at}: entries must be non-empty texts.";

                return null;
            }

            if (! in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * Maps fixed-list entries that are not a dish key but exactly one dish name to that key, so an
     * agent that only knows names (keys are generated on import) still gets a working set menu.
     *
     * @param  list<MenuOption>  $menu
     * @param  list<string>  $warnings
     * @return list<MenuOption>
     */
    public static function resolveReferences(array $menu, array &$warnings): array
    {
        $keys = [];
        $byName = [];

        foreach ($menu as $option) {
            $keys[$option->key] = true;
            $byName[mb_strtolower($option->name)][] = $option->key;
        }

        return array_map(static function (MenuOption $option) use ($keys, $byName, &$warnings): MenuOption {
            if ($option->choices === []) {
                return $option;
            }

            $groups = [];

            foreach ($option->choices as $group) {
                $mapped = [];

                foreach ($group->options as $entry) {
                    $named = $byName[mb_strtolower($entry)] ?? [];

                    if (! isset($keys[$entry]) && count($named) === 1) {
                        $warnings[] = "{$option->key}.choices \"{$group->label}\": \"".SafeText::line($entry, 80)."\" matched the dish name, stored as key \"{$named[0]}\".";
                        $entry = $named[0];
                    }

                    $mapped[] = $entry;
                }

                $groups[] = $group->options === [] ? $group : $group->withOptions($mapped);
            }

            return $option->withChoices($groups);
        }, $menu);
    }

    /**
     * Cross-checks the groups of every dish against the whole menu.
     *
     * @param  list<MenuOption>  $menu
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function validateMenu(array $menu, string $path = 'items'): array
    {
        $errors = [];
        $warnings = [];
        $byKey = [];

        foreach ($menu as $option) {
            $byKey[$option->key] = $option;
        }

        foreach ($menu as $index => $dish) {
            if ($dish->choices === []) {
                continue;
            }

            $at = "{$path}[{$index}]";

            if (count($dish->choices) > MenuChoice::MAX_GROUPS) {
                $errors[] = "{$at}.choices: at most ".MenuChoice::MAX_GROUPS.' selection groups per dish.';
            }

            $labels = [];

            foreach ($dish->choices as $g => $group) {
                $gAt = "{$at}.choices[{$g}]";
                $errorsBefore = count($errors);

                if ($group->count < MenuChoice::MIN_COUNT || $group->count > MenuChoice::MAX_COUNT) {
                    $errors[] = "{$gAt}.count: a whole number from ".MenuChoice::MIN_COUNT.' to '.MenuChoice::MAX_COUNT.'.';
                }

                $fingerprint = mb_strtolower($group->label);

                if (isset($labels[$fingerprint])) {
                    $errors[] = "{$gAt}.label: duplicate group label \"{$group->label}\".";
                }

                $labels[$fingerprint] = true;

                foreach ($group->options as $key) {
                    if ($key === $dish->key) {
                        $errors[] = "{$gAt}.options: \"{$key}\" refers to the set menu itself.";
                    } elseif (! isset($byKey[$key])) {
                        $errors[] = "{$gAt}.options: unknown dish key \"".SafeText::line($key, 64).'".';
                    } elseif ($byKey[$key]->choices !== []) {
                        $errors[] = "{$gAt}.options: \"{$key}\" is a set menu itself and cannot be picked inside another.";
                    }
                }

                $eligible = count(self::eligible($group, $menu, $dish->key));

                if ($eligible === 0 && count($errors) === $errorsBefore) {
                    $warnings[] = "{$gAt}: offers no dishes ({$group->label}); the menu cannot be ordered until a category or dish matches.";
                } elseif ($eligible > 0 && ! $group->allowRepeat && $eligible < $group->count) {
                    $warnings[] = "{$gAt}: needs {$group->count} different dishes but only {$eligible} are offered.";
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * The dishes a group offers: category members or the fixed list, never a set menu and never
     * the menu itself, in menu order.
     *
     * @param  list<MenuOption>  $menu
     * @return list<MenuOption>
     */
    public static function eligible(MenuChoice $group, array $menu, string $selfKey = ''): array
    {
        $categories = array_map(static fn (string $c): string => mb_strtolower(trim($c)), $group->categories);
        $fixed = array_flip($group->options);
        $out = [];

        foreach ($menu as $option) {
            if ($option->key === $selfKey || $option->choices !== []) {
                continue;
            }

            if ($group->usesCategories()) {
                if (in_array(mb_strtolower(trim(GroupOrder::split($option->name)[0])), $categories, true)) {
                    $out[] = $option;
                }
            } elseif (isset($fixed[$option->key])) {
                $out[] = $option;
            }
        }

        return $out;
    }

    /**
     * What a client needs to render the picker: per group the label, the required count and the
     * offered dishes (key, display label without category prefix, category).
     *
     * @param  list<MenuOption>  $menu
     * @return list<array{label: string, count: int, allowRepeat: bool, options: list<array{key: string, label: string, category: string}>}>
     */
    public static function describe(MenuOption $dish, array $menu): array
    {
        return array_map(static fn (MenuChoice $group): array => [
            'label' => $group->label,
            'count' => $group->count,
            'allowRepeat' => $group->allowRepeat,
            'options' => array_map(static function (MenuOption $o): array {
                [$category, $label] = GroupOrder::split($o->name);

                return ['key' => $o->key, 'label' => $label, 'category' => $category];
            }, self::eligible($group, $menu, $dish->key)),
        ], $dish->choices);
    }

    /**
     * Checks a participant's selection against the dish's groups and returns the stored shape
     * with name snapshots. Exactly `count` picks per group, every pick from the group's offer,
     * repeats only when allowed. Anything else is refused.
     *
     * @param  list<MenuOption>  $menu
     * @return list<array{group: string, option_key: string, name: string}>
     *
     * @throws CateringException
     */
    public static function resolveSelection(MenuOption $dish, array $menu, mixed $raw): array
    {
        $picks = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['group'] ?? null) || ! is_string($entry['option_key'] ?? null)) {
                throw CateringException::choicesInvalid($dish->name);
            }

            $picks[mb_strtolower(trim($entry['group']))][] = $entry['option_key'];
        }

        $known = [];

        foreach ($dish->choices as $group) {
            $known[mb_strtolower(trim($group->label))] = true;
        }

        if (array_diff_key($picks, $known) !== []) {
            throw CateringException::choicesInvalid($dish->name);
        }

        $result = [];

        foreach ($dish->choices as $group) {
            $offer = [];

            foreach (self::eligible($group, $menu, $dish->key) as $option) {
                $offer[$option->key] = $option;
            }

            if ($offer === []) {
                throw CateringException::choicesUnavailable($dish->name);
            }

            $given = $picks[mb_strtolower(trim($group->label))] ?? [];

            if (count($given) !== $group->count) {
                throw CateringException::choicesIncomplete($group->label, $group->count, count($given));
            }

            if (! $group->allowRepeat && count(array_unique($given)) !== count($given)) {
                throw CateringException::choicesInvalid($group->label);
            }

            foreach ($given as $key) {
                if (! isset($offer[$key])) {
                    throw CateringException::choicesInvalid($group->label);
                }

                $result[] = ['group' => $group->label, 'option_key' => $key, 'name' => $offer[$key]->name];
            }
        }

        return $result;
    }
}
