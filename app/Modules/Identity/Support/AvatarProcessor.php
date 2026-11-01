<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns downloaded bytes into the stored avatar: the header is read first (so a non-image or a
 * decompression bomb is refused before the decoder allocates a canvas), the image is decoded for
 * real, EXIF orientation is baked in, it is cropped to a square and re-encoded at 256x256 through
 * GD, which drops every metadata segment. WebP when GD can write it, JPEG otherwise.
 */
class AvatarProcessor
{
    public const SIZE = 256;

    private const MIN_EDGE = 16;

    private const MAX_EDGE = 8192;

    private const MAX_PIXELS = 25_000_000;

    public function __construct(private readonly ImageManager $manager) {}

    /**
     * @throws AvatarFetchFailed
     */
    public function handle(string $binary): ProcessedAvatar
    {
        $header = @getimagesizefromstring($binary);

        if ($header === false || ! in_array($header[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw AvatarFetchFailed::because('the response is not a supported image');
        }

        [$width, $height] = $header;

        if ($width < self::MIN_EDGE || $height < self::MIN_EDGE || $width > self::MAX_EDGE || $height > self::MAX_EDGE || $width * $height > self::MAX_PIXELS) {
            throw AvatarFetchFailed::because('the image dimensions are out of range');
        }

        $webp = function_exists('imagewebp');

        try {
            $image = $this->manager->decodeBinary($binary)->orient()->cover(self::SIZE, self::SIZE);
            $encoded = $webp
                ? $image->encodeUsingFormat(Format::WEBP, quality: 85)
                : $image->encodeUsingFormat(Format::JPEG, quality: 88);
        } catch (Throwable) {
            throw AvatarFetchFailed::because('the image cannot be decoded');
        }

        $bytes = (string) $encoded;

        return new ProcessedAvatar(
            binary: $bytes,
            extension: $webp ? 'webp' : 'jpg',
            hash: substr(hash('sha256', $bytes), 0, 16),
        );
    }
}
