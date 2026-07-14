<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Discord/guild config so CreateMatchChannelJob resolves a concrete
    // guild/category id (mirrors Task 18/21's MatchChannelTest and
    // VoiceOrchestrationTest conventions).
    config([
        'services.discord.guild_id' => 'guild-1',
        'services.discord.match_category_id' => 'category-1',
    ]);
});

/**
 * The full 8-solo-player double-elimination acceptance run for M3: real
 * enrollment + check-in + start, then a deterministic playthrough of every
 * `Ready` match via the orga-override path (the report/confirm handshake
 * itself is fully covered by Task 11's MatchReportFlowTest) until the
 * tournament finishes.
 *
 * Because MatchReady/MatchCompleted/TournamentCompleted are dispatched for
 * real here (no Event::fake()) and QUEUE_CONNECTION=sync in tests, this
 * drives the live listeners end to end: CreateMatchChannelJob (Discord) and
 * ProvisionMatchVoiceJob (Mumble) on every MatchReady, and
 * AnnounceAndCleanupOnCompleted's delayed CleanupMatchChannelJob plus
 * CleanupTournamentVoiceJob on completion — the sync queue driver ignores
 * `->delay()` and runs those handlers immediately, so channel cleanup is
 * observable against the fakes by the end of this test without any manual
 * job execution.
 */
it('runs a full 8-player double-elim to exactly one champion with all channels created and cleaned', function () {
    $discord = fakeDiscord();
    $mumble = fakeMumble();

    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->doubleElim()->enrollment()->create([
        'event_id' => $event->id,
        'team_size' => 1,
    ]);

    $orga = User::factory()->orga()->create();

    $entries = collect(range(1, 8))
        ->map(fn () => app(EnrollSolo::class)->handle($tournament, User::factory()->create()));

    // EnrollSolo requires Enrollment status, CheckInEntry requires CheckIn —
    // open the check-in window only after all 8 solo entries are enrolled,
    // then check each of them in for real via the action (no forceFill).
    app(OpenCheckin::class)->handle($tournament->fresh());

    $entries->each(fn ($entry) => app(CheckInEntry::class)->handle($entry->fresh()));

    app(StartTournament::class)->handle($tournament->fresh());

    $this->actingAs($orga);

    // Play every playable match with a deterministic result (lower entry id
    // wins) until the tournament finishes.
    //
    // Only matches that become Ready *through bracket progression* (i.e.
    // every match except the winners-bracket round-1 matches, which
    // BracketPersister persists as already-Ready at start time) ever get a
    // live MatchReady dispatched — see MatchProgression::apply(). So a
    // match's Discord/Mumble channels are only guaranteed to exist once it
    // was reached that way; round-1 matches never get one. Each channel is
    // created on MatchReady and — for Discord — deleted again by
    // AnnounceAndCleanupOnCompleted's delayed CleanupMatchChannelJob as soon
    // as that same match is decided (QUEUE_CONNECTION=sync ignores ->delay()
    // and runs the job inline), so "created" is asserted right before a
    // match is played, while its channel is still live, rather than read
    // back from the fakes' end state (which holds nothing once the whole
    // tournament has finished). Mumble match channels are NOT cleaned up
    // per-match (only the tournament-wide CleanupTournamentVoiceJob on
    // TournamentCompleted tears those down), so their creation is still
    // observable in the fake's final state.
    $matchesWithLiveChannels = 0;
    $decidedMatchIds = [];

    $guard = 0;
    while ($tournament->fresh()->status !== TournamentStatus::Finished && $guard++ < 100) {
        $match = GameMatch::where('tournament_id', $tournament->id)
            ->where('status', MatchStatus::Ready)->first();

        if ($match === null) {
            break;
        }

        $discordChannel = collect($discord->channels)->firstWhere('name', "match-{$match->id}");

        if ($discordChannel !== null) {
            // This match became Ready via MatchProgression, so its Discord
            // channel and Mumble voice channels were provisioned by
            // CreateMatchChannelJob/ProvisionMatchVoiceJob on that MatchReady.
            $mumble->assertChannelCreated($match->entry1->display_name);
            $mumble->assertChannelCreated($match->entry2->display_name);
            $matchesWithLiveChannels++;
        }

        [$s1, $s2] = $match->entry1_id < $match->entry2_id ? [2, 0] : [0, 2];
        app(OverrideMatchResult::class)->handle($match, $s1, $s2);
        $decidedMatchIds[] = $match->id;

        if ($discordChannel !== null) {
            // The just-decided match's Discord channel must already be gone
            // again (delayed cleanup ran inline on MatchCompleted).
            $discord->assertChannelDeleted($discordChannel['id']);
        }
    }

    expect($guard)->toBeLessThan(100, 'Guard tripped — the tournament never reached Finished.');
    // At least the winners-bracket final and the grand final are reached via
    // progression for any bracket with more than one round, so this must be
    // > 0 for an 8-player double-elim.
    expect($matchesWithLiveChannels)->toBeGreaterThan(0);

    $tournament->refresh();
    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($tournament->winner_entry_id)->not->toBeNull();

    // No open matches remain.
    expect(GameMatch::where('tournament_id', $tournament->id)->where('status', MatchStatus::Ready)->count())->toBe(0);

    // --- Channels were created during play and cleaned up by completion (fakes) ---

    $playedMatches = GameMatch::whereIn('id', $decidedMatchIds)->get();
    expect($playedMatches)->not->toBeEmpty();

    // Discord: every match that had a channel (i.e. every match reached via
    // progression) was deleted again as soon as it completed (asserted
    // live, above) — none remain live by the end.
    expect($discord->channels)->toBeEmpty('Expected every Discord match channel to have been cleaned up.');
    expect($discord->deletedChannelIds)->toHaveCount($matchesWithLiveChannels);

    foreach ($playedMatches as $match) {
        expect($match->fresh()->discord_channels)->toBeNull();
    }

    // Mumble: every decided match provisioned two temporary per-entry voice
    // channels on MatchReady (ProvisionMatchVoiceJob), left alone until the
    // tournament finished; the tournament/team tree plus every leftover
    // match channel was then explicitly torn down by
    // CleanupTournamentVoiceJob on TournamentCompleted.
    expect($mumble->deletedChannelIds)->not->toBeEmpty();
    expect($mumble->channels)->toBeEmpty('Expected every Mumble channel (tournament, team and match) to have been cleaned up.');

    foreach ($playedMatches as $match) {
        expect($match->fresh()->voice_channels)->toBeNull();
    }
});
