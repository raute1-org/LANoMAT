<?php

namespace App\Modules\Catering\Domain;

/**
 * Outcome of normalising an externally supplied menu: the typed options plus
 * every problem found in one pass, so the caller can fix the payload at once.
 */
final readonly class MenuImportResult
{
    /**
     * @param  list<MenuOption>  $menu
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $menu,
        public array $errors,
        public array $warnings,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
