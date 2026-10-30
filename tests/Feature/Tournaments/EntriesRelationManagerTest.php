<?php

use App\Models\User;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\RelationManagers\EntriesRelationManager;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('offers the check-in row action only while check-in is open', function () {
    $this->actingAs(User::factory()->orga()->create());

    $closed = Tournament::factory()->create(['status' => TournamentStatus::Enrollment]);
    $closedEntry = TournamentEntry::factory()->create(['tournament_id' => $closed->id]);

    Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $closed, 'pageClass' => EditTournament::class])
        ->assertTableActionHidden('check_in', $closedEntry);

    $open = Tournament::factory()->create(['status' => TournamentStatus::CheckIn, 'checkin_opens_at' => now()->subHour(), 'checkin_closes_at' => now()->addHour()]);
    $openEntry = TournamentEntry::factory()->create(['tournament_id' => $open->id]);

    Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $open, 'pageClass' => EditTournament::class])
        ->assertTableActionVisible('check_in', $openEntry);
});

it('shows a german empty state on the entries manager', function () {
    $this->actingAs(User::factory()->orga()->create());
    $tournament = Tournament::factory()->create();

    Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class])
        ->assertSee('Noch keine Anmeldungen');
});
