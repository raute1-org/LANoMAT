<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\GameRecipes\Filament\Resources\LauncherTerminals\Pages\ListLauncherTerminals;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->orga = User::factory()->orga()->create();
});

it('lists the launcher fleet for orga with german labels', function () {
    LauncherTerminal::factory()->playing('quake-live')->withInstalled(['quake-live', 'ut2004'])->withGamepad()->create([
        'name' => 'Kabinett 7',
        'version' => '1.4.2',
    ]);

    $this->actingAs($this->orga)
        ->get('/admin/launcher-terminals')
        ->assertOk()
        ->assertSee('Kabinett 7')
        ->assertSee('Spielt')
        ->assertSee('1.4.2')
        ->assertSee('Terminals'); // i18n gate
});

it('shows a stale terminal as offline instead of its last reported game state', function () {
    $stale = LauncherTerminal::factory()->playing('quake-live')->stale()->create(['name' => 'Altes Kabinett']);
    $fresh = LauncherTerminal::factory()->playing('quake-live')->create(['name' => 'Frisches Kabinett']);

    $this->actingAs($this->orga)
        ->get('/admin/launcher-terminals')
        ->assertOk()
        ->assertSee('Altes Kabinett');

    Livewire::test(ListLauncherTerminals::class)
        ->assertTableColumnStateSet('state', TerminalState::Offline, $stale)
        ->assertTableColumnStateSet('state', TerminalState::Playing, $fresh);
});

it('forbids participants and helpers from the fleet monitor', function (Role $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get('/admin/launcher-terminals')
        ->assertForbidden();
})->with([Role::Participant, Role::Helper]);

it('offers no hand-made terminal: create and edit routes do not exist', function () {
    $terminal = LauncherTerminal::factory()->create();

    $this->actingAs($this->orga)->get('/admin/launcher-terminals/create')->assertNotFound();
    $this->actingAs($this->orga)->get('/admin/launcher-terminals/'.$terminal->id.'/edit')->assertNotFound();
});
