<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\Identity\Actions\LinkAccount;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Enums\OwnershipHintStatus;
use App\Modules\Identity\Support\GameOwnershipHint;
use App\Modules\Identity\Support\LinkedAccountData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is Unknown when the game has no provider mapping', function () {
    $game = Game::factory()->create(['provider' => null, 'provider_app_id' => null]);
    $user = User::factory()->create();

    expect(GameOwnershipHint::for($user, $game))->toBe(OwnershipHintStatus::Unknown);
});

it('is Unknown when the user has no linked account for the game provider', function () {
    fakeLinkedAccounts();
    $game = Game::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_app_id' => '730']);
    $user = User::factory()->create();

    expect(GameOwnershipHint::for($user, $game))->toBe(OwnershipHintStatus::Unknown);
});

it('is Owned when the connector confirms ownership', function () {
    fakeLinkedAccounts()->willReportOwnership(LinkedAccountProvider::Steam, owns: true);
    $game = Game::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_app_id' => '730']);
    $user = User::factory()->create();
    app(LinkAccount::class)->handle($user, LinkedAccountProvider::Steam, new LinkedAccountData(
        provider_user_id: 'steam-1',
        nickname: 'SteamNick',
    ));

    expect(GameOwnershipHint::for($user, $game))->toBe(OwnershipHintStatus::Owned);
});

it('is NotOwned when the connector confirms non-ownership', function () {
    fakeLinkedAccounts()->willReportOwnership(LinkedAccountProvider::Steam, owns: false);
    $game = Game::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_app_id' => '730']);
    $user = User::factory()->create();
    app(LinkAccount::class)->handle($user, LinkedAccountProvider::Steam, new LinkedAccountData(
        provider_user_id: 'steam-1',
        nickname: 'SteamNick',
    ));

    expect(GameOwnershipHint::for($user, $game))->toBe(OwnershipHintStatus::NotOwned);
});

it('is Unknown when the connector cannot answer (private profile / API failure)', function () {
    fakeLinkedAccounts()->willReportOwnershipUnknown(LinkedAccountProvider::Steam);
    $game = Game::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_app_id' => '730']);
    $user = User::factory()->create();
    app(LinkAccount::class)->handle($user, LinkedAccountProvider::Steam, new LinkedAccountData(
        provider_user_id: 'steam-1',
        nickname: 'SteamNick',
    ));

    expect(GameOwnershipHint::for($user, $game))->toBe(OwnershipHintStatus::Unknown);
});

it('is Unknown for a Twitch-mapped game when the connector cannot answer (mirrors the real TwitchConnector, which has no ownership concept)', function () {
    fakeLinkedAccounts()->willReportOwnershipUnknown(LinkedAccountProvider::Twitch);
    $game = Game::factory()->create(['provider' => LinkedAccountProvider::Twitch, 'provider_app_id' => 'some-channel']);
    $user = User::factory()->create();
    app(LinkAccount::class)->handle($user, LinkedAccountProvider::Twitch, new LinkedAccountData(
        provider_user_id: 'twitch-1',
        nickname: 'TwitchNick',
        access_token: 'tok',
    ));

    expect(GameOwnershipHint::for($user, $game))->toBe(OwnershipHintStatus::Unknown);
});
