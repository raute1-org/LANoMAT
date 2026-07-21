<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes event and uploader relations', function () {
    $event = Event::factory()->create();
    $uploader = User::factory()->create();
    $photo = EventPhoto::factory()->for($event)->create(['uploaded_by' => $uploader->id]);

    expect($photo->event)->toBeInstanceOf(Event::class)
        ->and($photo->event->id)->toBe($event->id)
        ->and($photo->uploader)->toBeInstanceOf(User::class)
        ->and($photo->uploader->id)->toBe($uploader->id);
});

it('casts visibility, is_highlight, width, height and reviewed_at', function () {
    $reviewer = User::factory()->create();
    $photo = EventPhoto::factory()->approved()->create();
    $photo->forceFill(['reviewed_by' => $reviewer->id])->save();

    $fresh = $photo->fresh();

    expect($fresh->visibility)->toBe(PhotoVisibility::Approved)
        ->and($fresh->is_highlight)->toBeBool()
        ->and($fresh->width)->toBeInt()
        ->and($fresh->height)->toBeInt()
        ->and($fresh->reviewed_at)->toBeInstanceOf(CarbonInterface::class);
});

it('defaults is_highlight to false', function () {
    $photo = EventPhoto::factory()->create();

    expect($photo->fresh()->is_highlight)->toBeFalse();
});
