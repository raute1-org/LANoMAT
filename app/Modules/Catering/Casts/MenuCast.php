<?php

namespace App\Modules\Catering\Casts;

use App\Modules\Catering\Domain\MenuOption;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed cast for FoodOrder::$menu.
 *
 * DB jsonb `array<array{key,name,price_cents}>` <-> `list<MenuOption>`.
 *
 * This exists specifically so the menu is never edited as a raw
 * string=>string map (Filament's KeyValue field mangles types on such
 * jsonb columns, e.g. booleans becoming the truthy string 'false' — see
 * roadmap insight #9). Reads always decode into MenuOption value objects
 * with an explicit int price_cents; writes always go through
 * MenuOption::toArray() (or a plain array shaped the same way).
 *
 * @implements CastsAttributes<list<MenuOption>, mixed>
 */
class MenuCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<MenuOption>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Menu cast expects a JSON array for [{$key}].");
        }

        return array_values(array_map(
            static fn (mixed $option): MenuOption => $option instanceof MenuOption
                ? $option
                : MenuOption::fromArray($option),
            $decoded,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $options = $value ?? [];

        if (! is_iterable($options)) {
            throw new InvalidArgumentException("Menu cast expects an iterable of MenuOption for [{$key}].");
        }

        $normalized = [];

        foreach ($options as $option) {
            $normalized[] = $option instanceof MenuOption
                ? $option->toArray()
                : MenuOption::fromArray($option)->toArray();
        }

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }
}
