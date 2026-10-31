<?php

use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Jobs\CleanupMatchChannelJob;
use App\Modules\Discord\Jobs\CreateMatchChannelJob;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config([
        'services.discord.guild_id' => 'guild-1',
        'services.discord.match_category_id' => 'category-1',
        'services.discord.application_id' => 'bot-1',
    ]);

    // MatchReady also triggers Task 21's voice-channel provisioning
    // listener; fake it too so these Discord-focused tests never hit a
    // real Mumble server.
    fakeMumble();
});

function readyMatch(): GameMatch
{
    $tournament = Tournament::factory()->create(['name' => 'Valorant Cup']);

    $user1 = User::factory()->create(['discord_id' => '111111']);
    $user2 = User::factory()->create(['discord_id' => '222222']);
    $noDiscordUser = User::factory()->create(['discord_id' => null]);

    $entry1 = TournamentEntry::factory()->team()->for($tournament)->create([
        'display_name' => 'Team Alpha',
        'roster_snapshot' => [
            ['user_id' => $user1->id, 'name' => 'Alpha One'],
            ['user_id' => $noDiscordUser->id, 'name' => 'Alpha Two (no discord)'],
        ],
    ]);

    $entry2 = TournamentEntry::factory()->solo()->for($tournament)->create([
        'display_name' => 'Solo Bravo',
        'user_id' => $user2->id,
        'roster_snapshot' => null,
    ]);

    return GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Ready,
    ]);
}

it('creates a match text channel with roster overwrites and a welcome embed on MatchReady', function () {
    $match = readyMatch();

    event(new MatchReady($match));

    $this->fake->assertChannelCreated('guild-1', "match-{$match->id}");

    $channel = collect($this->fake->channels)->firstWhere('name', "match-{$match->id}");
    expect($channel)->not->toBeNull();
    expect($channel['parentId'])->toBe('category-1');

    $overwriteEntry = collect($this->fake->overwrites)->firstWhere('channelId', $channel['id']);
    expect($overwriteEntry)->not->toBeNull();

    $overwrites = collect($overwriteEntry['overwrites']);
    $overwrittenUserIds = $overwrites->pluck('id')->all();
    expect($overwrittenUserIds)->toContain('111111', '222222', 'guild-1', 'bot-1');
    // the bot's own allow, the two roster-member allows, plus one @everyone deny
    expect($overwrittenUserIds)->toHaveCount(4); // the no-discord roster member is skipped

    $everyoneOverwrite = $overwrites->firstWhere('id', 'guild-1');
    expect($everyoneOverwrite['type'])->toBe(0); // role overwrite
    expect($everyoneOverwrite['allow'])->toBe('0');
    expect($everyoneOverwrite['deny'])->toBe('1024'); // VIEW_CHANNEL denied for @everyone

    $this->fake->assertMessageSent($channel['id']);

    $voice = collect($this->fake->channels)->firstWhere('name', "Match {$match->id}");
    expect($voice['voice'])->toBeTrue();
    expect($channel['voice'])->toBeFalse();
    expect($match->fresh()->discord_channels)->toBe(['text_channel_id' => $channel['id'], 'voice_channel_id' => $voice['id']]);

    // roster may connect and speak in the voice channel, @everyone may not even see it
    $voiceOverwrites = collect(collect($this->fake->overwrites)->firstWhere('channelId', $voice['id'])['overwrites']);
    expect($voiceOverwrites->firstWhere('id', '111111')['allow'])->toBe((string) (1024 | (1 << 20) | (1 << 21)));
    expect($voiceOverwrites->firstWhere('id', 'guild-1')['deny'])->toBe('1024');
});

it('is idempotent when MatchReady fires twice for the same match', function () {
    $match = readyMatch();

    event(new MatchReady($match));
    event(new MatchReady($match));

    expect(collect($this->fake->channels))->toHaveCount(2); // text + voice, once
});

it('resolves German copy for the welcome embed', function () {
    $match = readyMatch();

    event(new MatchReady($match));

    $channel = collect($this->fake->channels)->firstWhere('name', "match-{$match->id}");
    $message = collect($this->fake->messages)->firstWhere('channelId', $channel['id']);
    $embed = $message['embeds'][0];

    expect($embed['description'])->toContain('Team Alpha')
        ->and($embed['description'])->toContain('Solo Bravo')
        ->and($embed['description'])->toContain(route('tournaments.show', $match->tournament));
});

