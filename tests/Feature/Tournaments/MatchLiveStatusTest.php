<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Presence\Support\PresenceProjection;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\EnterWarmup;
use App\Modules\Tournaments\Actions\GoLive;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Actions\WithdrawMatchReport;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

/** @return array{0: Tournament, 1: GameMatch, 2: GameMatch} tournament and its two round-1 matches */
function liveStatusBracket(?Event $event = null): array
{
    $factory = Tournament::factory()->checkIn()->singleElim();
    $tournament = ($event !== null ? $factory->for($event) : $factory)->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);
    $tournament = app(StartTournament::class)->handle($tournament)->fresh();
    $round1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->get();

    return [$tournament, $round1[0], $round1[1]];
}

function sendLive(GameMatch $match): GameMatch
{
    $match = app(EnterWarmup::class)->handle($match);

    return app(GoLive::class)->handle($match, User::factory()->create(['role' => Role::Orga]));
}

it('lets a Live match be reported and confirmed, progressing the bracket', function () {
    [, $a] = liveStatusBracket();
    $a = sendLive($a);
    expect($a->status)->toBe(MatchStatus::Live);

    $report = app(SubmitMatchReport::class)->handle($a, TournamentEntry::find($a->entry1_id), 2, 0);
    expect($a->fresh()->status)->toBe(MatchStatus::Reported);

    $confirmed = app(ConfirmMatchReport::class)->handle($report, TournamentEntry::find($a->entry2_id), $a->fresh()->lock_version);

    expect($confirmed->status)->toBe(MatchStatus::Completed)
        ->and($confirmed->winner_entry_id)->toBe($a->entry1_id);
});

it('restores Live when a report on a warmed-up match is withdrawn, and Ready for a never-warmed one', function () {
    [, $a, $b] = liveStatusBracket();
    $a = sendLive($a);

    $report = app(SubmitMatchReport::class)->handle($a, TournamentEntry::find($a->entry1_id), 1, 0);
    app(WithdrawMatchReport::class)->handle($report, TournamentEntry::find($a->entry1_id), $a->fresh()->lock_version);
    expect($a->fresh()->status)->toBe(MatchStatus::Live);

    $report = app(SubmitMatchReport::class)->handle($b, TournamentEntry::find($b->entry1_id), 1, 0);
    app(WithdrawMatchReport::class)->handle($report, TournamentEntry::find($b->entry1_id), $b->fresh()->lock_version);
    expect($b->fresh()->status)->toBe(MatchStatus::Ready);
});

it('keeps a Live match Live and does not re-fire MatchReady when another match completes', function () {
    [, $a, $b] = liveStatusBracket();
    sendLive($a);

    EventFacade::fake([MatchReady::class]);

    $report = app(SubmitMatchReport::class)->handle($b, TournamentEntry::find($b->entry1_id), 2, 1);
    app(ConfirmMatchReport::class)->handle($report, TournamentEntry::find($b->entry2_id), $b->fresh()->lock_version);

    expect($a->fresh()->status)->toBe(MatchStatus::Live);
    EventFacade::assertNotDispatched(MatchReady::class);
});

it('keeps a Warmup match in Warmup when another match completes', function () {
    [, $a, $b] = liveStatusBracket();
    app(EnterWarmup::class)->handle($a);

    $report = app(SubmitMatchReport::class)->handle($b, TournamentEntry::find($b->entry1_id), 2, 1);
    app(ConfirmMatchReport::class)->handle($report, TournamentEntry::find($b->entry2_id), $b->fresh()->lock_version);

    expect($a->fresh()->status)->toBe(MatchStatus::Warmup);
});

it('includes Live matches in the upcoming-matches scene payload', function () {
    $event = Event::factory()->live()->create();
    [$tournament, $a, $b] = liveStatusBracket($event);
    sendLive($a);

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::UpcomingMatches,
        'config' => new SceneConfig(tournamentId: $tournament->id),
    ]);

    $statuses = collect(ScenePayload::for($scene)['data']['matches'])->pluck('status', 'id');

    expect($statuses[$a->id])->toBe('live')
        ->and($statuses[$b->id])->toBe('ready');
});

it('counts a Live match as playing in the presence projection', function () {
    $event = Event::factory()->create();
    $tournament = Tournament::factory()->for($event)->for(Game::factory()->create(['name' => 'Quake']))->live()->create();

    $user = User::factory()->create(['name' => 'Ada']);
    EventRegistration::factory()->for($event)->for($user, 'user')->checkedIn()->create();
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'team_id' => null, 'display_name' => 'Ada']);
    $other = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'display_name' => 'Bob']);

    GameMatch::factory()->for($tournament)->create([
        'status' => MatchStatus::Live,
        'entry1_id' => $entry->id,
        'entry2_id' => $other->id,
    ]);

    $board = PresenceProjection::forEvent($event);

    expect(collect($board->participants)->firstWhere('name', 'Ada')->isPlaying)->toBeTrue()
        ->and($board->liveMatches)->toHaveCount(1);
});

it('backfills only ready matches that were warmed up to live', function () {
    $warmed = GameMatch::factory()->create(['status' => MatchStatus::Ready, 'warmup_started_at' => now()]);
    $plain = GameMatch::factory()->create(['status' => MatchStatus::Ready]);
    $pending = GameMatch::factory()->create(['status' => MatchStatus::Pending, 'warmup_started_at' => now()]);

    $migration = require database_path('migrations/2026_11_03_100000_backfill_live_match_status.php');
    $migration->up();

    expect(DB::table('matches')->where('id', $warmed->id)->value('status'))->toBe('live')
        ->and(DB::table('matches')->where('id', $plain->id)->value('status'))->toBe('ready')
        ->and(DB::table('matches')->where('id', $pending->id)->value('status'))->toBe('pending');
});
