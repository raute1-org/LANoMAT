<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Jobs\CleanupMatchChannelJob;
use App\Modules\Discord\Jobs\CreateMatchChannelJob;
use App\Modules\Discord\Models\DiscordVoiceState;
use App\Modules\Discord\Support\EventCategories;
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

    $alpha = collect($this->fake->channels)->firstWhere('name', "Match {$match->id} · Team Alpha");
    $bravo = collect($this->fake->channels)->firstWhere('name', "Match {$match->id} · Solo Bravo");
    expect($alpha['voice'])->toBeTrue()->and($bravo['voice'])->toBeTrue();
    expect($channel['voice'])->toBeFalse();
    expect($match->fresh()->discord_channels)->toBe([
        'text_channel_id' => $channel['id'],
        'voice_channel_ids' => ['entry1' => $alpha['id'], 'entry2' => $bravo['id']],
    ]);

    // Every team gets its own room, visible to all but joinable only by that team
    // (and admins): @everyone may look but not connect, the team may connect and speak.
    $alphaOverwrites = collect(collect($this->fake->overwrites)->firstWhere('channelId', $alpha['id'])['overwrites']);
    // The bot keeps CONNECT and SPEAK in the room: Discord only lets it hand out rights it holds
    // itself, and @everyone losing CONNECT would otherwise take that away from it.
    expect($alphaOverwrites->firstWhere('id', 'bot-1')['allow'])->toBe((string) (1024 | (1 << 20) | (1 << 21)));
    expect($alphaOverwrites->firstWhere('id', 'guild-1'))->toMatchArray(['type' => 0, 'allow' => '1024', 'deny' => (string) (1 << 20)]);
    expect($alphaOverwrites->firstWhere('id', '111111')['allow'])->toBe((string) (1024 | (1 << 20) | (1 << 21)));
    expect($alphaOverwrites->pluck('id')->all())->not->toContain('222222');

    $bravoOverwrites = collect(collect($this->fake->overwrites)->firstWhere('channelId', $bravo['id'])['overwrites']);
    expect($bravoOverwrites->firstWhere('id', '222222')['allow'])->toBe((string) (1024 | (1 << 20) | (1 << 21)));
    expect($bravoOverwrites->pluck('id')->all())->not->toContain('111111');
});

it('lets admins join every team room', function () {
    User::factory()->create(['role' => Role::Admin, 'discord_id' => '999999']);
    User::factory()->create(['role' => Role::Participant, 'discord_id' => '888888']);
    $match = readyMatch();

    event(new MatchReady($match));

    foreach (["Match {$match->id} · Team Alpha", "Match {$match->id} · Solo Bravo"] as $name) {
        $room = collect($this->fake->channels)->firstWhere('name', $name);
        $overwrites = collect(collect($this->fake->overwrites)->firstWhere('channelId', $room['id'])['overwrites']);
        expect($overwrites->firstWhere('id', '999999')['allow'])->toBe((string) (1024 | (1 << 20) | (1 << 21)));
        expect($overwrites->pluck('id')->all())->not->toContain('888888');
    }
});

it('puts the channels of a match into a category of the event when no fixed category is configured', function () {
    config(['services.discord.match_category_id' => null]);
    $match = readyMatch();

    event(new MatchReady($match));

    $category = collect($this->fake->channels)->firstWhere('category', true);
    expect($category['name'])->toBe($match->tournament->event->name);
    foreach (collect($this->fake->channels)->where('category', false) as $channel) {
        expect($channel['parentId'])->toBe($category['id']);
    }
});

