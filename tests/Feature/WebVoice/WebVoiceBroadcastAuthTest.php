<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\WebVoice\Events\ChannelsChanged;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

/*
| The websocket is the other door to the chat. phpunit.xml uses the null broadcaster, which never
| runs authorization callbacks, so this swaps in the signing driver exactly like
| tests/Feature/Security/BroadcastChannelAuthorizationTest.php does.
*/
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');

    $this->lobby = WebVoiceChannel::factory()->create();
    $this->teamA = WebVoiceChannel::factory()->match()->create();
    $this->teamB = WebVoiceChannel::factory()->match()->create();
    $this->archived = WebVoiceChannel::factory()->archived()->create();

    $this->ada = User::factory()->create();
    $this->admin = User::factory()->create(['role' => Role::Admin]);
    $this->teamA->members()->attach($this->ada);
});

function voiceChannelAuth(object $test, string $channel)
{
    return $test->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
}

it('authorizes the chat channel of a general channel for any logged-in user', function () {
    $this->actingAs($this->ada);

    voiceChannelAuth($this, "private-web-voice.channel.{$this->lobby->id}")->assertOk()->assertJsonStructure(['auth']);
});

it('authorizes a match channel for its roster and the admin, not for the opposing team', function () {
    voiceChannelAuth($this->actingAs($this->ada), "private-web-voice.channel.{$this->teamA->id}")->assertOk();
    voiceChannelAuth($this->actingAs($this->ada), "private-web-voice.channel.{$this->teamB->id}")->assertForbidden();
    voiceChannelAuth($this->actingAs($this->admin), "private-web-voice.channel.{$this->teamB->id}")->assertOk();
});

it('refuses an archived channel for members', function () {
    voiceChannelAuth($this->actingAs($this->ada), "private-web-voice.channel.{$this->archived->id}")->assertForbidden();
});

it('refuses an unknown channel and guests', function () {
    voiceChannelAuth($this->actingAs($this->ada), 'private-web-voice.channel.999999')->assertForbidden();

    auth()->guard('web')->logout();
    voiceChannelAuth($this, "private-web-voice.channel.{$this->lobby->id}")->assertForbidden();
});

it('applies the same rule to the presence spelling of the channel name', function () {
    // Laravel strips the private-/presence- prefix before matching, so the callback guards both.
    voiceChannelAuth($this->actingAs($this->ada), "presence-web-voice.channel.{$this->teamB->id}")->assertForbidden();
});

it('lets a logged-in user into the directory ping channel, which carries no payload', function () {
    $this->actingAs($this->ada);

    voiceChannelAuth($this, 'private-web-voice.directory')->assertOk();
    expect((new ChannelsChanged)->broadcastWith())->toBe([]);
});

it('authorizes orga for every live match room, helpers only for their own', function () {
    $orga = User::factory()->create(['role' => Role::Orga]);
    $helper = User::factory()->create(['role' => Role::Helper]);

    voiceChannelAuth($this->actingAs($orga), "private-web-voice.channel.{$this->teamB->id}")->assertOk();
    voiceChannelAuth($this->actingAs($helper), "private-web-voice.channel.{$this->teamB->id}")->assertForbidden();
});
