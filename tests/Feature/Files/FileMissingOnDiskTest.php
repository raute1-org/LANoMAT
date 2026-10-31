<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('answers 404 instead of an error page when the file of an approved entry is gone from the disk', function () {
    Storage::fake('local');
    $event = Event::factory()->registration()->create();
    $file = SharedFile::factory()->for($event)->approved()->create();

    $this->actingAs(User::factory()->create())
        ->get("/files/{$file->id}/download")
        ->assertNotFound();
});

it('still streams the file when it is there', function () {
    Storage::fake('local');
    $event = Event::factory()->registration()->create();
    $file = SharedFile::factory()->for($event)->approved()->create();
    Storage::disk('local')->put($file->path, 'file contents');

    $this->actingAs(User::factory()->create())
        ->get("/files/{$file->id}/download")
        ->assertOk();
});
