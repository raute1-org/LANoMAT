<?php

namespace App\Modules\Lfg\Exceptions;

use DomainException;

class LfgException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function eventNotVisible(): self
    {
        return new self('LFG posts can only be created for a publicly visible event.', 'lfg.errors.event_not_visible');
    }

    public static function invalidDuration(): self
    {
        return new self('duration_hours must be a positive number.', 'lfg.errors.invalid_duration');
    }

    public static function invalidTitle(): self
    {
        return new self('title must be a non-empty string of at most 120 characters.', 'lfg.errors.invalid_title');
    }
}
