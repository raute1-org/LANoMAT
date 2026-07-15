<?php

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the public schedule as an ICS calendar', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Opening Ceremony',
        'starts_at' => now()->addHour(),
    ]);

    $response = $this->get("/events/{$event->slug}/schedule.ics")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/calendar');

    $body = $response->getContent();

    expect($body)->toStartWith('BEGIN:VCALENDAR');
    expect($body)->toContain('SUMMARY:Opening Ceremony');
});

it('404s the schedule ICS export for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/events/{$event->slug}/schedule.ics")
        ->assertNotFound();
});
