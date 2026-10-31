<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

final readonly class StepDescription
{
    /**
     * @param  list<PlannedAction>  $actions
     * @param  list<string>  $verifies  what `verify()` checks afterwards
     */
    public function __construct(
        public string $title,
        public array $actions,
        public array $verifies = [],
        public ?string $rollback = null,
    ) {}
}
