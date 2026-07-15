<?php

use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Tournaments\Models\Tournament;

it('creates exactly one schedule item when a tournament is created', function () {
    $tournament = Tournament::factory()->create([
        'name' => 'Winter Cup',
        'starts_at' => '2026-08-01 10:00:00',
    ]);

    $items = ScheduleItem::query()
        ->where('ref_type', 'tournament')
        ->where('ref_id', $tournament->id)
        ->get();

    expect($items)->toHaveCount(1);

    $item = $items->first();

    expect($item->type)->toBe(ScheduleItemType::Tournament)
        ->and($item->event_id)->toBe($tournament->event_id)
        ->and($item->title)->toBe('Winter Cup')
        ->and($item->starts_at->equalTo($tournament->starts_at))->toBeTrue();
});

it('updates the same schedule item without creating a duplicate when the tournament changes', function () {
    $tournament = Tournament::factory()->create([
        'name' => 'Winter Cup',
        'starts_at' => '2026-08-01 10:00:00',
    ]);

    $tournament->update([
        'name' => 'Winter Cup 2',
        'starts_at' => '2026-08-02 12:00:00',
    ]);

    $items = ScheduleItem::query()
        ->where('ref_type', 'tournament')
        ->where('ref_id', $tournament->id)
        ->get();

    expect($items)->toHaveCount(1);

    $item = $items->first();

    expect($item->title)->toBe('Winter Cup 2')
        ->and($item->starts_at->equalTo($tournament->fresh()->starts_at))->toBeTrue();
});

it('does not create a duplicate schedule item when unrelated attributes change', function () {
    $tournament = Tournament::factory()->create();

    $tournament->update(['rules' => 'Best of 3, single elimination.']);

    expect(ScheduleItem::query()
        ->where('ref_type', 'tournament')
        ->where('ref_id', $tournament->id)
        ->count())->toBe(1);
});
