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

    public static function storeUnavailable(): self
    {
        return new self('The file store is not reachable right now.', 'files.errors.store_unavailable');
    }

    public static function notApprovable(): self
    {
        return new self('A rejected file has been removed from storage and cannot be approved.', 'files.errors.not_approvable');
    }
}
