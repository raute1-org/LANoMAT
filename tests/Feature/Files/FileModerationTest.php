<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\ApproveSharedFile;
use App\Modules\Files\Actions\RejectSharedFile;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('lets a helper approve a pending file, stamping the reviewer, and it now appears for other participants', function () {
    $event = Event::factory()->registration()->create();
    $helper = User::factory()->helper()->create();
    $uploader = User::factory()->create();
    $otherParticipant = User::factory()->create();

    $file = SharedFile::factory()->for($event)->create([
        'user_id' => $uploader->id,
        'visibility' => FileVisibility::Pending,
    ]);

    $result = app(ApproveSharedFile::class)->handle($file, $helper);

    expect($result->visibility)->toBe(FileVisibility::Approved)
        ->and($result->reviewed_by)->toBe($helper->id)
        ->and($result->reviewed_at)->not->toBeNull();

    $this->actingAs($otherParticipant)
        ->get("/events/{$event->slug}/files")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('files', function ($files) use ($file) {
                return in_array($file->original_name, collect($files)->pluck('originalName')->all(), true);
            })
        );
});

it('forbids a participant from approving a file', function () {
    $event = Event::factory()->registration()->create();
    $participant = User::factory()->create();

    $file = SharedFile::factory()->for($event)->create([
        'visibility' => FileVisibility::Pending,
    ]);

    expect(fn () => app(ApproveSharedFile::class)->handle($file, $participant))
        ->toThrow(AuthorizationException::class);

    expect($file->refresh()->visibility)->toBe(FileVisibility::Pending);
});

it('forbids a participant from rejecting a file', function () {
    $event = Event::factory()->registration()->create();
    $participant = User::factory()->create();

    $file = SharedFile::factory()->for($event)->create([
        'visibility' => FileVisibility::Pending,
    ]);

    expect(fn () => app(RejectSharedFile::class)->handle($file, $participant))
        ->toThrow(AuthorizationException::class);

    expect($file->refresh()->visibility)->toBe(FileVisibility::Pending);
});

it('lets a helper reject a pending file, hiding it from other participants but keeping it visible to the uploader', function () {
    $event = Event::factory()->registration()->create();
    $helper = User::factory()->helper()->create();
    $uploader = User::factory()->create();
    $otherParticipant = User::factory()->create();

    $file = SharedFile::factory()->for($event)->create([
        'user_id' => $uploader->id,
        'original_name' => 'geheim.zip',
        'visibility' => FileVisibility::Pending,
    ]);
    Storage::disk('local')->put($file->path, 'file contents');

    $result = app(RejectSharedFile::class)->handle($file, $helper);

    expect($result->visibility)->toBe(FileVisibility::Rejected)
        ->and($result->reviewed_by)->toBe($helper->id)
        ->and($result->reviewed_at)->not->toBeNull();

    $this->actingAs($otherParticipant)
        ->get("/events/{$event->slug}/files")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('files', function ($files) use ($file) {
                return ! in_array($file->original_name, collect($files)->pluck('originalName')->all(), true);
            })
        );

    // Uploader still sees their own rejected file (SharedFilePolicy::view).
    $this->actingAs($uploader)
        ->get("/files/{$file->id}/download")
        ->assertOk();
});
