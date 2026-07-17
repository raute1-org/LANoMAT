<?php

namespace App\Modules\Files\Exceptions;

use DomainException;

class FileException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function quotaExceeded(): self
    {
        return new self('The upload would exceed the per-user quota for this event.', 'files.errors.quota_exceeded');
    }

    public static function unreadable(): self
    {
        return new self('The uploaded file could not be read to determine its size.', 'files.errors.unreadable');
    }
}
