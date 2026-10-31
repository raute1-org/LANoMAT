<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\CreateTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\ListTournaments;
use App\Modules\Tournaments\Filament\Resources\Tournaments\RelationManagers\EntriesRelationManager;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

// F-R2A-8
it('rejects array-valued scores with a validation error instead of a 500', function () {
    $tournament = Tournament::factory()->for(Event::factory()->live()->create())->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(2)->create(['tournament_id' => $tournament->id]);
    app(StartTournament::class)->handle($tournament);
    $match = GameMatch::where('tournament_id', $tournament->id)->firstOrFail();
    $reporter = User::find(TournamentEntry::find($match->entry1_id)->user_id);

    $this->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => [1], 'score2' => 2])
        ->assertSessionHasErrors('score1');
    $this->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => 1, 'score2' => [2]])
        ->assertSessionHasErrors('score2');
    $this->actingAs($reporter)->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 2])
        ->assertSessionHasErrors(['score2' => __('tournaments.errors.tied_score')]);
    expect($match->fresh()->status->value)->toBe('ready');
});

// F-R2A-14
it('answers 404 for write routes on a draft the user cannot see', function () {
    $draft = Tournament::factory()->for(Event::factory()->live()->create())->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post("/tournaments/{$draft->id}/enroll")->assertNotFound();
    $this->actingAs($user)->post("/tournaments/{$draft->id}/checkin")->assertNotFound();
    $this->actingAs($user)->post("/tournaments/{$draft->id}/withdraw")->assertNotFound();
});

// F-R2A-11
it('does not allow deleting a live or finished tournament', function (TournamentStatus $status, bool $deletable) {
    $orga = User::factory()->orga()->create();
    $tournament = Tournament::factory()->create(['status' => $status]);

    expect($orga->can('delete', $tournament))->toBe($deletable);

    $this->actingAs($orga);
    $component = Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()]);
    $deletable
        ? $component->assertActionVisible('delete')
        : $component->assertActionHidden('delete');
})->with([
    [TournamentStatus::Draft, true],
    [TournamentStatus::Enrollment, true],
    [TournamentStatus::Live, false],
    [TournamentStatus::Finished, false],
]);

it('refuses a list row delete bulk action for live tournaments', function () {
    $this->actingAs(User::factory()->orga()->create());
    $live = Tournament::factory()->create(['status' => TournamentStatus::Live]);

    Livewire::test(ListTournaments::class)
        ->selectTableRecords([$live->id])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Tournament::find($live->id))->not->toBeNull();
});

it('hides the entry withdraw action outside enrollment and check-in', function (TournamentStatus $status, bool $visible) {
    $this->actingAs(User::factory()->orga()->create());
    $tournament = Tournament::factory()->create(['status' => $status]);
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'status' => EntryStatus::Registered]);

    $component = Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class]);
    $visible
        ? $component->assertTableActionVisible('withdraw', $entry)
        : $component->assertTableActionHidden('withdraw', $entry);
})->with([
    [TournamentStatus::Enrollment, true],
    [TournamentStatus::CheckIn, true],
    [TournamentStatus::Live, false],
    [TournamentStatus::Finished, false],
]);

// F-R2A-10
it('bounds max_entries and team_size in the admin form', function (array $data, array $errors) {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateTournament::class)
        ->fillForm(['name' => 'Cup', 'format' => 'single_elimination', 'starts_at' => now()->addDay(), 'team_size' => 1, ...$data])
        ->call('create')
        ->assertHasFormErrors($errors);
})->with([
    'negative max entries' => [['max_entries' => -5], ['max_entries']],
    'one entry' => [['max_entries' => 1], ['max_entries']],
    'huge max entries' => [['max_entries' => 3000000000], ['max_entries']],
    'team size zero' => [['team_size' => 0], ['team_size']],
    'team size huge' => [['team_size' => 999999], ['team_size']],
]);
