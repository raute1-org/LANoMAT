<?php

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Actions\BuildEventPhotoZip;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('streams a zip of approved photos once the event is finished', function () {
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Finished]);
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($photo->path, 'JPEGBYTES');

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery/zip")
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
});

it('streams a zip of approved photos once the event is archived', function () {
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Archived]);
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($photo->path, 'JPEGBYTES');

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery/zip")
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
});

it('403s the zip while the event is still live', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery/zip")
        ->assertForbidden();
});

it('404s the zip for a draft event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Draft]);

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery/zip")
        ->assertNotFound();
});

it('redirects a guest to login instead of streaming the zip', function () {
    $event = Event::factory()->create(['status' => EventStatus::Finished]);

    $this->get("/events/{$event->slug}/gallery/zip")->assertRedirect();
});

it('only includes approved photos in the zip, not pending or rejected ones', function () {
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Finished]);

    $approved = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($approved->path, 'APPROVED-BYTES');

    $pending = EventPhoto::factory()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($pending->path, 'PENDING-BYTES');

    $rejected = EventPhoto::factory()->rejected()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($rejected->path, 'REJECTED-BYTES');

    $path = app(BuildEventPhotoZip::class)->handle($event);

    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->numFiles)->toBe(1);
    expect($zip->getFromName('photo-1.jpg'))->toBe('APPROVED-BYTES');

    $zip->close();
    unlink($path);
});
