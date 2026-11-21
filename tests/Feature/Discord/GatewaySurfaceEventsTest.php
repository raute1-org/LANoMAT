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

it('forwards the enriched body only for messages addressed to the assistant', function () {
    Event::fake([DiscordMessageCreated::class]);

    postEvent('message_create', [
        'channel_id' => 'c',
        'author_id' => '900',
        'message_id' => 'm2',
        'content' => '<@42> Installiere UT2004',
        'author' => ['id' => '900', 'username' => 'rene', 'global_name' => 'Rene', 'bot' => false],
        'channel' => ['id' => 'c', 'name' => 'hilfe', 'type' => 0],
        'guild' => ['id' => 'g', 'name' => 'Raute1'],
        'mentions' => [['id' => '42', 'username' => 'LANoMAT']],
        'isDM' => false,
    ])->assertNoContent();

    Event::assertDispatched(DiscordMessageCreated::class, fn (DiscordMessageCreated $event) => $event->messageId === 'm2'
        && $event->content === '<@42> Installiere UT2004'
        && $event->isDM === false
        && $event->mentions === [['id' => '42', 'username' => 'LANoMAT']]
        && $event->context['channel']['name'] === 'hilfe'
        && $event->context['guild']['name'] === 'Raute1');

    postEvent('message_create', ['channel_id' => 'c', 'author_id' => '900', 'message_id' => 'm3'])->assertNoContent();

    Event::assertDispatched(DiscordMessageCreated::class, fn (DiscordMessageCreated $event) => $event->messageId === 'm3'
        && $event->content === null
        && $event->context === []);
});
