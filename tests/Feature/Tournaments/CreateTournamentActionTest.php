<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Tournaments\Actions\CreateTournament;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\TournamentSaved;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\CreateTournament as CreateTournamentPage;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function validTournamentData(array $overrides = []): array
{
    return array_merge([
        'name' => 'CS2 Wingman',
        'format' => TournamentFormat::SingleElimination->value,
        'team_size' => 2,
        'starts_at' => '2026-12-05 13:00:00',
    ], $overrides);
}

it('creates a draft tournament for an orga', function () {
    EventFacade::fake([TournamentSaved::class]);
    $event = Event::factory()->create();

    $tournament = app(CreateTournament::class)->handle(User::factory()->orga()->create(), $event, validTournamentData([
        'game_id' => Game::factory()->create()->id,
        'max_entries' => 16,
    ]));

    expect($tournament->status)->toBe(TournamentStatus::Draft)
        ->and($tournament->event_id)->toBe($event->id)
        ->and($tournament->max_entries)->toBe(16);
    EventFacade::assertDispatched(TournamentSaved::class);
});

it('rejects a participant', function () {
    app(CreateTournament::class)->handle(User::factory()->create(), Event::factory()->create(), validTournamentData());
})->throws(AuthorizationException::class);

it('rejects check-in closing before opening', function () {
    try {
        app(CreateTournament::class)->handle(User::factory()->orga()->create(), Event::factory()->create(), validTournamentData([
            'checkin_opens_at' => '2026-12-05 12:30:00',
            'checkin_closes_at' => '2026-12-05 12:00:00',
        ]));
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('checkin_closes_at');
    }
});

it('ignores a status or unknown field smuggled into the data', function () {
    $t = app(CreateTournament::class)->handle(User::factory()->orga()->create(), Event::factory()->create(), validTournamentData([
        'status' => 'live', 'winner_entry_id' => 5, 'event_id' => 999,
    ]));

    expect($t->status)->toBe(TournamentStatus::Draft)->and($t->winner_entry_id)->toBeNull();
});

it('rejects an unknown game id', function () {
    app(CreateTournament::class)->handle(User::factory()->orga()->create(), Event::factory()->create(), validTournamentData(['game_id' => 99999]));
})->throws(ValidationException::class);

it('creates through the Filament page using the action', function () {
    $event = Event::factory()->create();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateTournamentPage::class)
        ->fillForm([
            'event_id' => $event->id,
            'name' => 'Filament Cup',
            'format' => TournamentFormat::SingleElimination->value,
            'team_size' => 1,
            'starts_at' => '2026-12-05 13:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tournament::query()->where('name', 'Filament Cup')->firstOrFail()->status)->toBe(TournamentStatus::Draft);
});
