<?php

namespace App\Modules\Catering\Domain;

/**
 * What an import (or its dry run) did or would do. Carries counts and the
 * resulting menu only; the audit log never receives dish texts.
 */
final readonly class MenuImportReport
{
    /**
     * @param  list<MenuOption>  $menu  the menu the round has (or would have) afterwards
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  array{added: int, kept: int, removed: int, changed: int}  $changes
     * @param  array{id: int|null, title: string, status: string}|null  $order
     */
    public function __construct(
        public string $mode,
        public bool $applied,
        public bool $created,
        public bool $unchanged,
        public array $errors,
        public array $warnings,
        public array $menu,
        public array $changes,
        public ?array $order,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'applied' => $this->applied,
            'mode' => $this->mode,
            'created' => $this->created,
            'unchanged' => $this->unchanged,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'changes' => $this->changes,
            'dish_count' => count($this->menu),
            'order' => $this->order,
            'menu' => array_map(static fn (MenuOption $option): array => $option->toArray(), $this->menu),
        ];
    }
}
