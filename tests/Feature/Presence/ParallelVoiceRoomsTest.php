<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Discord\Jobs\CleanupMatchChannelJob;
use App\Modules\Discord\Support\EventCategories;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Audit of the "all voice rooms in parallel" promise: one MatchReady must produce the per-team
| rooms on Discord, Mumble, TeamSpeak and the LANoMAT web voice, and the cleanup must mirror it.
*/

beforeEach(function () {
    config([
        'services.discord.guild_id' => 'guild-1',
        'services.discord.match_category_id' => 'category-1',
        'services.discord.application_id' => 'bot-1',
    ]);

    $this->discord = fakeDiscord();
    $this->voice = fakeVoice(['mumble', 'teamspeak']);
    $this->web = new FakeWebVoiceClient;
    $this->app->instance(WebVoiceClient::class, $this->web);

    $this->tournament = Tournament::factory()->live()->create(['name' => 'Duel Cup', 'team_size' => 1]);
    $this->ada = User::factory()->create(['discord_id' => '1001']);
    $this->bea = User::factory()->create(['discord_id' => '1002']);

    // One solo entry and one team entry: both shapes must get a room everywhere.
    $this->solo = TournamentEntry::factory()->solo()->for($this->tournament)->create([
        'display_name' => 'Solo Ada',
        'user_id' => $this->ada->id,
        'roster_snapshot' => null,
    ]);
    $this->team = TournamentEntry::factory()->team()->for($this->tournament)->create([
        'display_name' => 'Team Bea',
        'roster_snapshot' => [['user_id' => $this->bea->id]],
    ]);
    $this->match = GameMatch::factory()->create([
        'tournament_id' => $this->tournament->id,
        'entry1_id' => $this->solo->id,
        'entry2_id' => $this->team->id,
    ]);
});

it('creates a room per side on every provider when a match becomes ready', function () {
    event(new MatchReady($this->match));

    $expected = ['Match '.$this->match->id.' · Solo Ada', 'Match '.$this->match->id.' · Team Bea'];

    $discordRooms = collect($this->discord->channels)->where('voice', true)->pluck('name')->sort()->values()->all();
    expect($discordRooms)->toBe($expected);

    foreach ($this->voice as $provider => $fake) {
        expect(collect($fake->channels)->pluck('name')->sort()->values()->all())->toBe($expected, $provider);
    }

    $web = WebVoiceChannel::query()->where('game_match_id', $this->match->id)->orderBy('name')->get();
    expect($web->pluck('name')->all())->toBe(['Solo Ada', 'Team Bea'])
        ->and($web[0]->members->pluck('id')->all())->toBe([$this->ada->id])
        ->and($web[1]->members->pluck('id')->all())->toBe([$this->bea->id]);

    $fresh = $this->match->fresh();
    expect(array_keys($fresh->voice_channels))->toBe(['mumble', 'teamspeak'])
        ->and($fresh->discord_channels['voice_channel_ids'])->toHaveCount(2);
});

it('tears every provider down again: web and Discord on the match, Mumble and TeamSpeak with the tournament', function () {
    event(new MatchReady($this->match));

    event(new MatchCompleted($this->match));

    // Web voice: archived at once when empty.
    expect(WebVoiceChannel::query()->whereNull('archived_at')->count())->toBe(0);

    // Discord: its cleanup is delayed by design, so run the job the listener queued.
    (new CleanupMatchChannelJob($this->match->id))->handle($this->discord, app(EventCategories::class));
    expect($this->discord->deletedChannelIds)->toHaveCount(2);

    // Mumble and TeamSpeak match rooms go with the tournament.
    $before = collect($this->voice)->map(fn ($fake) => count($fake->channels))->all();
    expect($before)->toBe(['mumble' => 2, 'teamspeak' => 2]);

    event(new TournamentCompleted($this->tournament));

    foreach ($this->voice as $provider => $fake) {
        expect($fake->channels)->toBe([], $provider);
    }
    expect($this->match->fresh()->voice_channels)->toBeNull();
});
