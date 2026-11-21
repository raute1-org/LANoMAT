<?php

namespace App\Modules\Catering\Domain;

use App\Support\SafeText;
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
    /** Upper bound for one menu price: 1000,00 EUR (well inside the int4 column). */
    public const MAX_PRICE_CENTS = 100_000;

    /** Longest dish description, in characters. */
    public const MAX_DESCRIPTION_LENGTH = 600;

    /** The only shape an image path may have: content-addressed WebP under catering/dishes/. */
    public const IMAGE_PATH_PATTERN = '#^catering/dishes/[0-9a-f]{16}\.webp$#';

    /**
     * @param  string|null  $description  plain text, at most MAX_DESCRIPTION_LENGTH characters
     * @param  string|null  $image  relative storage path (never a URL), see DishImageStore
     * @param  list<MenuChoice>  $choices  selection groups of a set menu (empty for an ordinary dish)
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $priceCents,
        public ?string $description = null,
        public ?string $image = null,
        public array $choices = [],
    ) {}

    /**
     * The stored shape. Optional fields are omitted when empty, so menus written before
     * descriptions and images existed keep their exact JSON.
     *
     * @return array{key: string, name: string, price_cents: int, description?: string, image?: string, choices?: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $data = [
            'key' => $this->key,
            'name' => $this->name,
            'price_cents' => $this->priceCents,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->image !== null) {
            $data['image'] = $this->image;
        }

        if ($this->choices !== []) {
            $data['choices'] = array_map(static fn (MenuChoice $c): array => $c->toArray(), $this->choices);
        }

        return $data;
    }

    /**
     * The agent-facing shape (MCP get/preview): no storage path, only whether a photo exists.
     *
     * @return array{key: string, name: string, price_cents: int, description?: string, has_image?: true, choices?: list<array<string, mixed>>}
     */
    public function toImportArray(): array
    {
        $data = [
            'key' => $this->key,
            'name' => $this->name,
            'price_cents' => $this->priceCents,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->image !== null) {
            $data['has_image'] = true;
        }

        if ($this->choices !== []) {
            $data['choices'] = array_map(static fn (MenuChoice $c): array => $c->toArray(), $this->choices);
        }

        return $data;
    }

    public function with(?string $description, ?string $image): self
    {
        return new self($this->key, $this->name, $this->priceCents, $description, $image, $this->choices);
    }

    /** @param  list<MenuChoice>  $choices */
    public function withChoices(array $choices): self
    {
        return new self($this->key, $this->name, $this->priceCents, $this->description, $this->image, $choices);
    }

    public function isSetMenu(): bool
    {
        return $this->choices !== [];
    }

    /** One bounded plain-text line, or null when nothing is left. Markup is text, never interpreted. */
    public static function cleanDescription(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return SafeText::line($value, self::MAX_DESCRIPTION_LENGTH);
    }

    /** The path when it has the content-addressed shape, otherwise null (never an arbitrary path). */
    public static function cleanImage(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::IMAGE_PATH_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * @return list<MenuChoice>
     */
    public static function cleanChoices(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $groups = [];

        foreach ($value as $raw) {
            $group = MenuChoice::fromStored($raw);

            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return array_slice($groups, 0, MenuChoice::MAX_GROUPS);
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
            description: self::cleanDescription($data['description'] ?? null),
            image: self::cleanImage($data['image'] ?? null),
            choices: self::cleanChoices($data['choices'] ?? null),
        );
    }
}
