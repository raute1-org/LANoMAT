<?php

namespace App\Modules\Tournaments\Exceptions;

use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use DomainException;

/**
 * Thrown by {@see ConfirmMatchReport} when
 * the optimistic-lock `lock_version` supplied by the caller no longer matches
 * the persisted `GameMatch` row — someone else (a concurrent confirm or an
 * orga override) already changed the match in the meantime.
 */
class StaleMatchException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This match was already updated by someone else. Please reload and try again.');
    }
}
