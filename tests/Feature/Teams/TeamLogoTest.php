<?php

use App\Models\User;
use App\Modules\Teams\Actions\CreateTeam;
use App\Support\Images\InvalidImageException;
use App\Support\Images\SanitizeImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const GPS_MARKER = 'SECRET-GPS-48.1371N-11.5754E';

/** A real JPEG with an EXIF APP1 segment (camera + GPS text) injected after SOI. */
function jpegWithExif(int $width = 40, int $height = 30): string
{
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 30, 30));
    ob_start();
    imagejpeg($canvas, null, 90);
    $jpeg = (string) ob_get_clean();

    $exif = "Exif\0\0".'II*'."\0".GPS_MARKER.'-Make:EvilCam';
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

function pngWithAlpha(): string
{
    $canvas = imagecreatetruecolor(32, 32);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    ob_start();
    imagepng($canvas);

    return (string) ob_get_clean();
}

function uploadLogo($test, User $owner, $team, UploadedFile $file)
{
    return $test->actingAs($owner)->patch("/teams/{$team->id}", [
        'name' => $team->name,
        'tag' => $team->tag,
        'logo' => $file,
    ]);
}

beforeEach(function () {
    Storage::fake('public');
    $this->owner = User::factory()->create();
    $this->team = app(CreateTeam::class)->handle($this->owner, 'Alpha Squad', 'ALP');
});

it('strips EXIF and GPS bytes from an uploaded JPEG logo', function () {
    $bytes = jpegWithExif();
    expect(str_contains($bytes, GPS_MARKER))->toBeTrue(); // the fixture really carries it

    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('logo.jpg', $bytes))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $path = $this->team->refresh()->logo_path;
    $stored = Storage::disk('public')->get($path);

    expect($path)->toEndWith('.jpg')
        ->and(str_contains($stored, GPS_MARKER))->toBeFalse()
        ->and(str_contains($stored, 'EvilCam'))->toBeFalse()
        ->and(str_contains($stored, 'Exif'))->toBeFalse()
        ->and(getimagesizefromstring($stored)[2])->toBe(IMAGETYPE_JPEG);
});

it('preserves PNG transparency', function () {
    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('logo.png', pngWithAlpha()))
        ->assertSessionHasNoErrors();

    $path = $this->team->refresh()->logo_path;
    $image = imagecreatefromstring(Storage::disk('public')->get($path));

    expect($path)->toEndWith('.png')
        ->and((imagecolorat($image, 1, 1) >> 24) & 0x7F)->toBeGreaterThan(0);
});

it('downscales a large logo to the configured edge', function () {
    $canvas = imagecreatetruecolor(1200, 600);
    ob_start();
    imagepng($canvas);
    $png = (string) ob_get_clean();

    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('logo.png', $png))
        ->assertSessionHasNoErrors();

    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($this->team->refresh()->logo_path));

    expect($w)->toBe(512)->and($h)->toBe(256);
});

it('rejects an image above the pixel cap without storing it', function () {
    config(['teams.logo_max_pixels' => 100]);

    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('logo.png', pngWithAlpha()))
        ->assertSessionHasErrors(['logo' => trans('teams.errors.logo_unreadable')]);

    expect($this->team->refresh()->logo_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects a non-image upload', function () {
    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('logo.png', 'definitely not an image'))
        ->assertSessionHasErrors('logo');

    expect($this->team->refresh()->logo_path)->toBeNull();
});

it('rejects SVG logos', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';

    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('logo.svg', $svg))
        ->assertSessionHasErrors('logo');

    expect($this->team->refresh()->logo_path)->toBeNull();
});

it('refuses non-raster input at the service level', function () {
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"/>');

    try {
        expect(fn () => app(SanitizeImage::class)->handle($path, 512, 1_000_000))
            ->toThrow(InvalidImageException::class);
    } finally {
        unlink($path);
    }
});

it('replaces the previous logo file', function () {
    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('a.png', pngWithAlpha()));
    $first = $this->team->refresh()->logo_path;

    uploadLogo($this, $this->owner, $this->team, UploadedFile::fake()->createWithContent('b.jpg', jpegWithExif()));
    $second = $this->team->refresh()->logo_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});
