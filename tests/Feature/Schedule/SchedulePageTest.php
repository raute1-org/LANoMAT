<?php

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the public schedule page with german labels and the item list', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create([
        'title' => 'Opening Ceremony',
        'starts_at' => now()->addHour(),
    ]);

    $this->get("/events/{$event->slug}/schedule")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Schedule/Index')
            ->where('labels.title', 'Programm')
            ->has('items', 1)
            ->where('items.0.title', 'Opening Ceremony')
            ->has('items.0', fn (AssertableInertia $item) => $item
                ->hasAll(['id', 'type', 'typeLabel', 'title', 'description', 'startsAt', 'endsAt', 'location']))
        );
});

it('404s the schedule page for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/events/{$event->slug}/schedule")
        ->assertNotFound();
});

it('computes now as the item whose interval contains the current time', function () {
    $event = Event::factory()->live()->create();

    $past = ScheduleItem::factory()->for($event)->create([
        'title' => 'Past Item',
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHours(2),
    ]);

    $current = ScheduleItem::factory()->for($event)->create([
        'title' => 'Current Item',
        'starts_at' => now()->subMinutes(30),
        'ends_at' => now()->addMinutes(30),
    ]);

    $future = ScheduleItem::factory()->for($event)->create([
        'title' => 'Future Item',
        'starts_at' => now()->addHours(2),
    ]);

    $this->get("/events/{$event->slug}/schedule")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Schedule/Index')
            ->where('now.id', $current->id)
            ->where('now.title', 'Current Item')
            ->where('next.id', $future->id)
            ->where('next.title', 'Future Item')
        );

    expect($past)->not->toBeNull();
});

it('defaults an item without ends_at to a one hour window for the now computation', function () {
    $event = Event::factory()->live()->create();

    $item = ScheduleItem::factory()->for($event)->create([
        'title' => 'No End Time',
        'starts_at' => now()->subMinutes(30),
        'ends_at' => null,
    ]);

    $this->get("/events/{$event->slug}/schedule")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Schedule/Index')
            ->where('now.id', $item->id)
            ->where('next', null)
        );
});

it('surfaces the correct now/next pair via time travel', function () {
    $event = Event::factory()->live()->create();

    $morning = ScheduleItem::factory()->for($event)->create([
        'title' => 'Morning Session',
        'starts_at' => '2026-07-15 09:00:00',
        'ends_at' => '2026-07-15 10:00:00',
    ]);

    $afternoon = ScheduleItem::factory()->for($event)->create([
        'title' => 'Afternoon Session',
        'starts_at' => '2026-07-15 14:00:00',
        'ends_at' => '2026-07-15 15:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-15 09:30:00'));

    $this->get("/events/{$event->slug}/schedule")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Schedule/Index')
            ->where('now.id', $morning->id)
            ->where('next.id', $afternoon->id)
        );
});

it('resolves the german schedule page labels', function () {
    app()->setLocale('de');

    expect(__('schedule.page.title'))->toBe('Programm')
        ->and(__('schedule.page.now'))->toBe('Jetzt')
        ->and(__('schedule.page.next'))->toBe('Gleich')
        ->and(__('schedule.page.empty'))->toBe('Noch kein Programm');
});
