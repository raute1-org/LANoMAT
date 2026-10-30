<?php

namespace App\Support\Images;

use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Re-encodes an uploaded raster image so the stored bytes carry no metadata:
 * the header is read first and refused above `$maxPixels` (decompression-bomb
 * guard, before the decoder allocates the canvas), EXIF orientation is baked
 * into the pixels, the longest edge is capped, and the image is re-encoded
 * through GD, which drops every EXIF/XMP/IPTC segment (including GPS). JPEG
 * stays JPEG; everything else (PNG, GIF, WebP, BMP) becomes PNG so
 * transparency survives. SVG and other non-raster input is unreadable here.
 *
 * Same technique as the gallery's UploadPhoto (kept separate there because
 * it also builds a thumbnail and an always-JPEG original).
 */
class SanitizeImage
{
    public function __construct(private readonly ImageManager $manager) {}

    /**
     * @throws InvalidImageException
     */
    public function handle(string $path, int $maxEdge, int $maxPixels, int $quality = 85): SanitizedImage
    {
        $header = @getimagesize($path);

        if ($header === false) {
            throw InvalidImageException::unreadable();
        }

        if ($maxPixels < $header[0] * $header[1]) {
            throw InvalidImageException::tooManyPixels();
        }

        try {
            $image = $this->manager->decodePath($path)->orient()->scaleDown(width: $maxEdge, height: $maxEdge);

            $jpeg = $header[2] === IMAGETYPE_JPEG;
            $encoded = $jpeg
                ? $image->encodeUsingFormat(Format::JPEG, quality: $quality)
                : $image->encodeUsingFormat(Format::PNG);

            return new SanitizedImage(
                binary: (string) $encoded,
                extension: $jpeg ? 'jpg' : 'png',
                width: $image->size()->width(),
                height: $image->size()->height(),
            );
        } catch (Throwable) {
            throw InvalidImageException::unreadable();
        }
    }
}
