<?php

use App\Models\User;
use App\Modules\Gallery\Actions\ApprovePhoto;
use App\Modules\Gallery\Actions\DeletePhoto;
use App\Modules\Gallery\Actions\RejectPhoto;
use App\Modules\Gallery\Actions\ToggleHighlight;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Events\GalleryUpdated;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('lets a helper approve a pending photo and stamps the reviewer', function () {
    $helper = User::factory()->helper()->create();
    $photo = EventPhoto::factory()->create();

    app(ApprovePhoto::class)->handle($photo, $helper);

    expect($photo->fresh())
        ->visibility->toBe(PhotoVisibility::Approved)
        ->reviewed_by->toBe($helper->id);
});

it('forbids a plain participant from approving', function () {
    $photo = EventPhoto::factory()->create();

    app(ApprovePhoto::class)->handle($photo, User::factory()->create());
})->throws(AuthorizationException::class);

it('dispatches GalleryUpdated for the event when a photo is approved', function () {
    EventFacade::fake([GalleryUpdated::class]);
    $helper = User::factory()->helper()->create();
    $photo = EventPhoto::factory()->create();

    app(ApprovePhoto::class)->handle($photo, $helper);

    EventFacade::assertDispatched(GalleryUpdated::class, fn ($dispatched) => $dispatched->eventId === $photo->event_id);
});

it('lets a helper reject a pending photo and stamps the reviewer', function () {
    $helper = User::factory()->helper()->create();
    $photo = EventPhoto::factory()->create();

    app(RejectPhoto::class)->handle($photo, $helper);

    expect($photo->fresh())
        ->visibility->toBe(PhotoVisibility::Rejected)
        ->reviewed_by->toBe($helper->id);
});

it('forbids a plain participant from rejecting', function () {
    $photo = EventPhoto::factory()->create();

    app(RejectPhoto::class)->handle($photo, User::factory()->create());
})->throws(AuthorizationException::class);

it('deletes both files and the row', function () {
    Storage::fake('local');
    $photo = EventPhoto::factory()->create();
    Storage::disk('local')->put($photo->path, 'original');
    Storage::disk('local')->put($photo->thumb_path, 'thumb');

    app(DeletePhoto::class)->handle($photo);

    Storage::disk('local')->assertMissing($photo->path);
    Storage::disk('local')->assertMissing($photo->thumb_path);
    expect(EventPhoto::find($photo->id))->toBeNull();
});

it('lets an orga toggle the highlight flag and dispatches GalleryUpdated', function () {
    EventFacade::fake([GalleryUpdated::class]);
    $orga = User::factory()->orga()->create();
    $photo = EventPhoto::factory()->approved()->create(['is_highlight' => false]);

    app(ToggleHighlight::class)->handle($photo, $orga);

    expect($photo->fresh()->is_highlight)->toBeTrue();
    EventFacade::assertDispatched(GalleryUpdated::class, fn ($dispatched) => $dispatched->eventId === $photo->event_id);

    app(ToggleHighlight::class)->handle($photo->fresh(), $orga);

    expect($photo->fresh()->is_highlight)->toBeFalse();
});

it('forbids a plain participant from toggling the highlight flag', function () {
    $photo = EventPhoto::factory()->approved()->create();

    app(ToggleHighlight::class)->handle($photo, User::factory()->create());
})->throws(AuthorizationException::class);

it('broadcasts GalleryUpdated with an empty payload on the public event channel', function () {
    $event = new GalleryUpdated(42);

    expect($event->broadcastOn()->name)->toBe('event.42')
        ->and($event->broadcastAs())->toBe('gallery.updated')
        ->and($event->broadcastWith())->toBe([]);
});
