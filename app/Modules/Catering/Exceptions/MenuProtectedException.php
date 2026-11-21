<?php

namespace App\Modules\Catering\Exceptions;

use DomainException;

/**
 * A restaurant menu change that would orphan ordered dishes: a dish that appears in an order
 * can be neither deleted nor have its key changed.
 */
class MenuProtectedException extends DomainException
{
    /**
     * @param  array<string, int>  $blocked  dish key => ordered positions
     */
    public function __construct(public readonly array $blocked)
    {
        parent::__construct('Dishes that were ordered cannot be removed or re-keyed: '.implode(', ', array_map(
            static fn (string $key, int $n): string => "{$key} ({$n})",
            array_keys($blocked),
            array_values($blocked),
        )).'.');
    }
}
