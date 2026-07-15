<?php

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Actions\DeleteScheduleItem;
use App\Modules\Schedule\Actions\UpsertScheduleItem;
use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;

it('creates a custom schedule item', function () {
    $event = Event::factory()->create();

    $item = app(UpsertScheduleItem::class)->handle($event, [
        'type' => ScheduleItemType::Custom->value,
        'title' => 'Opening Ceremony',
        'starts_at' => '2026-08-01 09:00:00',
    ]);

    expect($item->exists)->toBeTrue()
        ->and($item->event_id)->toBe($event->id)
        ->and($item->type)->toBe(ScheduleItemType::Custom)
        ->and($item->title)->toBe('Opening Ceremony');
});

it('updates an existing schedule item in place', function () {
    $event = Event::factory()->create();
    $item = ScheduleItem::factory()->for($event)->create(['title' => 'Old title']);

    $updated = app(UpsertScheduleItem::class)->handle($event, [
        'title' => 'New title',
        'starts_at' => '2026-08-01 11:00:00',
    ], $item);

    expect($updated->id)->toBe($item->id)
        ->and($updated->title)->toBe('New title')
        ->and(ScheduleItem::query()->count())->toBe(1);
});

it('deletes a schedule item', function () {
    $item = ScheduleItem::factory()->create();

    app(DeleteScheduleItem::class)->handle($item);

    expect(ScheduleItem::query()->count())->toBe(0);
});

it('never persists ref_type/ref_id for a custom item, even when passed as attributes', function () {
    $event = Event::factory()->create();

    $item = app(UpsertScheduleItem::class)->handle($event, [
        'ref_type' => 'tournament',
        'ref_id' => 999,
        'title' => 'Custom Item',
        'starts_at' => now(),
    ]);

    expect($item->ref_type)->toBeNull()
        ->and($item->ref_id)->toBeNull();

    $item->refresh();

    expect($item->ref_type)->toBeNull()
        ->and($item->ref_id)->toBeNull();
});
