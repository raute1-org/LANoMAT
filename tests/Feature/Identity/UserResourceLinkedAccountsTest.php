<?php

declare(strict_types=1);

use App\Filament\RelationManagers\LinkedAccountsRelationManager;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists users for orga', function () {
    User::factory()->create(['name' => 'Ada Lovelace']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/users')
        ->assertOk()
        ->assertSee('Ada Lovelace');
});

it('forbids participants from the users resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/users')
        ->assertForbidden();
});

it('shows a linked account with provider label, nickname and linked-at on the view page', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    LinkedAccount::factory()->for($user)->create([
        'provider' => LinkedAccountProvider::Steam,
        'nickname' => 'ada_steam',
        'access_token' => 'super-secret-access-token',
        'refresh_token' => 'super-secret-refresh-token',
    ]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(LinkedAccountsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => ViewUser::class,
    ])
        ->assertSee('Steam')
        ->assertSee('ada_steam');
});

it('never renders linked account tokens in Filament', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create([
        'provider' => LinkedAccountProvider::Twitch,
        'access_token' => 'super-secret-access-token-xyz',
        'refresh_token' => 'super-secret-refresh-token-xyz',
    ]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(LinkedAccountsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => ViewUser::class,
    ])
        ->assertDontSee('super-secret-access-token-xyz')
        ->assertDontSee('super-secret-refresh-token-xyz');
});

it('shows no linked accounts as an empty relation table', function () {
    $user = User::factory()->create(['name' => 'No Links Person']);

    $response = $this->actingAs(User::factory()->orga()->create())
        ->get("/admin/users/{$user->id}");

    $response->assertOk()->assertSee('No Links Person');
});
