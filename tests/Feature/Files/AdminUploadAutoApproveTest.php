<?php

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

const AA_SECRET = 'tus-hook-secret-0123456789-abcdefghijklmnop';

function aaResumable(): void
{
    config([
        'files.store' => 'host',
        'files.host.id' => 1,
        'files.host.url' => 'https://files.example.test',
        'files.host.secret' => 'abcdefghijklmnopqrstuvwxyzABCDEF_-0123456789',
        'files.tus.endpoint' => 'https://files.example.test/upload/',
        'files.tus.hook_secret' => AA_SECRET,
        'files.tus.data_dir' => '/data',
        'files.max_file_gb' => 100,
        'files.per_user_quota_mb' => 100 * 1024,
    ]);
    app(FileStores::class)->extend(new FakeFileStore('filehost'));
}

function aaHook(string $type, array $upload)
{
    return test()->postJson('/internal/files/tusd-hook', ['Type' => $type, 'Event' => ['Upload' => $upload]], ['X-Tusd-Hook-Secret' => AA_SECRET]);
}

/** Runs the resumable flow (ticket, pre-create, post-finish) as the given user. */
function aaFinish(User $user): SharedFile
{
    $event = Event::factory()->registration()->create();
    $name = 'game.zip';
    $size = 5 * 1024 * 1024 * 1024;

    $ticket = test()->actingAs($user)->postJson("/events/{$event->slug}/files/uploads", ['name' => $name, 'size' => $size])->assertOk()->json('ticket');
    $id = aaHook('pre-create', ['ID' => '', 'Size' => $size, 'SizeIsDeferred' => false, 'Offset' => 0, 'MetaData' => ['filename' => $name, 'filetype' => 'application/zip', 'ticket' => $ticket]])->json('ChangeFileInfo.ID');
    aaHook('post-finish', ['ID' => $id, 'Size' => $size, 'Offset' => $size, 'MetaData' => ['filename' => $name]])->assertOk();

    return SharedFile::query()->firstOrFail();
}

it('approves a resumable upload of an admin right away and records the admin as reviewer', function () {
    aaResumable();
    $admin = User::factory()->admin()->create();

    $file = aaFinish($admin);

    expect($file->visibility)->toBe(FileVisibility::Approved)
        ->and($file->reviewed_by)->toBe($admin->id)
        ->and($file->reviewed_at)->not->toBeNull();
});

it('keeps resumable uploads of participants, helpers and orga pending', function (string $state) {
    aaResumable();
    $user = $state === 'participant' ? User::factory()->create() : User::factory()->{$state}()->create();

    expect(aaFinish($user)->visibility)->toBe(FileVisibility::Pending);
})->with(['participant', 'helper', 'orga']);

it('approves a legacy form upload of an admin right away', function () {
    Storage::fake('local');
    $event = Event::factory()->registration()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post("/events/{$event->slug}/files", ['file' => UploadedFile::fake()->create('map.zip', 200, 'application/zip')])
        ->assertSessionDoesntHaveErrors();

    $file = SharedFile::query()->firstOrFail();
    expect($file->visibility)->toBe(FileVisibility::Approved)
        ->and($file->reviewed_by)->toBe($admin->id);
});

it('keeps a legacy form upload of a participant pending', function () {
    Storage::fake('local');
    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/files", ['file' => UploadedFile::fake()->create('map.zip', 200, 'application/zip')]);

    expect(SharedFile::query()->firstOrFail()->visibility)->toBe(FileVisibility::Pending);
});
