<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Actions\FavoriteScheduleItem;
use App\Modules\Schedule\Actions\UpsertScheduleItem;
use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

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

it('exports a stable UID per item and a rising SEQUENCE after an edit', function () {
    $event = Event::factory()->live()->create();
    $item = ScheduleItem::factory()->for($event)->create(['title' => 'Opening']);
    $user = User::factory()->create();

    $first = $this->actingAs($user)->get("/events/{$event->slug}/schedule.ics")->getContent();
    $second = $this->actingAs($user)->get("/events/{$event->slug}/schedule.ics")->getContent();

    preg_match('/UID:(\S+)/', $first, $a);
    preg_match('/UID:(\S+)/', $second, $b);
    preg_match('/SEQUENCE:(\d+)/', $first, $seqA);

    expect($a[1])->toBe("schedule-item-{$item->id}@".parse_url((string) config('app.url'), PHP_URL_HOST))
        ->and($b[1])->toBe($a[1]);

    $this->travel(1)->minutes();
    $item->update(['title' => 'Opening v2']);

    $third = $this->actingAs($user)->get("/events/{$event->slug}/schedule.ics")->getContent();
    preg_match('/SEQUENCE:(\d+)/', $third, $seqC);

    expect((int) $seqC[1])->toBeGreaterThan((int) $seqA[1]);
});

it('exports only the favorites with ?favorites=1', function () {
    $event = Event::factory()->live()->create();
    $mine = ScheduleItem::factory()->for($event)->create(['title' => 'Mine']);
    ScheduleItem::factory()->for($event)->create(['title' => 'Other']);
    $user = User::factory()->create();
    app(FavoriteScheduleItem::class)->handle($mine, $user);

    $body = $this->actingAs($user)->get("/events/{$event->slug}/schedule.ics?favorites=1")->getContent();

    expect($body)->toContain('SUMMARY:Mine')->and($body)->not->toContain('SUMMARY:Other');
});

it('rejects an end before the start in the upsert action', function () {
    $event = Event::factory()->live()->create();

    expect(fn () => app(UpsertScheduleItem::class)->handle($event, [
        'type' => ScheduleItemType::Custom,
        'title' => 'Backwards',
        'starts_at' => now()->addHours(3),
        'ends_at' => now()->addHours(2),
    ]))->toThrow(ValidationException::class);

    expect(ScheduleItem::query()->count())->toBe(0);
});
