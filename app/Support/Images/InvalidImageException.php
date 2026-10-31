<?php

namespace App\Support\Images;

use DomainException;

class InvalidImageException extends DomainException
{
    public static function unreadable(): self
    {
        return new self('The file could not be decoded as an image.');
    }

    public static function tooManyPixels(): self
    {
        return new self('The image declares more pixels than the configured cap.');
    }
}
