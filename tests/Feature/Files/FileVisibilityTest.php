<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('shows approved files to everyone and the viewers own pending file flagged, but not another participants pending file', function () {
    $event = Event::factory()->registration()->create();
    $viewer = User::factory()->create();
    $otherUploader = User::factory()->create();

    $approved = SharedFile::factory()->for($event)->approved()->create([
        'original_name' => 'freigegeben.zip',
    ]);
    $viewerPending = SharedFile::factory()->for($event)->create([
        'user_id' => $viewer->id,
        'original_name' => 'mein-upload.zip',
        'visibility' => FileVisibility::Pending,
    ]);
    $otherPending = SharedFile::factory()->for($event)->create([
        'user_id' => $otherUploader->id,
        'original_name' => 'fremder-upload.zip',
        'visibility' => FileVisibility::Pending,
    ]);

    $this->actingAs($viewer)
        ->get("/events/{$event->slug}/files")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Files/Index')
            ->where('labels.title', trans('files.page.title'))
            ->where('files', function ($files) use ($approved, $viewerPending, $otherPending) {
                $names = collect($files)->pluck('originalName')->all();

                return in_array($approved->original_name, $names, true)
                    && in_array($viewerPending->original_name, $names, true)
                    && ! in_array($otherPending->original_name, $names, true);
            })
        );
});

it('returns 404 for a draft events file page', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/events/{$event->slug}/files")->assertNotFound();
});

it('streams an approved files download with a 200', function () {
    $event = Event::factory()->registration()->create();
    $viewer = User::factory()->create();

    $file = SharedFile::factory()->for($event)->approved()->create();
    Storage::disk('local')->put($file->path, 'file contents');

    $this->actingAs($viewer)
        ->get("/files/{$file->id}/download")
        ->assertOk();
});

it('forbids downloading someone elses pending file', function () {
    $event = Event::factory()->registration()->create();
    $uploader = User::factory()->create();
    $otherParticipant = User::factory()->create();

    $file = SharedFile::factory()->for($event)->create([
        'user_id' => $uploader->id,
        'visibility' => FileVisibility::Pending,
    ]);
    Storage::disk('local')->put($file->path, 'file contents');

    $this->actingAs($otherParticipant)
        ->get("/files/{$file->id}/download")
        ->assertForbidden();
});
