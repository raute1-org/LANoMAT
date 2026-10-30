<?php

use App\Models\User;
use App\Modules\Events\Filament\Resources\Events\Pages\EditEvent;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Filament\RelationManagers\RegistrationsRelationManager;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists registrations on the event edit page for orga', function () {
    $event = Event::factory()->registration()->create();
    $participant = User::factory()->create(['name' => 'QR Tester']);
    EventRegistration::factory()->for($event)->for($participant)->create();

    $this->actingAs(User::factory()->orga()->create())
        ->get("/admin/events/{$event->getRouteKey()}/edit")
        ->assertOk()
        ->assertSee('QR Tester')
        ->assertSee('Bestätigt'); // german status label -> i18n gate
});

it('toggles paid_at via the row action without mass-assignment', function () {
    $event = Event::factory()->registration()->create();
    $registration = EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->orga()->create());

    expect($registration->paid_at)->toBeNull();

    Livewire::test(RegistrationsRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])->callTableAction('toggle_paid', $registration);

    expect($registration->fresh()->paid_at)->not->toBeNull();

    Livewire::test(RegistrationsRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])->callTableAction('toggle_paid', $registration);

    expect($registration->fresh()->paid_at)->toBeNull();
});

it('exports registrations as csv for orga', function () {
    $event = Event::factory()->registration()->create();
    $participant = User::factory()->create(['name' => 'CSV Tester']);
    EventRegistration::factory()->for($event)->for($participant)->create();

    $this->actingAs(User::factory()->orga()->create());

    $test = Livewire::test(RegistrationsRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])->callTableAction('export_csv');

    $test->assertFileDownloaded('registrations.csv');

    $content = base64_decode(data_get($test->effects, 'download.content'));

    expect($content)
        ->toContain('name,ticket_type,status,paid_at,checked_in_at')
        ->toContain('CSV Tester');
});

it('shows the ticket label, filters by status and names the participant in the cancel modal', function () {
    $event = Event::factory()->registration()->create();
    $active = EventRegistration::factory()->for($event)->for(User::factory()->create(['name' => 'Aktiv Anna']))->create(['ticket_type' => 'standard']);
    $cancelled = EventRegistration::factory()->for($event)->for(User::factory()->create(['name' => 'Weg Willi']))->create(['status' => 'cancelled']);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertSee('Standard')
        ->assertDontSee('>standard<', false)
        ->assertTableColumnExists('arrival_day')
        ->filterTable('status', 'cancelled')
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$active]);

    $component = Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->mountTableAction('cancel_registration', $active);

    expect((string) $component->instance()->getMountedAction()?->getModalHeading())
        ->toBe('Anmeldung von Aktiv Anna stornieren?');
});

it('shows a german empty state on the registrations manager', function () {
    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertSee('Noch keine Anmeldungen')
        ->assertDontSee('Keine event registrations');
});
