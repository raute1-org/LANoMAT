<?php

use App\Modules\Discord\Events\DiscordGuildMemberJoined;
use App\Modules\Discord\Events\DiscordGuildMemberLeft;
use App\Modules\Discord\Events\DiscordMessageCreated;
use App\Modules\Discord\Events\DiscordMessageReactionChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['services.discord.gateway_bridge_secret' => 'test-secret']));

function postEvent(string $type, array $data): TestResponse
{
    return test()->postJson('/internal/discord/gateway', ['type' => $type, 'data' => $data], ['X-Gateway-Secret' => 'test-secret']);
}

it('dispatches typed events for surfaced gateway events', function () {
    Event::fake([
        DiscordGuildMemberJoined::class, DiscordGuildMemberLeft::class,
        DiscordMessageCreated::class, DiscordMessageReactionChanged::class,
    ]);

    postEvent('member_add', ['guild_id' => 'g', 'user_id' => '900'])->assertNoContent();
    postEvent('member_remove', ['guild_id' => 'g', 'user_id' => '900'])->assertNoContent();
    postEvent('message_create', ['channel_id' => 'c', 'author_id' => '900', 'message_id' => 'm'])->assertNoContent();
    postEvent('reaction', ['message_id' => 'm', 'channel_id' => 'c', 'user_id' => '900', 'emoji' => '✅', 'added' => true])->assertNoContent();

    Event::assertDispatched(DiscordGuildMemberJoined::class, fn ($e) => $e->discordUserId === '900');
    Event::assertDispatched(DiscordGuildMemberLeft::class, fn ($e) => $e->discordUserId === '900');
    Event::assertDispatched(DiscordMessageCreated::class, fn ($e) => $e->messageId === 'm');
    Event::assertDispatched(DiscordMessageReactionChanged::class, fn ($e) => $e->added === true && $e->emoji === '✅');
});
