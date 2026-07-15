<?php

use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has german labels for each type', function () {
    expect(ScheduleItemType::Custom->label())->toBe('Programmpunkt')
        ->and(ScheduleItemType::Tournament->label())->toBe('Turnier')
        ->and(ScheduleItemType::Catering->label())->toBe('Essen')
        ->and(ScheduleItemType::Break->label())->toBe('Pause');
});

it('creates a schedule item via the factory with the type cast to the enum', function () {
    $item = ScheduleItem::factory()->create();

    expect($item->fresh()->type)->toBe(ScheduleItemType::Custom)
        ->and($item->starts_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($item->ref_type)->toBeNull()
        ->and($item->ref_id)->toBeNull();
});

it('casts tournament state via the named factory state', function () {
    $item = ScheduleItem::factory()->tournament()->create();

    expect($item->fresh()->type)->toBe(ScheduleItemType::Tournament);
});

it('casts catering state via the named factory state', function () {
    $item = ScheduleItem::factory()->catering()->create();

    expect($item->fresh()->type)->toBe(ScheduleItemType::Catering);
});
