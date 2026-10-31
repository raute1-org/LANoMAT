<?php

namespace App\Modules\Files\Exceptions;

use DomainException;

class FileException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    /** Stable machine code for JSON clients: the last segment of the translation key. */
    public function code(): string
    {
        return substr($this->translationKey, (int) strrpos($this->translationKey, '.') + 1);
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

    public static function tooLarge(): self
    {
        return new self('The file exceeds the maximum file size.', 'files.errors.too_large');
    }

    public static function blockedType(): self
    {
        return new self('This file type could be executed by a browser and is refused.', 'files.errors.blocked_type');
    }

    public static function uploadsUnavailable(): self
    {
        return new self('Resumable uploads are not enabled.', 'files.errors.uploads_unavailable');
    }

    public static function useResumableUpload(): self
    {
        return new self('Uploads go through the resumable upload path.', 'files.errors.use_resumable_upload');
    }
}
