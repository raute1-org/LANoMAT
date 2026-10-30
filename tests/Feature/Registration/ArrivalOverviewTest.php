<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\Filament\Pages\ArrivalOverview;
use App\Modules\Registration\Filament\Widgets\ArrivalsByDayChart;
use App\Modules\Registration\Filament\Widgets\ArrivalStatsWidget;
use App\Modules\Registration\Filament\Widgets\ExpectedPresentChart;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function overviewEvent(): Event
{
    return Event::factory()->registration()->create([
        'starts_at' => '2026-11-20 16:00:00', 'ends_at' => '2026-11-22 12:00:00', 'max_participants' => 10,
    ]);
}

it('is reachable for orga and not for participants or guests', function () {
    $event = overviewEvent();
    $url = "/admin/events/{$event->getRouteKey()}/arrival";

    $this->get($url)->assertRedirect();
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs(User::factory()->orga()->create())->get($url)->assertOk()->assertSee('Anreise-Übersicht');
});

it('lists registrations with plans, excludes cancelled, and filters', function () {
    $event = overviewEvent();
    $a = EventRegistration::factory()->for($event)->for(User::factory()->create(['name' => 'Anna Plan']))->create([
        'arrival_day' => '2026-11-21', 'arrival_window' => 'afternoon',
    ]);
    $b = EventRegistration::factory()->for($event)->for(User::factory()->create(['name' => 'Bernd Offen']))->create();
    $c = EventRegistration::factory()->cancelled()->for($event)->for(User::factory()->create(['name' => 'Carl Storno']))->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ArrivalOverview::class, ['record' => $event->getRouteKey()])
        ->assertCanSeeTableRecords([$a, $b])
        ->assertCanNotSeeTableRecords([$c])
        ->assertSee('Sa, 21.11., 12–18 Uhr')
        ->filterTable('arrival_day', '2026-11-21')
        ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->resetTableFilters()
        ->filterTable('without_arrival', true)
        ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
        ->resetTableFilters()
        ->sortTable('arrival_day')
        ->assertCanSeeTableRecords([$a, $b], inOrder: true);
});

it('renders the widgets from the forecast', function () {
    $event = overviewEvent();
    EventRegistration::factory()->for($event)->create(['arrival_day' => '2026-11-20', 'arrival_window' => 'evening', 'checked_in_at' => now()]);
    EventRegistration::factory()->for($event)->create(['arrival_day' => '2026-11-20', 'arrival_window' => 'evening', 'departure_day' => '2026-11-21', 'departure_window' => 'morning']);
    EventRegistration::factory()->for($event)->create();
    EventRegistration::factory()->cancelled()->for($event)->create(['arrival_day' => '2026-11-20', 'arrival_window' => 'evening']);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ArrivalStatsWidget::class, ['eventId' => $event->id])
        ->assertSee('Angemeldet')->assertSee('Freie Plätze')->assertSee('7');

    $component = Livewire::test(ArrivalsByDayChart::class, ['eventId' => $event->id]);
    $data = (fn () => $this->getData())->call($component->instance());
    expect(end($data['datasets'])['data'])->toBe([0, 0, 0, 1])
        ->and($data['labels'])->toBe(['Fr, 20.11.', 'Sa, 21.11.', 'So, 22.11.', 'Unbekannt'])
        ->and($data['datasets'][TimeWindow::Evening->order()]['data'][0])->toBe(2);

    Livewire::test(ExpectedPresentChart::class, ['eventId' => $event->id])
        ->assertSee('Spitze: 2 am Fr, 20.11. 18–22 Uhr')
        ->assertSee('1 Anmeldungen ohne Ankunft');
});

it('refuses widget data to non-orga', function () {
    $event = overviewEvent();
    $this->actingAs(User::factory()->create());

    Livewire::test(ArrivalStatsWidget::class, ['eventId' => $event->id])->assertForbidden();
});

it('sorts the table by day, then by window in enum order, unknown last', function () {
    $event = overviewEvent();
    $mk = fn (?string $day, ?string $window) => EventRegistration::factory()->for($event)->create([
        'arrival_day' => $day, 'arrival_window' => $window,
    ]);
    $none = $mk(null, null);
    $night = $mk('2026-11-20', 'night');
    $morning = $mk('2026-11-20', 'morning');
    $evening = $mk('2026-11-20', 'evening');
    $afternoon = $mk('2026-11-20', 'afternoon');
    $next = $mk('2026-11-21', 'morning');

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ArrivalOverview::class, ['record' => $event->getRouteKey()])
        ->sortTable('arrival_day')
        ->assertCanSeeTableRecords([$morning, $afternoon, $evening, $night, $next, $none], inOrder: true);
});
