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

it('rejects a disallowed mime type with a validation error', function () {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();

    $file = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');

    $response = $this->actingAs($uploader)->post("/events/{$event->slug}/files", [
        'file' => $file,
    ]);

    $response->assertSessionHasErrors('file');
    expect(SharedFile::query()->count())->toBe(0);
});
