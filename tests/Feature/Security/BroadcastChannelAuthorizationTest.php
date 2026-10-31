<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    // The null test driver is already booted with the channel callbacks; swap
    // to the signing driver and register the real callbacks on it again.
    Broadcast::purge();
    require base_path('routes/channels.php');
});

function channelAuth(object $test, string $channel)
{
    return $test->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
}

it('lets a user join only their own private user channel', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($me);

    channelAuth($this, "private-App.Models.User.{$me->id}")->assertOk()->assertJsonStructure(['auth']);
    channelAuth($this, "private-App.Models.User.{$other->id}")->assertForbidden();
});

it('refuses channel authorization to guests', function () {
    $user = User::factory()->create();

    channelAuth($this, "private-App.Models.User.{$user->id}")->assertForbidden();
});

it('refuses private and presence channels that have no authorization callback', function (string $channel) {
    $this->actingAs(User::factory()->create());

    channelAuth($this, $channel)->assertForbidden();
})->with([
    'private-orga.events.1',
    'private-tournament.1',
    'presence-event.1',
    'presence-App.Models.User.1',
]);