it('is idempotent when MatchReady fires twice for the same match', function () {
    $match = readyMatch();

    event(new MatchReady($match));
    event(new MatchReady($match));

    expect(collect($this->fake->channels))->toHaveCount(3); // text + one room per team, once
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

it('moves the finished match text channel into the archive of its event, read-only', function () {
    $match = readyMatch();
    $match->update(['discord_channels' => ['text_channel_id' => 'fake-channel-text']]);
    $this->fake->channels[] = ['guildId' => 'guild-1', 'name' => "match-{$match->id}", 'parentId' => 'category-1', 'voice' => false, 'id' => 'fake-channel-text', 'category' => false];

    (new CleanupMatchChannelJob($match->id))->handle($this->fake, app(EventCategories::class));

    $archive = collect($this->fake->channels)->firstWhere('name', 'Archiv: '.$match->tournament->event->name);
    expect($archive)->not->toBeNull();
    expect($this->fake->moves)->toBe([['channelId' => 'fake-channel-text', 'parentId' => $archive['id']]]);
    expect($this->fake->deletedChannelIds)->toBe([]);
    expect($match->fresh()->discord_channels)->toBeNull();

    // everyone may read the archive, nobody may write
    $overwrites = collect(collect($this->fake->overwrites)->firstWhere('channelId', 'fake-channel-text')['overwrites']);
    $everyone = $overwrites->firstWhere('id', 'guild-1');
    expect($everyone['type'])->toBe(0);
    expect((int) $everyone['allow'] & (1 << 10))->toBe(1 << 10); // VIEW_CHANNEL
    expect((int) $everyone['allow'] & (1 << 16))->toBe(1 << 16); // READ_MESSAGE_HISTORY
    expect((int) $everyone['deny'] & (1 << 11))->toBe(1 << 11); // SEND_MESSAGES
    expect((int) $everyone['deny'] & (1 << 6))->toBe(1 << 6); // ADD_REACTIONS
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

it('deletes empty team rooms at once and archives the text channel', function () {
    Bus::fake([CleanupMatchChannelJob::class]);
    $match = readyMatch();
    $match->update(['discord_channels' => ['text_channel_id' => 'text-1', 'voice_channel_ids' => ['entry1' => 'voice-1', 'entry2' => 'voice-2']]]);

    (new CleanupMatchChannelJob($match->id))->handle($this->fake, app(EventCategories::class));

    $this->fake->assertChannelDeleted('voice-1');
    $this->fake->assertChannelDeleted('voice-2');
    expect($this->fake->deletedChannelIds)->not->toContain('text-1');
    expect(collect($this->fake->moves)->pluck('channelId')->all())->toBe(['text-1']);
    expect($match->fresh()->discord_channels)->toBeNull();
    Bus::assertNotDispatched(CleanupMatchChannelJob::class);
});

it('still removes a voice room from a match created before every team had its own', function () {
    $match = readyMatch();
    $match->update(['discord_channels' => ['text_channel_id' => 'text-1', 'voice_channel_id' => 'voice-old']]);

    (new CleanupMatchChannelJob($match->id))->handle($this->fake, app(EventCategories::class));

    $this->fake->assertChannelDeleted('voice-old');
    expect($match->fresh()->discord_channels)->toBeNull();
});

it('leaves a room alone while someone is in it and looks again later', function () {
    config(['queue.default' => 'redis']); // a sync queue never re-queues
    Bus::fake([CleanupMatchChannelJob::class]);
    $match = readyMatch();
    $match->update(['discord_channels' => ['text_channel_id' => 'text-1', 'voice_channel_ids' => ['entry1' => 'voice-1', 'entry2' => 'voice-2']]]);
    DiscordVoiceState::query()->create(['discord_user_id' => '111111', 'channel_id' => 'voice-1']);

    (new CleanupMatchChannelJob($match->id))->handle($this->fake, app(EventCategories::class));

    // The empty room and the text channel are dealt with at once; the occupied one stays.
    expect($this->fake->deletedChannelIds)->toBe(['voice-2']);
    expect(collect($this->fake->moves)->pluck('channelId')->all())->toBe(['text-1']);
    expect($match->fresh()->discord_channels)->toBe(['voice_channel_ids' => ['entry1' => 'voice-1']]);
    Bus::assertDispatched(CleanupMatchChannelJob::class, fn (CleanupMatchChannelJob $job) => $job->matchId === $match->id && $job->delay !== null);

    // Once the last person has left, the next run removes it and does not archive again.
    DiscordVoiceState::query()->delete();
    (new CleanupMatchChannelJob($match->id))->handle($this->fake, app(EventCategories::class));

    expect($this->fake->deletedChannelIds)->toBe(['voice-2', 'voice-1']);
    expect(collect($this->fake->moves))->toHaveCount(1);
    expect($match->fresh()->discord_channels)->toBeNull();
});

it('does not re-queue itself on a sync queue where a delay would loop', function () {
    Bus::fake([CleanupMatchChannelJob::class]);
    $match = readyMatch();
    $match->update(['discord_channels' => ['voice_channel_ids' => ['entry1' => 'voice-1']]]);
    DiscordVoiceState::query()->create(['discord_user_id' => '111111', 'channel_id' => 'voice-1']);

    (new CleanupMatchChannelJob($match->id))->handle($this->fake, app(EventCategories::class));

    Bus::assertNotDispatched(CleanupMatchChannelJob::class);
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

it('removes every channel it made when the second team room cannot be set up', function () {
    $failing = new class extends FakeDiscordClient
    {
        public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
        {
            if ($channelId === 'fake-channel-3') {
                throw new RuntimeException('second room permissions failed');
            }

            parent::upsertPermissionOverwrites($channelId, $overwrites);
        }
    };
    app()->instance(DiscordClient::class, $failing);

    $match = readyMatch();

    expect(fn () => dispatch_sync(new CreateMatchChannelJob($match->id)))->toThrow(RuntimeException::class);

    expect($failing->channels)->toBe([]);
    expect($failing->deletedChannelIds)->toHaveCount(3);
    expect($match->fresh()->discord_channels)->toBeNull();
});

it('keeps removing the other channels when one of them cannot be deleted while rolling back', function () {
    $failing = new class extends FakeDiscordClient
    {
        public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
        {
            if ($channelId === 'fake-channel-3') {
                throw new RuntimeException('second room permissions failed');
            }

            parent::upsertPermissionOverwrites($channelId, $overwrites);
        }

        public function deleteChannel(string $channelId): void
        {
            if ($channelId === 'fake-channel-1') {
                throw new RuntimeException('delete refused');
            }

            parent::deleteChannel($channelId);
        }
    };
    app()->instance(DiscordClient::class, $failing);

    $match = readyMatch();

    // The error that started the rollback is the one that surfaces, not the failed delete.
    expect(fn () => dispatch_sync(new CreateMatchChannelJob($match->id)))->toThrow(RuntimeException::class, 'second room permissions failed');

    expect($failing->deletedChannelIds)->toBe(['fake-channel-2', 'fake-channel-3']);
});
