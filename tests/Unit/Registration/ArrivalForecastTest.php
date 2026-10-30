<?php

use App\Modules\Registration\Enums\TimeWindow as W;
use App\Modules\Registration\Support\ArrivalForecast;
use App\Modules\Registration\ValueObjects\ArrivalPlan;
use Carbon\CarbonImmutable;

function fcDays(string ...$dates): array
{
    return array_map(fn (string $d) => CarbonImmutable::parse($d, 'UTC')->startOfDay(), $dates);
}

function fc(array $days, array $plans, string $now = '2026-01-01 00:00'): ArrivalForecast
{
    return ArrivalForecast::compute($days, $plans, CarbonImmutable::parse($now, 'UTC'));
}

it('builds four slots per day in order', function () {
    $f = fc(fcDays('2026-11-20', '2026-11-21'), []);

    expect($f->slots)->toHaveCount(8)
        ->and($f->slots[0]['window'])->toBe(W::Morning)
        ->and($f->slots[3]['window'])->toBe(W::Night)
        ->and($f->slots[4]['day'])->toBe('2026-11-21')
        ->and($f->slots[3]['endsAt']->toDateTimeString())->toBe('2026-11-21 00:00:00');
});

it('handles an empty event without registrations or days', function () {
    $f = fc([], []);

    expect($f->slots)->toBe([])->and($f->registered)->toBe(0)->and($f->peak)->toBeNull();
});

it('reports everyone as unknown when no one stated a plan', function () {
    $f = fc(fcDays('2026-11-20'), [new ArrivalPlan, new ArrivalPlan]);

    expect($f->registered)->toBe(2)->and($f->unknown)->toBe(2)->and($f->placed)->toBe(0)
        ->and($f->peak)->toBeNull()
        ->and(array_sum(array_column($f->slots, 'present')))->toBe(0);
});

it('counts arrivals minus earlier departures per slot', function () {
    $f = fc(fcDays('2026-11-20', '2026-11-21'), [
        new ArrivalPlan('2026-11-20', W::Evening, '2026-11-21', W::Afternoon),
        new ArrivalPlan('2026-11-20', W::Evening, '2026-11-21', W::Morning),
        new ArrivalPlan('2026-11-21', W::Morning, null, null),
    ]);

    // slot indexes: 20.: 0-3 (evening=2), 21.: 4-7 (morning=4, afternoon=5)
    expect($f->slots[2]['arrivals'])->toBe(2)
        ->and($f->slots[2]['present'])->toBe(2)
        ->and($f->slots[4]['present'])->toBe(3)  // third arrives, nobody left before
        ->and($f->slots[5]['present'])->toBe(2)  // one left in morning slot
        ->and($f->slots[6]['present'])->toBe(1)  // one left in afternoon slot
        ->and($f->peak)->toBe(['index' => 4, 'count' => 3]);
});

it('keeps a missing departure present until the end', function () {
    $f = fc(fcDays('2026-11-20', '2026-11-21'), [new ArrivalPlan('2026-11-20', W::Morning)]);

    expect($f->slots[7]['present'])->toBe(1);
});

it('counts arrive and leave in the same slot as present for that slot only', function () {
    $f = fc(fcDays('2026-11-20'), [new ArrivalPlan('2026-11-20', W::Afternoon, '2026-11-20', W::Afternoon)]);

    expect($f->slots[1]['present'])->toBe(1)->and($f->slots[2]['present'])->toBe(0);
});

it('ignores a departure before the arrival slot', function () {
    $f = fc(fcDays('2026-11-20'), [new ArrivalPlan('2026-11-20', W::Evening, '2026-11-20', W::Morning)]);

    expect($f->slots[3]['present'])->toBe(1);
});

it('treats days outside the event and departure-only plans as unknown', function () {
    $f = fc(fcDays('2026-11-20'), [
        new ArrivalPlan('2030-01-01', W::Evening),
        new ArrivalPlan(null, null, '2026-11-20', W::Evening),
    ]);

    expect($f->unknown)->toBe(2)->and($f->placed)->toBe(0);
});

it('supports a single-day event and an event over midnight (two days)', function () {
    $single = fc(fcDays('2026-11-20'), [new ArrivalPlan('2026-11-20', W::Night)]);
    $over = fc(fcDays('2026-11-20', '2026-11-21'), [new ArrivalPlan('2026-11-20', W::Night, '2026-11-21', W::Morning)]);

    expect($single->slots)->toHaveCount(4)->and($single->peak)->toBe(['index' => 3, 'count' => 1])
        ->and($over->slots[3]['present'])->toBe(1)
        ->and($over->slots[4]['present'])->toBe(1)
        ->and($over->slots[5]['present'])->toBe(0);
});

it('counts expected arrivals by now from slots that have started', function () {
    $plans = [
        new ArrivalPlan('2026-11-20', W::Afternoon),
        new ArrivalPlan('2026-11-20', W::Evening),
    ];

    expect(fc(fcDays('2026-11-20'), $plans, '2026-11-20 11:59')->expectedArrivedByNow)->toBe(0)
        ->and(fc(fcDays('2026-11-20'), $plans, '2026-11-20 12:00')->expectedArrivedByNow)->toBe(1)
        ->and(fc(fcDays('2026-11-20'), $plans, '2026-11-21 00:00')->expectedArrivedByNow)->toBe(2);
});
