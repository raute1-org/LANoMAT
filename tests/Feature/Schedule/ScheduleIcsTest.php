<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the public schedule as an ICS calendar', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Opening Ceremony',
        'starts_at' => now()->addHour(),
    ]);

    $response = $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/schedule.ics")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/calendar');

    $body = $response->getContent();

    expect($body)->toStartWith('BEGIN:VCALENDAR');
    expect($body)->toContain('SUMMARY:Opening Ceremony');
});

it('404s the schedule ICS export for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/schedule.ics")
        ->assertNotFound();
});

it('exports items without an end with an estimated duration instead of a zero-length event', function () {
    $event = Event::factory()->live()->create();
    $start = now()->addDay()->startOfHour()->utc();
    ScheduleItem::factory()->for($event)->create([
        'type' => ScheduleItemType::Tournament,
        'title' => 'Finale',
        'starts_at' => $start,
        'ends_at' => null,
    ]);

    $body = $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/schedule.ics")->assertOk()->getContent();

    expect($body)->toContain('DTSTART:'.$start->format('Ymd\THis\Z'))
        ->and($body)->toContain('DTEND:'.$start->copy()->addHours(2)->format('Ymd\THis\Z'));
});
