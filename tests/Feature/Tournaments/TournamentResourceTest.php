<?php

use App\Models\User;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\ManageDisputes;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists tournaments for orga in the admin panel', function () {
    Tournament::factory()->create(['name' => 'Testlan Finals']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/tournaments')
        ->assertOk()
        ->assertSee('Testlan Finals')
        ->assertSee('Teamgröße'); // i18n gate: german column label
});

it('forbids participants from the tournaments resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/tournaments')
        ->assertForbidden();
});

it('starts a startable tournament via the header action', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
        ->callAction('start');

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Live);
});

it('shows a danger notification instead of a 500 when starting a non-startable double-elimination tournament', function () {
    // 5 entries is not among the supported double-elimination sizes
    // (2, 4, 6, 8, 16) -- StartTournament throws TournamentException here
    // even though the tournament's status is startable (CheckIn).
    $tournament = Tournament::factory()->checkIn()->doubleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(5)->create(['tournament_id' => $tournament->id]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
        ->callAction('start')
        ->assertNotified();

    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('overrides a disputed match result from the dispute queue', function () {
    $tournament = Tournament::factory()->live()->singleElim()->create();
    $entry1 = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id]);
    $entry2 = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id]);
    $match = GameMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Disputed,
    ]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ManageDisputes::class)
        ->callAction(TestAction::make('override')->table($match), data: [
            'score1' => 2,
            'score2' => 1,
        ]);

    $match->refresh();

    expect($match->status)->toBe(MatchStatus::Completed)
        ->and($match->score1)->toBe(2)
        ->and($match->score2)->toBe(1)
        ->and($match->winner_entry_id)->toBe($entry1->id);
});
