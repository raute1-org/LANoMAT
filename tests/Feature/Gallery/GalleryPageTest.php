<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('shows approved photos to everyone and the uploader their own pending one', function () {
    $event = Event::factory()->announced()->create();
    $approved = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    $uploader = User::factory()->create();
    $mine = EventPhoto::factory()->create(['event_id' => $event->id, 'uploaded_by' => $uploader->id]);
    EventPhoto::factory()->create(['event_id' => $event->id]); // someone else's pending → hidden

    $this->actingAs($uploader)
        ->get("/events/{$event->slug}/gallery")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Gallery/Index')
            ->has('photos', 2)
            ->where('canUpload', false));
});

it('hides another participant pending photo from a stranger and shows canUpload false', function () {
    $event = Event::factory()->announced()->create();
    EventPhoto::factory()->create(['event_id' => $event->id]); // pending → hidden

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get("/events/{$event->slug}/gallery")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Gallery/Index')
            ->has('photos', 0)
            ->where('canUpload', false));
});

it('reports canUpload true only for a registered participant', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canUpload', true));
});

it('reports canUpload false for a cancelled registration', function () {
    $event = Event::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->cancelled()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canUpload', false));
});

it('redirects a guest to login instead of showing the gallery', function () {
    // Correction to the brief: gallery.index sits behind the `auth`
    // middleware group (not public) because its photo/thumb serving routes
    // (Task 3's PhotoController) are auth-gated — a public page rendering
    // those <img> src values would show guests 401 images.
    $event = Event::factory()->announced()->create();

    $this->get("/events/{$event->slug}/gallery")->assertRedirect();
});

it('exposes the photo DTO shape with route-generated URLs', function () {
    $event = Event::factory()->announced()->create();
    $approved = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'caption' => 'Nice shot']);

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('photos.0.id', $approved->id)
            ->where('photos.0.thumbUrl', route('gallery.photos.thumb', $approved->id))
            ->where('photos.0.fullUrl', route('gallery.photos.show', $approved->id))
            ->where('photos.0.caption', 'Nice shot')
            ->where('photos.0.visibility', 'approved')
            ->has('photos.0.uploaderName')
            ->has('photos.0.mine')
            ->has('photos.0.isHighlight'));
});

it('returns 404 for a draft (not publicly visible) event', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery")
        ->assertNotFound();
});

it('defaults canDownloadZip to false', function () {
    $event = Event::factory()->announced()->create();

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canDownloadZip', false));
});
