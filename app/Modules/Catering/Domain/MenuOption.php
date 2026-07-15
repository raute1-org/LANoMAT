<?php

namespace App\Modules\Catering\Domain;

use InvalidArgumentException;

/**
 * A single selectable item on a FoodOrder's menu.
 *
 * This is the typed answer to the "typed jsonb through Filament's raw
 * KeyValue mangles types" footgun (see roadmap insight #9): a menu is never
 * stored/edited as a loose string=>string map. See MenuCast for the
 * DB jsonb <-> list<MenuOption> bridge.
 */
final readonly class MenuOption
{
    public function __construct(
        public string $key,
        public string $name,
        public int $priceCents,
    ) {}

    /**
     * @return array{key: string, name: string, price_cents: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'price_cents' => $this->priceCents,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('key', $data) || ! array_key_exists('name', $data) || ! array_key_exists('price_cents', $data)) {
            throw new InvalidArgumentException('MenuOption array must contain key, name and price_cents.');
        }

        if (! is_string($data['key']) || $data['key'] === '') {
            throw new InvalidArgumentException('MenuOption key must be a non-empty string.');
        }

        if (! is_string($data['name']) || $data['name'] === '') {
            throw new InvalidArgumentException('MenuOption name must be a non-empty string.');
        }

        if (! is_numeric($data['price_cents'])) {
            throw new InvalidArgumentException('MenuOption price_cents must be numeric.');
        }

        return new self(
            key: $data['key'],
            name: $data['name'],
            priceCents: (int) $data['price_cents'],
        );
    }
}
