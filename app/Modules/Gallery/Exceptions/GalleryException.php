<?php

namespace App\Modules\Gallery\Exceptions;

use DomainException;

class GalleryException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function unreadable(): self
    {
        return new self('The uploaded file could not be decoded as an image.', 'gallery.errors.unreadable');
    }

    public static function tooLarge(): self
    {
        return new self('The upload exceeds the configured maximum size.', 'gallery.errors.too_large');
    }

    public static function invalidType(): self
    {
        return new self('The uploaded file has an unsupported MIME type.', 'gallery.errors.invalid_type');
    }
}
