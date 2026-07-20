<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces one account per provider per user', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '111']);

    expect(fn () => LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '222']))
        ->toThrow(QueryException::class);
});

it('enforces one user per (provider, provider_user_id)', function () {
    LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch, 'provider_user_id' => 'abc']);

    expect(fn () => LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch, 'provider_user_id' => 'abc']))
        ->toThrow(QueryException::class);
});
