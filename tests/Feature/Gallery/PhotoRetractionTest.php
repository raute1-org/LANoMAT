<?php

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Actions\RetractPhoto;
use App\Modules\Gallery\Events\GalleryUpdated;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Support\GalleryQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * An approved photo on a finished (publicly visible, zip-able, recap-able)
 * event, with both of its files on the faked `local` disk.
 */
function retractionApprovedPhoto(?User $uploader = null): EventPhoto
{
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Finished]);
    $photo = EventPhoto::factory()->approved()->create([
        'event_id' => $event->id,
        'uploaded_by' => $uploader?->id ?? User::factory()->create()->id,
        'is_highlight' => true,
    ]);
    Storage::disk('local')->put($photo->path, 'FULL-BYTES');
    Storage::disk('local')->put($photo->thumb_path, 'THUMB-BYTES');

    return $photo;
}

it('lets the uploader retract their own approved photo over HTTP and removes the bytes', function () {
    $uploader = User::factory()->create();
    $photo = retractionApprovedPhoto($uploader);

    $this->actingAs($uploader)
        ->delete(route('gallery.destroy', ['eventPhoto' => $photo->id]))
        ->assertRedirect();

    expect(EventPhoto::query()->find($photo->id))->toBeNull();
    Storage::disk('local')->assertMissing($photo->path);
    Storage::disk('local')->assertMissing($photo->thumb_path);
});

it('forbids another participant from retracting a photo and keeps the bytes', function () {
    $photo = retractionApprovedPhoto();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->delete(route('gallery.destroy', ['eventPhoto' => $photo->id]))
        ->assertForbidden();

    expect(EventPhoto::query()->find($photo->id))->not->toBeNull();
    Storage::disk('local')->assertExists($photo->path);
});

it('lets an orga retract a photo uploaded by someone else', function () {
    $photo = retractionApprovedPhoto();
    $orga = User::factory()->orga()->create();

    $this->actingAs($orga)
        ->delete(route('gallery.destroy', ['eventPhoto' => $photo->id]))
        ->assertRedirect();

    expect(EventPhoto::query()->find($photo->id))->toBeNull();
    Storage::disk('local')->assertMissing($photo->path);
    Storage::disk('local')->assertMissing($photo->thumb_path);
});

it('rejects a plain participant at the action level and keeps the photo', function () {
    $photo = retractionApprovedPhoto();

    app(RetractPhoto::class)->handle($photo, User::factory()->create());
})->throws(AuthorizationException::class);

it('removes the photo from the participant gallery, the public photo route, the zip and the recap', function () {
    $photo = retractionApprovedPhoto();
    $event = $photo->event;
    $uploader = $photo->uploader;

    // Visible before the retraction.
    $this->get(route('gallery.photos.public.show', $photo))->assertOk();
    $this->actingAs($uploader)->get("/events/{$event->slug}/gallery/zip")->assertOk();
    $this->actingAs($uploader)->get("/events/{$event->slug}/recap")
        ->assertInertia(fn (AssertableInertia $p) => $p->has('recap.topPhotos', 1));

    app(RetractPhoto::class)->handle($photo, $uploader);

    expect(app(GalleryQuery::class)->approvedFor($event))->toBeEmpty();

    $this->get(route('gallery.photos.public.show', ['eventPhoto' => $photo->id]))->assertNotFound();
    $this->get(route('gallery.photos.public.thumb', ['eventPhoto' => $photo->id]))->assertNotFound();

    $this->actingAs($uploader)
        ->get("/events/{$event->slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p->has('photos', 0));

    // No approved photos left: the zip page answers with the friendly redirect, not a file.
    $this->actingAs($uploader)
        ->get("/events/{$event->slug}/gallery/zip")
        ->assertRedirect();

    $this->actingAs($uploader)
        ->get("/events/{$event->slug}/recap")
        ->assertInertia(fn (AssertableInertia $p) => $p->has('recap.topPhotos', 0));
});

it('treats a repeated retraction as a no-op over HTTP', function () {
    $uploader = User::factory()->create();
    $photo = retractionApprovedPhoto($uploader);
    $url = route('gallery.destroy', ['eventPhoto' => $photo->id]);

    $this->actingAs($uploader)->delete($url)->assertRedirect();
    $this->actingAs($uploader)->delete($url)->assertRedirect();

    expect(EventPhoto::query()->find($photo->id))->toBeNull();
});

it('treats a repeated retraction as a no-op at the action level', function () {
    $uploader = User::factory()->create();
    $photo = retractionApprovedPhoto($uploader);

    app(RetractPhoto::class)->handle($photo, $uploader);
    app(RetractPhoto::class)->handle($photo, $uploader);

    expect(EventPhoto::query()->find($photo->id))->toBeNull();
});

it('dispatches GalleryUpdated for the event so open gallery and beamer views refresh', function () {
    EventFacade::fake([GalleryUpdated::class]);
    $photo = retractionApprovedPhoto();

    app(RetractPhoto::class)->handle($photo, $photo->uploader);

    EventFacade::assertDispatched(GalleryUpdated::class, fn ($dispatched) => $dispatched->eventId === $photo->event_id);
});

it('flags the photo as retractable only for its uploader and orga in the page payload', function () {
    $photo = retractionApprovedPhoto();
    $uploader = $photo->uploader;
    $stranger = User::factory()->create();
    $orga = User::factory()->orga()->create();
    $slug = $photo->event->slug;

    $this->actingAs($uploader)->get("/events/{$slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('photos.0.retractable', true));

    $this->actingAs($stranger)->get("/events/{$slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('photos.0.retractable', false));

    $this->actingAs($orga)->get("/events/{$slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('photos.0.retractable', true));
});
