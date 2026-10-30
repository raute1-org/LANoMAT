<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Support\EventDays;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Notifications\ScheduleItemStartingSoon;
use App\Support\DisplayTimezone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('keeps storage in UTC and displays in Europe/Berlin', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('lanomat.timezone'))->toBe('Europe/Berlin')
        ->and(DisplayTimezone::name())->toBe('Europe/Berlin');
});

it('offers Fr 20.11. as the first arrival day for an event starting 00:30 Berlin time', function () {
    // 2026-11-20 00:30 Berlin (CET, UTC+1) == 2026-11-19 23:30 UTC.
    $event = Event::factory()->registration()->create([
        'starts_at' => Carbon::parse('2026-11-19 23:30:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-11-22 11:00:00', 'UTC'),
    ]);

    $days = EventDays::options($event);

    expect($days[0])->toBe(['value' => '2026-11-20', 'label' => 'Fr, 20.11.'])
        ->and(EventDays::dates($event))->toBe(['2026-11-20', '2026-11-21', '2026-11-22']);
});

it('does not add an empty day when the event ends at local midnight', function () {
    // ends 2026-11-22 00:00 Berlin == 2026-11-21 23:00 UTC.
    $days = EventDays::between(
        Carbon::parse('2026-11-20 12:00:00', 'UTC'),
        Carbon::parse('2026-11-21 23:00:00', 'UTC'),
    );

    expect(array_map(fn ($d) => $d->toDateString(), $days))->toBe(['2026-11-20', '2026-11-21']);
});

it('handles the summer-time offset (CEST, UTC+2)', function () {
    // 2026-07-10 00:30 Berlin == 2026-07-09 22:30 UTC.
    $days = EventDays::between(Carbon::parse('2026-07-09 22:30:00', 'UTC'), null);

    expect($days[0]->toDateString())->toBe('2026-07-10');
});

it('configures Filament pickers and columns with the display timezone', function () {
    expect(FilamentTimezone::get())->toBe('Europe/Berlin');
});

it('shares the display timezone with every Inertia page', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/tournaments")
        ->assertInertia(fn ($page) => $page->where('displayTimezone', 'Europe/Berlin'));
});

it('renders schedule notification times in Berlin time, not UTC', function () {
    $item = ScheduleItem::factory()->create(['starts_at' => Carbon::parse('2026-11-19 23:30:00', 'UTC')]);

    $data = (new ScheduleItemStartingSoon($item))->toDatabase(User::factory()->create());

    expect($data['body'])->toContain('00:30');
});

it('makes a Filament date-time picker work in Berlin time', function () {
    expect(DateTimePicker::make('starts_at')->getTimezone())->toBe('Europe/Berlin');
});
