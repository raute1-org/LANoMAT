<?php

namespace App\Support\Modules;

use RuntimeException;

final class InvalidModuleConfiguration extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("Invalid module configuration:\n - ".implode("\n - ", $problems));
    }
}
