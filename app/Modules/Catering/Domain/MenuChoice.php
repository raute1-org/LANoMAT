<?php

declare(strict_types=1);

namespace App\Modules\Catering\Domain;

use App\Support\SafeText;

/**
 * One selection group of a set menu ("Menü für 2 Personen"): the participant picks exactly
 * `count` dishes for it. The pickable dishes come either from whole menu categories (the name
 * prefix before ": ") or from a fixed list of dish keys. Dishes that carry choices themselves
 * are never pickable. The menu keeps its own price; a pick has no surcharge.
 */
final readonly class MenuChoice
{
    public const MAX_GROUPS = 5;

    public const MIN_COUNT = 1;

    public const MAX_COUNT = 10;

    public const MAX_LABEL_LENGTH = 60;

    public const MAX_SOURCE_ENTRIES = 40;

    /**
     * @param  list<string>  $categories  category prefixes (exclusive with $options)
     * @param  list<string>  $options  dish keys of the restaurant (exclusive with $categories)
     */
    public function __construct(
        public string $label,
        public int $count,
        public array $categories = [],
        public array $options = [],
        public bool $allowRepeat = true,
    ) {}

    public function usesCategories(): bool
    {
        return $this->categories !== [];
    }

    /**
     * @return array{label: string, count: int, allow_repeat: bool, categories?: list<string>, options?: list<string>}
     */
    public function toArray(): array
    {
        $data = ['label' => $this->label, 'count' => $this->count, 'allow_repeat' => $this->allowRepeat];

        if ($this->categories !== []) {
            $data['categories'] = $this->categories;
        }

        if ($this->options !== []) {
            $data['options'] = $this->options;
        }

        return $data;
    }

    /** @param  list<string>  $options */
    public function withOptions(array $options): self
    {
        return new self($this->label, $this->count, $this->categories, $options, $this->allowRepeat);
    }

    /**
     * Lenient read of stored data: a malformed group is dropped instead of breaking every page
     * that shows the menu. Writers validate strictly (MenuChoices, MenuImport).
     */
    public static function fromStored(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['label'] ?? null) || ! is_numeric($data['count'] ?? null)) {
            return null;
        }

        $label = SafeText::line($data['label'], self::MAX_LABEL_LENGTH);
        $count = (int) $data['count'];

        if ($label === null || $count < self::MIN_COUNT || $count > self::MAX_COUNT) {
            return null;
        }

        return new self(
            $label,
            $count,
            self::strings($data['categories'] ?? null),
            self::strings($data['options'] ?? null),
            ($data['allow_repeat'] ?? true) !== false,
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }
}
