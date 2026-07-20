<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Support\FriendSuggestions;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('suggests a Steam friend who also linked Steam, excluding non-candidates', function () {
    $me = User::factory()->create();
    LinkedAccount::factory()->for($me)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'ME']);

    $steamFriend = User::factory()->create();
    LinkedAccount::factory()->for($steamFriend)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'F1']);
    $alreadyFriend = User::factory()->create();
    LinkedAccount::factory()->for($alreadyFriend)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'F2']);
    Friendship::factory()->create(['requester_id' => $me->id, 'addressee_id' => $alreadyFriend->id, 'status' => FriendshipStatus::Accepted]);

    fakeLinkedAccounts()->willReportFriends(LinkedAccountProvider::Steam, ['F1', 'F2', 'UNKNOWN']);

    $ids = app(FriendSuggestions::class)->for($me)->pluck('user.id');
    expect($ids)->toContain($steamFriend->id)   // Steam friend + LANoMAT-linked → suggested
        ->not->toContain($alreadyFriend->id)    // already a friend → excluded
        ->not->toContain($me->id);
});

it('adds shared_steam_friend to the reasons of a suggested Steam friend', function () {
    $me = User::factory()->create();
    LinkedAccount::factory()->for($me)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'ME']);
    $f = User::factory()->create();
    LinkedAccount::factory()->for($f)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'F1']);
    fakeLinkedAccounts()->willReportFriends(LinkedAccountProvider::Steam, ['F1']);

    $row = app(FriendSuggestions::class)->for($me)->firstWhere('user.id', $f->id);
    expect($row['reasons'])->toContain('shared_steam_friend');
});

it('contributes nothing when the viewer has no linked Steam account', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    LinkedAccount::factory()->for($other)->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => 'F1']);

    $ids = app(FriendSuggestions::class)->for($me)->pluck('user.id');
    expect($ids)->not->toContain($other->id);
});
