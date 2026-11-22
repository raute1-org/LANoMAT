<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\IssueUploadTicket;
use App\Modules\Files\Actions\UploadSharedFile;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Models\UploadReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Both upload paths must store the same name for the same browser file name:
 * a resumable (tus) upload through {@see IssueUploadTicket} and a multipart
 * upload through {@see UploadSharedFile}. The name later becomes a download
 * name, a recipe glob and a quoted shell literal, so it is cleaned once.
 */
beforeEach(function () {
    Storage::fake('local');

    config([
        'files.store' => 'host',
        'files.tus.endpoint' => 'https://files.example.test/upload/',
        'files.tus.hook_secret' => 'tus-hook-secret-0123456789-abcdefghijklmnop',
    ]);
});

it('stores the same cleaned name on the resumable and the form path', function (string $raw, string $expected) {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $reservation = app(IssueUploadTicket::class)->handle($event, $user, $raw, 1024)['reservation'];

    // The form path is the legacy post through the app, sent to the local store.
    config(['files.store' => 'local']);

    $form = UploadedFile::fake()->create($raw, 1, 'application/zip');
    $shared = app(UploadSharedFile::class)->handle($event, $user, $form);

    expect($reservation->original_name)->toBe($expected)
        ->and($shared->original_name)->toBe($expected);
})->with([
    'windows path' => ['C:\\Users\\mira\\lan\\Blitz Racer.zip', 'Blitz Racer.zip'],
    'posix path' => ['../../etc/passwd.zip', 'passwd.zip'],
    'control characters' => ["blitz\x00\x1F.zip", 'blitz.zip'],
    'line break' => ["blitz\nracer.zip", 'blitzracer.zip'],
    'leading dot' => ['.hidden-blitz.zip', '.hidden-blitz.zip'],
    '300 characters' => [str_repeat('a', 296).'.zip', str_repeat('a', 255)],
    'spaces and umlauts' => ['Blitz Racer – Ärger.zip', 'Blitz Racer – Ärger.zip'],
]);

it('refuses a name that cleans to nothing on both paths', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    expect(fn () => app(IssueUploadTicket::class)->handle($event, $user, "\x00\x1F", 1024))
        ->toThrow(FileException::class);

    config(['files.store' => 'local']);

    expect(fn () => app(UploadSharedFile::class)->handle($event, $user, UploadedFile::fake()->create("\x00\x1F", 1, 'application/zip')))
        ->toThrow(ValidationException::class);

    expect(UploadReservation::query()->count())->toBe(0)
        ->and(SharedFile::query()->count())->toBe(0);
});

it('applies the blocked-extension rule to the cleaned name on the form path', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    config(['files.store' => 'local']);

    // The trailing control character hides the .html suffix from a raw-name check.
    expect(fn () => app(UploadSharedFile::class)->handle($event, $user, UploadedFile::fake()->create("page.html\x01", 1, 'application/zip')))
        ->toThrow(ValidationException::class);

    expect(SharedFile::query()->count())->toBe(0);
});
