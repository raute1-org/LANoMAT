<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Enums;

/** What `apply()` did: changed something, or found the state already satisfied (and touched nothing). */
enum StepOutcome: string
{
    case Applied = 'applied';
    case AlreadySatisfied = 'already_satisfied';
}
