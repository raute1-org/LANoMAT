<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Testing\FakeFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('stores the sha256 of the uploaded bytes', function () {
    $event = Event::factory()->registration()->create();
    $content = "MZ\x00binary-content-that-is-not-text";

    $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/files", [
        'file' => UploadedFile::fake()->createWithContent('setup.exe', $content),
    ])->assertSessionDoesntHaveErrors();

    $shared = SharedFile::query()->sole();

    expect($shared->sha256)->toBe(hash('sha256', $content))
        ->and($shared->size_bytes)->toBe(strlen($content));
});

it('keeps the upload when the bytes cannot be hashed, storing a null sha256', function () {
    // A store that consumes the temp file: by the time the hash would be read,
    // the bytes are gone — exactly the "hashing failed" case.
    $store = new class('local') extends FakeFileStore
    {
        public function put(string $localPath, string $path): void
        {
            parent::put($localPath, $path);
            unlink($localPath);
        }
    };

    app(FileStores::class)->extend($store);

    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/files", [
        'file' => UploadedFile::fake()->createWithContent('maps.zip', 'map-bytes'),
    ])->assertSessionDoesntHaveErrors();

    $shared = SharedFile::query()->sole();

    expect($shared->sha256)->toBeNull()
        ->and($shared->visibility)->toBe(FileVisibility::Pending)
        ->and($store->files)->toHaveKey($shared->path);
});

it('backfills a null sha256 from the stored bytes', function () {
    $content = 'already-stored-bytes';
    Storage::disk('local')->put('event-1/existing-mod.zip', $content);

    $file = SharedFile::factory()->create([
        'disk' => 'local',
        'path' => 'event-1/existing-mod.zip',
        'size_bytes' => strlen($content),
        'sha256' => null,
    ]);

    $this->artisan('lanomat:files:hash')->assertSuccessful();

    expect($file->refresh()->sha256)->toBe(hash('sha256', $content));
});

it('leaves the hash null when the stored file is gone', function () {
    $file = SharedFile::factory()->create([
        'disk' => 'local',
        'path' => 'event-1/missing.zip',
        'size_bytes' => 42,
        'sha256' => null,
    ]);

    $this->artisan('lanomat:files:hash')->assertFailed();

    expect($file->refresh()->sha256)->toBeNull();
});

it('does not let an unreadable file starve later files out of a limited run', function () {
    $missing = SharedFile::factory()->create([
        'disk' => 'local',
        'path' => 'event-1/gone.zip',
        'size_bytes' => 42,
        'sha256' => null,
    ]);

    Storage::disk('local')->put('event-1/good.zip', 'good-bytes');
    $good = SharedFile::factory()->create([
        'disk' => 'local',
        'path' => 'event-1/good.zip',
        'size_bytes' => strlen('good-bytes'),
        'sha256' => null,
    ]);

    // The limit caps hashes, not rows: the missing file does not consume it.
    $this->artisan('lanomat:files:hash', ['--limit' => 1])->assertFailed();

    expect($missing->refresh()->sha256)->toBeNull()
        ->and($good->refresh()->sha256)->toBe(hash('sha256', 'good-bytes'));
});

it('does not read or write anything in a dry run', function () {
    $content = 'dry-run-bytes';
    Storage::disk('local')->put('event-1/dry.zip', $content);

    $file = SharedFile::factory()->create([
        'disk' => 'local',
        'path' => 'event-1/dry.zip',
        'size_bytes' => strlen($content),
        'sha256' => null,
    ]);

    $this->artisan('lanomat:files:hash', ['--dry-run' => true])
        ->expectsOutputToContain('#'.$file->id)
        ->assertSuccessful();

    expect($file->refresh()->sha256)->toBeNull();
});

it('hashes only the given event and respects the limit', function () {
    $first = Event::factory()->registration()->create();
    $second = Event::factory()->registration()->create();

    foreach (['event-a.zip', 'event-b.zip'] as $name) {
        Storage::disk('local')->put('event-'.$first->id.'/'.$name, $name);
        SharedFile::factory()->for($first)->create([
            'disk' => 'local',
            'path' => 'event-'.$first->id.'/'.$name,
            'size_bytes' => strlen($name),
            'sha256' => null,
        ]);
    }

    Storage::disk('local')->put('event-'.$second->id.'/other.zip', 'other');
    $other = SharedFile::factory()->for($second)->create([
        'disk' => 'local',
        'path' => 'event-'.$second->id.'/other.zip',
        'size_bytes' => 5,
        'sha256' => null,
    ]);

    $this->artisan('lanomat:files:hash', ['--event' => $first->slug, '--limit' => 1])
        ->assertSuccessful();

    expect(SharedFile::query()->where('event_id', $first->id)->whereNotNull('sha256')->count())->toBe(1)
        ->and($other->refresh()->sha256)->toBeNull();
});
