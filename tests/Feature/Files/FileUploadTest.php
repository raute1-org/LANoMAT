<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('stores an uploaded file on the local disk with pending visibility, ignoring a client-supplied user_id', function () {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();
    $attacker = User::factory()->create();

    $file = UploadedFile::fake()->create('mod-pack.zip', 500, 'application/zip');

    $response = $this->actingAs($uploader)->post("/events/{$event->slug}/files", [
        'file' => $file,
        'user_id' => $attacker->id,
    ]);

    $response->assertSessionDoesntHaveErrors();

    $sharedFile = SharedFile::query()->firstOrFail();

    expect($sharedFile->disk)->toBe('local')
        ->and($sharedFile->visibility)->toBe(FileVisibility::Pending)
        ->and($sharedFile->user_id)->toBe($uploader->id)
        ->and($sharedFile->user_id)->not->toBe($attacker->id)
        ->and($sharedFile->event_id)->toBe($event->id)
        ->and($sharedFile->original_name)->toBe('mod-pack.zip');

    Storage::disk('local')->assertExists($sharedFile->path);
});

it('rejects an upload once the per-user quota for the event is exceeded', function () {
    config(['files.per_user_quota_mb' => 1]);

    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    // Fill the quota with an existing 900 KB file.
    SharedFile::factory()->for($event)->create([
        'user_id' => $uploader->id,
        'size_bytes' => 900 * 1024,
    ]);

    $file = UploadedFile::fake()->create('too-big.zip', 200, 'application/zip');

    $response = $this->actingAs($uploader)->post("/events/{$event->slug}/files", [
        'file' => $file,
    ]);

    expect(trans('files.errors.quota_exceeded'))->not->toBe('files.errors.quota_exceeded');
    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $response->assertSessionHas('inertia.flash_data.toast.message', trans('files.errors.quota_exceeded'));

    expect(SharedFile::query()->count())->toBe(1);
});

it('rejects a first-ever upload that alone exceeds the per-user quota for the event', function () {
    // No prior SharedFile rows exist for this user/event, so there is
    // nothing for `lockForUpdate()` to lock — the advisory lock taken at
    // the top of the quota transaction is what closes this boundary.
    config(['files.per_user_quota_mb' => 1]);

    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    expect(SharedFile::query()->where('event_id', $event->id)->where('user_id', $uploader->id)->count())->toBe(0);

    $file = UploadedFile::fake()->create('too-big.zip', 2000, 'application/zip');

    $response = $this->actingAs($uploader)->post("/events/{$event->slug}/files", [
        'file' => $file,
    ]);

    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $response->assertSessionHas('inertia.flash_data.toast.message', trans('files.errors.quota_exceeded'));

    expect(SharedFile::query()->count())->toBe(0);
});

it('rejects an oversized upload with a validation error', function () {
    config(['files.max_upload_mb' => 1]);

    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->create('huge.zip', 2000, 'application/zip');

    $response = $this->actingAs($uploader)->post("/events/{$event->slug}/files", [
        'file' => $file,
    ]);

    $response->assertSessionHasErrors('file');
    expect(SharedFile::query()->count())->toBe(0);
});

it('returns 404 for an upload posted to a draft events files endpoint and creates no SharedFile row', function () {
    $event = Event::factory()->draft()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->create('mod-pack.zip', 500, 'application/zip');

    $response = $this->actingAs($uploader)->post("/events/{$event->slug}/files", [
        'file' => $file,
    ]);

    $response->assertNotFound();
    expect(SharedFile::query()->count())->toBe(0);
});

it('accepts executables and installers, keeping them pending until orga approves', function (string $name, string $mime) {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->create($name, 10, $mime);

    $this->actingAs($uploader)->post("/events/{$event->slug}/files", ['file' => $file])
        ->assertSessionDoesntHaveErrors();

    $shared = SharedFile::query()->firstOrFail();
    expect($shared->visibility)->toBe(FileVisibility::Pending)
        ->and($shared->original_name)->toBe($name);
    Storage::disk('local')->assertExists($shared->path);
})->with([
    'exe' => ['script.exe', 'application/x-msdownload'],
    'msi' => ['setup.msi', 'application/x-msi'],
    'iso' => ['game.iso', 'application/x-iso9660-image'],
    'zip' => ['maps.zip', 'application/zip'],
    'png' => ['screenshot.png', 'image/png'],
    'no extension' => ['README', 'application/octet-stream'],
]);

it('refuses types a browser could execute from our origin, whatever the client claims', function (string $name, string $mime) {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->create($name, 10, $mime);

    $response = $this->actingAs($uploader)->post("/events/{$event->slug}/files", ['file' => $file]);

    $response->assertSessionHasErrors('file');
    expect(SharedFile::query()->count())->toBe(0);
})->with([
    'html' => ['page.html', 'text/html'],
    'htm uppercase' => ['PAGE.HTM', 'application/octet-stream'],
    'xhtml' => ['page.xhtml', 'application/xhtml+xml'],
    'svg' => ['logo.svg', 'image/svg+xml'],
    'js' => ['app.js', 'text/javascript'],
    'mjs' => ['app.mjs', 'application/octet-stream'],
    'trailing dot' => ['evil.html.', 'application/octet-stream'],
    'trailing space' => ['evil.svg ', 'application/octet-stream'],
    'zip name but html mime' => ['harmless.zip', 'text/html'],
]);

it('refuses a file whose content is HTML even when it is named like an archive', function () {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->createWithContent('harmless.zip', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');

    $this->actingAs($uploader)->post("/events/{$event->slug}/files", ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(SharedFile::query()->count())->toBe(0);
});

it('refuses a file whose content is SVG even when it is named like a text file', function () {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->createWithContent('notes.txt', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->actingAs($uploader)->post("/events/{$event->slug}/files", ['file' => $file])
        ->assertSessionHasErrors('file');
});

it('stores the sniffed mime type, not the one the client sent', function () {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->createWithContent('thing.bin', "PK\x03\x04".str_repeat("\0", 64));

    $this->actingAs($uploader)->post("/events/{$event->slug}/files", ['file' => $file]);

    expect(SharedFile::query()->firstOrFail()->mime)->toBe('application/zip');
});

it('states in German which types are refused', function () {
    expect(trans('files.errors.blocked_type'))->toContain('HTML')->toContain('.exe');
});
