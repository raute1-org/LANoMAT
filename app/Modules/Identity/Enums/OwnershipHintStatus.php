<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

use App\Modules\Identity\Support\GameOwnershipHint;

/**
 * The result of {@see GameOwnershipHint::for()}
 * — ADVISORY only. Never gates anything: see the module docblock on
 * GameOwnershipHint for the binding "never blocks enrollment" rule.
 */
enum OwnershipHintStatus: string
{
    case Owned = 'owned';
    case NotOwned = 'not_owned';
    case Unknown = 'unknown';
}
