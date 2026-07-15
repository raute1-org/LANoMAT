<?php

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.discord.application_id' => 'app-123']);
});

it('lists the current event upcoming schedule items for /schedule', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Opening Ceremony',
        'starts_at' => now()->addHour(),
    ]);
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Closing Ceremony',
        'starts_at' => now()->subHour(),
    ]);

    $body = applicationCommand('schedule');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', fn ($content) => str_contains($content, 'Opening Ceremony'));
});

it('does not include past schedule items for /schedule', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Opening Ceremony',
        'starts_at' => now()->addHour(),
    ]);
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Closing Ceremony',
        'starts_at' => now()->subHour(),
    ]);

    $body = applicationCommand('schedule');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', fn ($content) => ! str_contains($content, 'Closing Ceremony'));
});

it('returns the German no-event fallback for /schedule with no publicly visible event', function () {
    Event::factory()->draft()->create();

    $body = applicationCommand('schedule');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.commands.schedule.no_current_event'));
});

it('returns the German no-items fallback for /schedule when the current event has no upcoming items', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Past Item',
        'starts_at' => now()->subHour(),
    ]);

    $body = applicationCommand('schedule');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.commands.schedule.none'));
});
