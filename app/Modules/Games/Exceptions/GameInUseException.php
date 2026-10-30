<?php

namespace App\Modules\Games\Exceptions;

use DomainException;

class GameInUseException extends DomainException
{
    public readonly string $translationKey;

    public function __construct()
    {
        parent::__construct('The game is used by tournaments and cannot be deleted.');
        $this->translationKey = 'games.errors.in_use';
    }
}
