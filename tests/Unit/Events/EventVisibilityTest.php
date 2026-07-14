<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;

it('is not publicly visible while draft', function () {
    expect(Event::factory()->draft()->create()->isPubliclyVisible())->toBeFalse();
});

it('is publicly visible in every non-draft status', function (EventStatus $status) {
    expect(Event::factory()->status($status)->create()->isPubliclyVisible())->toBeTrue();
})->with([
    EventStatus::Announced,
    EventStatus::Registration,
    EventStatus::Live,
    EventStatus::Finished,
    EventStatus::Archived,
]);

it('scopes queries to publicly visible events', function () {
    Event::factory()->draft()->create();
    Event::factory()->registration()->create();
    Event::factory()->archived()->create();

    expect(Event::query()->publiclyVisible()->count())->toBe(2);
});