it('announces the result and dispatches a delayed cleanup job on MatchCompleted', function () {
    // Only fake CleanupMatchChannelJob (not a blanket Bus::fake()): the
    // listener itself is ShouldQueue, so a blanket fake would intercept the
    // queued listener dispatch too and its handle() would never run under
    // test.
    Bus::fake([CleanupMatchChannelJob::class]);

    $match = readyMatch();
    $match->update([
        'discord_channels' => ['text_channel_id' => 'fake-channel-existing'],
        'status' => MatchStatus::Completed,
        'winner_entry_id' => $match->entry1_id,
    ]);

    event(new MatchCompleted($match));

    $this->fake->assertMessageSent('fake-channel-existing', 'Team Alpha');

    Bus::assertDispatched(CleanupMatchChannelJob::class, function (CleanupMatchChannelJob $job) use ($match) {
        return $job->matchId === $match->id
            && $job->delay !== null
            && $job->delay->greaterThan(now());
    });
});

it('does not announce the result twice when MatchCompleted fires twice for the same match', function () {
    // The cleanup job dispatch itself is intentionally NOT deduplicated at
    // the listener level: CleanupMatchChannelJob::handle() is naturally
    // idempotent (a no-op once discord_channels is already cleared), so a
    // second dispatch on a re-fired MatchCompleted is harmless. Only the
    // outward-facing announcement message needs the outbox guard.
    Bus::fake([CleanupMatchChannelJob::class]);

    $match = readyMatch();
    $match->update([
        'discord_channels' => ['text_channel_id' => 'fake-channel-existing'],
        'status' => MatchStatus::Completed,
        'winner_entry_id' => $match->entry1_id,
    ]);

    event(new MatchCompleted($match));
    event(new MatchCompleted($match));

    expect(collect($this->fake->messages))->toHaveCount(1);
});

it('deletes the channel and clears discord_channels when the cleanup job runs', function () {
    $match = readyMatch();
    $match->update(['discord_channels' => ['text_channel_id' => 'fake-channel-to-delete']]);
    $this->fake->channels[] = [
        'guildId' => 'guild-1',
        'name' => "match-{$match->id}",
        'parentId' => 'category-1',
        'id' => 'fake-channel-to-delete',
    ];

    (new CleanupMatchChannelJob($match->id))->handle($this->fake);

    $this->fake->assertChannelDeleted('fake-channel-to-delete');
    expect($match->fresh()->discord_channels)->toBeNull();
});

it('does nothing when MatchReady fires for a match without both entries resolved', function () {
    $tournament = Tournament::factory()->create();
    $entry1 = TournamentEntry::factory()->solo()->for($tournament)->create();

    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => null,
        'status' => MatchStatus::Pending,
    ]);

    event(new MatchReady($match));

    expect(collect($this->fake->channels))->toBeEmpty();
    expect($match->fresh()->discord_channels)->toBeNull();
});

it('removes the channel again and keeps it private first when permissions fail', function () {
    $failing = new class extends FakeDiscordClient
    {
        /** @var array<int, string> */
        public array $order = [];

        public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
        {
            $this->order = array_column($overwrites, 'id');

            throw new RuntimeException('discord down');
        }
    };
    app()->instance(DiscordClient::class, $failing);

    $match = readyMatch();

    expect(fn () => dispatch_sync(new CreateMatchChannelJob($match->id)))->toThrow(RuntimeException::class);

    expect($failing->order)->toBe(['bot-1', 'guild-1', '111111', '222222']);
    expect($failing->channels)->toBe([]);
    expect($failing->deletedChannelIds)->toHaveCount(1);
    expect($match->fresh()->discord_channels)->toBeNull();
});

it('deletes the voice channel too when the cleanup job runs', function () {
    $match = readyMatch();
    $match->update(['discord_channels' => ['text_channel_id' => 'text-1', 'voice_channel_id' => 'voice-1']]);

    dispatch_sync(new CleanupMatchChannelJob($match->id));

    $this->fake->assertChannelDeleted('text-1');
    $this->fake->assertChannelDeleted('voice-1');
});

it('removes both channels when the voice channel cannot be set up', function () {
    $failing = new class extends FakeDiscordClient
    {
        public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
        {
            if (str_starts_with($channelId, 'fake-channel-2')) {
                throw new RuntimeException('voice permissions failed');
            }

            parent::upsertPermissionOverwrites($channelId, $overwrites);
        }
    };
    app()->instance(DiscordClient::class, $failing);

    $match = readyMatch();

    expect(fn () => dispatch_sync(new CreateMatchChannelJob($match->id)))->toThrow(RuntimeException::class);

    expect($failing->channels)->toBe([]);
    expect($failing->deletedChannelIds)->toHaveCount(2);
    expect($match->fresh()->discord_channels)->toBeNull();
});
