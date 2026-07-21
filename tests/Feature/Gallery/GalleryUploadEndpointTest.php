<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Events\GalleryUpdated;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('lets a registered participant upload a photo', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect(EventPhoto::query()->where('uploaded_by', $user->id)->count())->toBe(1);
});

it('403s a non-registered user on the upload endpoint', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
    ]);

    $response->assertForbidden();
    expect(EventPhoto::query()->count())->toBe(0);
});

it('403s a user whose registration was cancelled', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->cancelled()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
    ]);

    $response->assertForbidden();
    expect(EventPhoto::query()->count())->toBe(0);
});

it('rejects a non-image file with a validation error', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [UploadedFile::fake()->create('not-an-image.zip', 10, 'application/zip')],
    ]);

    $response->assertSessionHasErrors('photos.0');
    expect(EventPhoto::query()->count())->toBe(0);
});

it('uploads multiple photos in one request', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [
            UploadedFile::fake()->image('a.jpg', 800, 600),
            UploadedFile::fake()->image('b.jpg', 800, 600),
        ],
    ]);

    $response->assertSessionDoesntHaveErrors();
    expect(EventPhoto::query()->where('uploaded_by', $user->id)->count())->toBe(2);
});

it('returns 404 for an upload posted to a draft events gallery endpoint', function () {
    $event = Event::factory()->draft()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
    ]);

    $response->assertNotFound();
    expect(EventPhoto::query()->count())->toBe(0);
});

it('flashes a German error message and does not create a row when the action throws GalleryException', function () {
    config(['gallery.max_upload_mb' => 1]);

    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [UploadedFile::fake()->image('big.jpg', 5000, 5000)->size(2000)],
    ]);

    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $response->assertSessionHas('inertia.flash_data.toast.message', trans('gallery.errors.too_large'));
    expect(EventPhoto::query()->count())->toBe(0);
});

it('stores the valid files in a mixed batch and flashes a per-file summary', function () {
    config(['gallery.max_upload_mb' => 1]);

    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    // The bad file sits BETWEEN two valid ones: the old behaviour aborted the
    // batch on the first failure, silently dropping the trailing valid file.
    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [
            UploadedFile::fake()->image('good1.jpg', 800, 600),
            UploadedFile::fake()->image('big.jpg', 5000, 5000)->size(2000), // > 1 MB → skipped
            UploadedFile::fake()->image('good2.jpg', 800, 600),
        ],
    ]);

    // Both valid files persist — the trailing one is NOT abandoned.
    expect(EventPhoto::query()->where('uploaded_by', $user->id)->count())->toBe(2);
    $response->assertSessionHas('inertia.flash_data.toast.type', 'warning');
    $response->assertSessionHas('inertia.flash_data.toast.message', trans('gallery.upload.partial', [
        'uploaded' => 2,
        'total' => 3,
        'skipped' => 1,
    ]));
});

it('flashes a success summary when every file in a batch uploads', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->post("/events/{$event->slug}/gallery", [
        'photos' => [
            UploadedFile::fake()->image('a.jpg', 800, 600),
            UploadedFile::fake()->image('b.jpg', 800, 600),
        ],
    ]);

    expect(EventPhoto::query()->where('uploaded_by', $user->id)->count())->toBe(2);
    $response->assertSessionHas('inertia.flash_data.toast.type', 'success');
    $response->assertSessionHas('inertia.flash_data.toast.message', trans_choice('gallery.upload.uploaded', 2, ['count' => 2]));
});

it('lets the owner delete their own photo', function () {
    $owner = User::factory()->create();
    $photo = EventPhoto::factory()->create(['uploaded_by' => $owner->id]);
    Storage::disk('local')->put($photo->path, 'x');
    Storage::disk('local')->put($photo->thumb_path, 'y');

    $response = $this->actingAs($owner)->delete("/gallery/photos/{$photo->id}");

    $response->assertRedirect();
    expect(EventPhoto::find($photo->id))->toBeNull();
});

it('403s a stranger attempting to delete a photo', function () {
    $photo = EventPhoto::factory()->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->delete("/gallery/photos/{$photo->id}");

    $response->assertForbidden();
    expect(EventPhoto::find($photo->id))->not->toBeNull();
});

it('dispatches GalleryUpdated for the owning event when a photo is deleted', function () {
    EventFacade::fake([GalleryUpdated::class]);

    $owner = User::factory()->create();
    $photo = EventPhoto::factory()->approved()->create(['uploaded_by' => $owner->id]);
    Storage::disk('local')->put($photo->path, 'x');
    Storage::disk('local')->put($photo->thumb_path, 'y');
    $eventId = $photo->event_id;

    $this->actingAs($owner)->delete("/gallery/photos/{$photo->id}");

    EventFacade::assertDispatched(GalleryUpdated::class, fn ($dispatched) => $dispatched->eventId === $eventId);
});

it('lets an orga delete any photo', function () {
    $orga = User::factory()->orga()->create();
    $photo = EventPhoto::factory()->approved()->create();
    Storage::disk('local')->put($photo->path, 'x');
    Storage::disk('local')->put($photo->thumb_path, 'y');

    $response = $this->actingAs($orga)->delete("/gallery/photos/{$photo->id}");

    $response->assertRedirect();
    expect(EventPhoto::find($photo->id))->toBeNull();
});
