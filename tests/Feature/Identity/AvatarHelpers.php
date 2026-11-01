<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\CacheAvatar;
use App\Modules\Identity\Contracts\AvatarFetcher;
use App\Modules\Identity\Testing\FakeAvatarFetcher;
use Illuminate\Support\Facades\Storage;

if (! function_exists('avatarImage')) {
    /** A real raster image made with GD (no fixtures on disk). */
    function avatarImage(string $format = 'png', int $width = 300, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 200, 40, 60));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), (int) imagecolorallocate($image, 250, 250, 250));

        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 90),
            'webp' => imagewebp($image, null, 90),
            default => imagepng($image),
        };

        return (string) ob_get_clean();
    }

    /** A JPEG carrying an EXIF APP1 segment (camera + a GPS-looking marker) that must not survive. */
    function avatarJpegWithExif(): string
    {
        $jpeg = avatarImage('jpeg');
        $payload = "Exif\0\0".'MM'."\0*\0\0\0\x08".'PRIVATE-GPS-MARKER-48.1351N-11.5820E';
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /** Scripts the (test-wide) fake fetcher and returns it. */
    function fakeAvatars(): FakeAvatarFetcher
    {
        $fake = app(AvatarFetcher::class);
        assert($fake instanceof FakeAvatarFetcher);
        Storage::fake('local');

        return $fake;
    }

    /** A user whose avatar has been fetched into the local store. */
    function userWithAvatar(array $attributes = []): User
    {
        $fake = fakeAvatars()->willReturn(avatarImage());
        $user = User::factory()->create($attributes + ['avatar_url' => 'https://cdn.discordapp.com/avatars/1/a.png']);
        app(CacheAvatar::class)->handle($user);

        return $user->refresh();
    }
}
