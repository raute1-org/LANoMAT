<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('flags no verified Steam link when none exists', function () {
    $user = User::factory()->create(['steam_url' => 'https://steamcommunity.com/id/example']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Profile')
            ->where('profile.hasVerifiedSteamLink', false)
            ->where('profile.verifiedSteamNickname', null)
        );
});

it('flags a verified Steam link and exposes its nickname', function () {
    $user = User::factory()->create(['steam_url' => 'https://steamcommunity.com/id/example']);
    LinkedAccount::factory()->for($user)->create([
        'provider' => LinkedAccountProvider::Steam,
        'nickname' => 'ada_steam',
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Profile')
            ->where('profile.hasVerifiedSteamLink', true)
            ->where('profile.verifiedSteamNickname', 'ada_steam')
        );
});
