<?php

use App\Modules\Discord\Models\DiscordVoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a voice state row', function () {
    $row = DiscordVoiceState::create([
        'discord_user_id' => '900',
        'channel_id' => 'chan-1',
        'channel_name' => 'Turnier 1',
        'user_id' => null,
    ]);

    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->exists())->toBeTrue()
        ->and($row->channel_name)->toBe('Turnier 1');
});

use App\Models\User;
use App\Modules\Discord\Events\DiscordVoicePresenceUpdated;
use App\Modules\Discord\Support\HandleVoiceState;
use App\Modules\Discord\Support\VoicePresenceProjection;
use Illuminate\Support\Facades\Event;

it('upserts on join and deletes on leave', function () {
    $handler = app(HandleVoiceState::class);

    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1']);
    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->value('channel_id'))->toBe('c1');

    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c2', 'channel_name' => 'Turnier 2']);
    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->value('channel_id'))->toBe('c2');

    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => null, 'channel_name' => null]);
    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->exists())->toBeFalse();
});

it('projects No-PII occupancy: mapped names only, unmapped counted but unnamed', function () {
    $mapped = User::factory()->create(['discord_id' => '900', 'name' => 'Alice']);
    $handler = app(HandleVoiceState::class);
    $handler->handle(['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1']);
    $handler->handle(['guild_id' => 'g', 'user_id' => '901', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1']); // unmapped

    $projection = VoicePresenceProjection::current();

    expect($projection)->toHaveCount(1)
        ->and($projection[0]['channel'])->toBe('Turnier 1')
        ->and($projection[0]['count'])->toBe(2)
        ->and($projection[0]['names'])->toBe(['Alice']);
});

it('broadcasts an empty voice-presence update from the ingress', function () {
    Event::fake([DiscordVoicePresenceUpdated::class]);
    config(['services.discord.gateway_bridge_secret' => 'test-secret']);

    $this->postJson('/internal/discord/gateway', [
        'type' => 'voice_state',
        'data' => ['guild_id' => 'g', 'user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1'],
    ], ['X-Gateway-Secret' => 'test-secret'])->assertNoContent();

    Event::assertDispatched(DiscordVoicePresenceUpdated::class);
    expect((new DiscordVoicePresenceUpdated)->broadcastWith())->toBe([]);
});
