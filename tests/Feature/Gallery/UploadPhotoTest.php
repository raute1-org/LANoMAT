<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Actions\UploadPhoto;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Exceptions\GalleryException;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('stores a pending photo plus a thumbnail on the private disk and records dimensions', function () {
    $event = Event::factory()->create();
    $actor = User::factory()->create();
    $file = UploadedFile::fake()->image('shot.jpg', 3000, 2000);

    $photo = app(UploadPhoto::class)->handle($event, $actor, $file);

    expect($photo->visibility)->toBe(PhotoVisibility::Pending)
        ->and($photo->uploaded_by)->toBe($actor->id)
        ->and($photo->width)->toBeLessThanOrEqual(2560)
        ->and($photo->width)->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($photo->path);
    Storage::disk('local')->assertExists($photo->thumb_path);
});

it('sets the caption from the trusted argument, trimmed', function () {
    $event = Event::factory()->create();
    $actor = User::factory()->create();

    $photo = app(UploadPhoto::class)->handle(
        $event,
        $actor,
        UploadedFile::fake()->image('a.jpg', 800, 600),
        '  Nice shot  ',
    );

    expect($photo->caption)->toBe('Nice shot');
});

it('throws GalleryException when the upload cannot be decoded as an image', function () {
    $event = Event::factory()->create();
    $actor = User::factory()->create();
    $file = UploadedFile::fake()->create('not-an-image.jpg', 10, 'image/jpeg');

    expect(fn () => app(UploadPhoto::class)->handle($event, $actor, $file))
        ->toThrow(GalleryException::class);
});

it('throws GalleryException when the upload exceeds the configured size limit', function () {
    config(['gallery.max_upload_mb' => 1]);

    $event = Event::factory()->create();
    $actor = User::factory()->create();
    $file = UploadedFile::fake()->create('big.jpg', 2000, 'image/jpeg');

    expect(fn () => app(UploadPhoto::class)->handle($event, $actor, $file))
        ->toThrow(GalleryException::class);
});

it('rejects an image whose declared pixel count would exhaust memory before decoding it', function () {
    $event = Event::factory()->create();
    $actor = User::factory()->create();
    // A tiny PNG whose IHDR claims 30000x30000 px: valid header, no pixel data.
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 30000, 30000, 8, 2, 0, 0, 0).pack('N', 0).pack('N', 0).'IEND';
    $file = UploadedFile::fake()->createWithContent('bomb.png', $png);

    expect(fn () => app(UploadPhoto::class)->handle($event, $actor, $file))
        ->toThrow(GalleryException::class, GalleryException::tooLarge()->getMessage());
    expect(EventPhoto::count())->toBe(0);
});
