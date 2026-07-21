<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists an event photo defaulting to pending, not-highlighted', function () {
    $photo = EventPhoto::factory()->create();

    expect($photo->fresh())
        ->visibility->toBe(PhotoVisibility::Pending)
        ->is_highlight->toBeFalse();
});

it('does not mass-assign privilege or state fields', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    $photo = new EventPhoto([
        'event_id' => $event->id,
        'uploaded_by' => $user->id,
        'caption' => 'hi',
        'visibility' => PhotoVisibility::Approved,
        'is_highlight' => true,
        'path' => 'hacked',
    ]);

    expect($photo->visibility)->toBeNull()
        ->and($photo->is_highlight)->toBeNull()
        ->and($photo->path)->toBeNull();
});
