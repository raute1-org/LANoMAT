<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Enums;

enum AssistantActionStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case Executed = 'executed';
    case Failed = 'failed';

    /** Only a proposed card can still be decided. */
    public function isOpen(): bool
    {
        return $this === self::Proposed;
    }
}
