<?php

use App\Modules\Events\Actions\TransitionEventStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Facades\Event as EventFacade;

/** The only edges the design permits (linear forward path). */
function allowedEdges(): array
{
    return [
        [EventStatus::Draft, EventStatus::Announced],
        [EventStatus::Announced, EventStatus::Registration],
        [EventStatus::Registration, EventStatus::Live],
        [EventStatus::Live, EventStatus::Finished],
        [EventStatus::Finished, EventStatus::Archived],
    ];
}

/** Every (from, to) pair that is NOT an allowed edge and not a no-op. */
function forbiddenEdges(): array
{
    $allowed = array_map(fn ($e) => $e[0]->value.'->'.$e[1]->value, allowedEdges());
    $pairs = [];
    foreach (EventStatus::cases() as $from) {
        foreach (EventStatus::cases() as $to) {
            if ($from === $to) {
                continue;
            }
            if (in_array($from->value.'->'.$to->value, $allowed, true)) {
                continue;
            }
            $pairs[] = [$from, $to];
        }
    }

    return $pairs;
}

it('allows every forward edge and persists the new status', function (EventStatus $from, EventStatus $to) {
    EventFacade::fake([EventStatusChanged::class]);
    $event = Event::factory()->status($from)->create();

    $result = app(TransitionEventStatus::class)->handle($event, $to);

    expect($result->status)->toBe($to)
        ->and($event->fresh()->status)->toBe($to);

    EventFacade::assertDispatched(EventStatusChanged::class, fn ($e) => $e->from === $from && $e->to === $to);
})->with(allowedEdges());

it('rejects every non-allowed transition with a DomainException', function (EventStatus $from, EventStatus $to) {
    $event = Event::factory()->status($from)->create();

    expect(fn () => app(TransitionEventStatus::class)->handle($event, $to))
        ->toThrow(DomainException::class);

    expect($event->fresh()->status)->toBe($from);
})->with(forbiddenEdges());

it('rejects a transition to the same status', function () {
    $event = Event::factory()->live()->create();

    expect(fn () => app(TransitionEventStatus::class)->handle($event, EventStatus::Live))
        ->toThrow(DomainException::class);
});

it('exposes allowed transitions per status', function () {
    expect(EventStatus::Draft->allowedTransitions())->toBe([EventStatus::Announced])
        ->and(EventStatus::Archived->allowedTransitions())->toBe([])
        ->and(EventStatus::Registration->canTransitionTo(EventStatus::Live))->toBeTrue()
        ->and(EventStatus::Registration->canTransitionTo(EventStatus::Draft))->toBeFalse();
});
