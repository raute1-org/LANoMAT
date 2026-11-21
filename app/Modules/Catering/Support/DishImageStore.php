<?php

declare(strict_types=1);

namespace App\Modules\Catering\Support;

use App\Support\Images\InvalidImageException;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Stores dish photos. Every upload is decoded and re-encoded as WebP (longest edge capped,
 * EXIF/GPS dropped with the re-encode) and filed under `catering/dishes/<sha256-16>.webp` on the
 * private `local` disk; the name is derived from the encoded bytes, so the same photo used by
 * several dishes (or rounds) is one file. Files are therefore shared and never deleted when a dish
 * drops its image. They are served by DishImageController, never linked directly.
 */
final class DishImageStore
{
    public const DISK = 'local';

    public const DIRECTORY = 'catering/dishes';

    public const MAX_EDGE = 800;

    public const QUALITY = 80;

    /** Decompression-bomb guard, checked on the header before the decoder allocates. */
    public const MAX_PIXELS = 40_000_000;

    public function __construct(private readonly ImageManager $manager) {}

    /**
     * Re-encodes the image at `$path` and returns its storage path.
     *
     * @throws InvalidImageException
     */
    public function store(string $path): string
    {
        $binary = $this->encode($path);
        $target = self::DIRECTORY.'/'.substr(hash('sha256', $binary), 0, 16).'.webp';

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($target)) {
            $disk->put($target, $binary);
        }

        return $target;
    }

    /**
     * Decodes and re-encodes without writing (dry runs use this to prove the file is usable).
     *
     * @throws InvalidImageException
     */
    public function encode(string $path): string
    {
        $header = @getimagesize($path);

        if ($header === false) {
            throw InvalidImageException::unreadable();
        }

        if ($header[0] * $header[1] > self::MAX_PIXELS) {
            throw InvalidImageException::tooManyPixels();
        }

        try {
            return (string) $this->manager->decodePath($path)
                ->orient()
                ->scaleDown(width: self::MAX_EDGE, height: self::MAX_EDGE)
                ->encodeUsingFormat(Format::WEBP, quality: self::QUALITY);
        } catch (Throwable) {
            throw InvalidImageException::unreadable();
        }
    }

    public static function exists(string $image): bool
    {
        return Storage::disk(self::DISK)->exists($image);
    }
}
