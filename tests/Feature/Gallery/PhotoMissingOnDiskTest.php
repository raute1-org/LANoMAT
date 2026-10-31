<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Actions\BuildEventPhotoZip;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('answers 404 instead of an error page when a photo or its thumbnail is gone from the disk', function () {
    Storage::fake('local');
    $photo = EventPhoto::factory()->approved()->create();
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get("/gallery/photos/{$photo->id}")->assertNotFound();
    $this->actingAs($viewer)->get("/gallery/photos/{$photo->id}/thumb")->assertNotFound();
});

it('answers 404 for a missing file on the public photo routes too', function () {
    Storage::fake('local');
    $event = Event::factory()->registration()->create();
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);

    $this->get("/gallery/public/photos/{$photo->id}")->assertNotFound();
    $this->get("/gallery/public/photos/{$photo->id}/thumb")->assertNotFound();
});

it('builds the zip from the photos that exist and skips one that is gone', function () {
    Storage::fake('local');
    $event = Event::factory()->create();
    $there = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    EventPhoto::factory()->approved()->create(['event_id' => $event->id]); // never written to disk
    Storage::disk('local')->put($there->path, 'JPEGBYTES');

    $path = app(BuildEventPhotoZip::class)->handle($event);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->numFiles)->toBe(1);
    $zip->close();
});
