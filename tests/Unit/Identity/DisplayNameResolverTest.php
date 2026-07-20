<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\DisplayNameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prefers the provider nickname in that provider\'s context', function () {
    $user = User::factory()->create(['name' => 'LanNick']);
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'nickname' => 'SteamNick']);
    $r = app(DisplayNameResolver::class);

    expect($r->resolve($user, LinkedAccountProvider::Steam))->toBe('SteamNick');
    expect($r->resolve($user, LinkedAccountProvider::Twitch))->toBe('LanNick'); // not linked → fallback
    expect($r->resolve($user, null))->toBe('LanNick');                          // no context → LANoMAT name
});

it('falls back to the LANoMAT name when the linked account has no nickname', function () {
    $user = User::factory()->create(['name' => 'LanNick']);
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'nickname' => null]);
    $r = app(DisplayNameResolver::class);

    expect($r->resolve($user, LinkedAccountProvider::Steam))->toBe('LanNick');
});

it('falls back to the LANoMAT name when the linked nickname is an empty string', function () {
    $user = User::factory()->create(['name' => 'LanNick']);
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'nickname' => '']);
    $r = app(DisplayNameResolver::class);

    expect($r->resolve($user, LinkedAccountProvider::Steam))->toBe('LanNick');
});

it('exposes the same resolution via User::displayNameFor()', function () {
    $user = User::factory()->create(['name' => 'LanNick']);
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'nickname' => 'SteamNick']);

    expect($user->displayNameFor(LinkedAccountProvider::Steam))->toBe('SteamNick');
    expect($user->displayNameFor(LinkedAccountProvider::Twitch))->toBe('LanNick');
    expect($user->displayNameFor())->toBe('LanNick');
});
