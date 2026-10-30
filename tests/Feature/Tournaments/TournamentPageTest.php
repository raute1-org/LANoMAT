<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    // startEightEntrySingleElim() and the report/confirm routes dispatch
    // real TournamentStarted/MatchReady/MatchCompleted events, which now
    // also reach Task 18's Discord listener and Task 21's voice-provisioning
    // listener — fake both globally so this suite never hits a real server.
    fakeDiscord();
    fakeMumble();
});

/**
 * Starts an 8-entry single-elimination tournament (7 matches total: 4 + 2 +
 * 1) and returns it, freshly reloaded.
 */
function startEightEntrySingleElim(): Tournament
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);

    return app(StartTournament::class)->handle($tournament)->fresh();
}

it('renders the tournaments index for an event with german labels', function () {
    $event = Event::factory()->live()->create();
    Tournament::factory()->for($event)->enrollment()->create(['name' => 'Season Opener']);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/tournaments")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Index')
            ->where('labels.title', 'Turniere')
            ->has('tournaments', 1)
            ->where('tournaments.0.name', 'Season Opener')
        );
});

it('renders the bracket for an 8-entry single-elimination tournament with 7 matches', function () {
    $tournament = startEightEntrySingleElim();

    $this->actingAs(User::factory()->create())->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('labels.title', 'Turnier')
            // i18n gate: report/confirm surface renders German action labels,
            // not raw translation keys.
            ->where('reportLabels.report_action', 'Melden')
            ->where('reportLabels.confirm_action', 'Bestätigen')
            // Task 5: BracketMatchCard's never-played-match placeholder labels.
            // Named `bracketStateLabels` (final review fix wave) to match
            // OverlayController's identical concept and stop colliding with
            // the pre-existing `bracketLabels` round-heading prop.
            ->where('bracketStateLabels.bye', 'Freilos')
            ->where('bracketStateLabels.not_held', 'Entfällt')
            ->has('matches', 7)
            ->has('matches.0', fn (AssertableInertia $match) => $match
                ->hasAll(['id', 'round', 'bracket', 'position', 'nextMatchId', 'nextSlot', 'slot1', 'slot2', 'entry1Id', 'entry2Id', 'score1', 'score2', 'winnerEntryId', 'status', 'isBye', 'lockVersion', 'server', 'warmupStartedAt', 'spectateHint'])
            )
        );
});

it('exposes an empty myMatchVoiceLinks when the viewer has no match voice channel provisioned', function () {
    // Round-1 matches are now provisioned a voice channel immediately at
    // start (Fix #1), so "no channel provisioned yet" is only true once the
    // viewer's round-1 match is done and their next (round-2) match has not
    // become Ready yet — that round-2 match is what activeMatchFor() must
    // surface once it exists but before Discord/Mumble provisioning for it
    // has actually run (voice_channels still null on it).
    $tournament = startEightEntrySingleElim();
    $round1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->get();
    $match = $round1->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $viewer = User::find($entry1->user_id);

    // Wipe the round-1 match's own voice channels to simulate the viewer's
    // *active* match (still Ready, e.g. voice provisioning failed/pending)
    // genuinely having none yet — isolates this test from Fix #1's
    // round-1-provisioning side effect so it still tests the null-link path.
    $match->update(['voice_channels' => null]);

    $this->actingAs($viewer)
        ->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('myEntryId', $entry1->id)
            ->where('myMatchVoiceLinks', [])
        );
});

it('surfaces the viewer active (Ready/Reported/Disputed) match rather than a stale earlier one', function () {
    $tournament = startEightEntrySingleElim();
    $round1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->get();
    $match = $round1->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry1->user_id);
    $opponent = User::find($entry2->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])
        ->assertRedirect();

    // entry1 won and advanced: its round-1 match is now Completed. Give the
    // completed match's own channel a null id for entry1's slot (so picking
    // the stale round-1 match, the pre-fix behavior, would yield a null
    // link) while the downstream round-2 match — now Ready — gets a
    // non-null channel id for entry1. Only surfacing the active round-2
    // match produces a non-null link.
    $completedMatch = $match->fresh();
    expect($completedMatch->status->value)->toBe('completed');
    $completedEntry1IsFirst = $completedMatch->entry1_id === $entry1->id;
    $completedMatch->update(['voice_channels' => ['mumble' => $completedEntry1IsFirst
        ? ['entry1_channel_id' => null, 'entry2_channel_id' => 'stale-2']
        : ['entry1_channel_id' => 'stale-1', 'entry2_channel_id' => null],
    ]]);

    $nextMatch = GameMatch::find($completedMatch->next_match_id);
    // Only one of the two round-1 feeders into this round-2 match has been
    // decided so far, so it is still Pending (not yet Ready) — the point of
    // this test is that activeMatchFor() must still prefer it over the
    // stale Completed round-1 match (falling back to "most recent match" once
    // no Ready/Reported/Disputed match exists), not that it is already Ready.
    expect($nextMatch->status->value)->toBe('pending');
    $nextEntry1IsFirst = $nextMatch->entry1_id === $entry1->id;
    $nextMatch->update(['voice_channels' => ['mumble' => $nextEntry1IsFirst
        ? ['entry1_channel_id' => 'active-1', 'entry2_channel_id' => null]
        : ['entry1_channel_id' => null, 'entry2_channel_id' => 'active-2'],
    ]]);

    $viewer = User::find($entry1->user_id);

    $this->actingAs($viewer)
        ->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('myEntryId', $entry1->id)
            // A non-empty list proves the active round-2 match was
            // surfaced, not the stale completed round-1 match (whose link
            // for entry1 is deliberately null above).
            ->has('myMatchVoiceLinks', 1)
        );
});

it('exposes the viewer own mumble join link once their match has voice channels provisioned', function () {
    config(['services.mumble.host' => 'voice.example.test', 'services.mumble.port' => 64738]);

    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $viewer = User::find($entry1->user_id);

    $match->update(['voice_channels' => ['mumble' => ['entry1_channel_id' => 101, 'entry2_channel_id' => 102]]]);

    $this->actingAs($viewer)
        ->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->has('myMatchVoiceLinks', 1)
            ->where('myMatchVoiceLinks.0.provider', 'mumble')
            ->where('myMatchVoiceLinks.0.url', 'mumble://voice.example.test:64738/'.$entry1->display_name)
            ->where('myMatchVoiceLinks.0.isDefault', true)
        );
});

it('404s on show for a tournament whose event is still draft', function () {
    $event = Event::factory()->draft()->create();
    $tournament = Tournament::factory()->for($event)->enrollment()->create();

    $this->actingAs(User::factory()->create())->get("/tournaments/{$tournament->id}")
        ->assertNotFound();
});

it('creates an entry on POST enroll while the tournament is in enrollment', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->post("/tournaments/{$tournament->id}/enroll")
        ->assertRedirect();

    expect(TournamentEntry::query()->where('tournament_id', $tournament->id)->where('user_id', $user->id)->exists())
        ->toBeTrue();
});

it('rejects enroll outside the enrollment window', function () {
    $tournament = Tournament::factory()->create(); // draft
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/tournaments/{$tournament->id}/enroll")
        ->assertForbidden();
});

it('lets a team owner enroll their team into a team tournament', function () {
    $tournament = Tournament::factory()->enrollment()->create(['team_size' => 2]);
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id]);
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post("/tournaments/{$tournament->id}/enroll", ['team_id' => $team->id])
        ->assertRedirect();

    expect(TournamentEntry::query()->where('tournament_id', $tournament->id)->where('team_id', $team->id)->exists())
        ->toBeTrue();
});

it('forbids enrolling a team the acting user does not own or manage', function () {
    $tournament = Tournament::factory()->enrollment()->create(['team_size' => 2]);
    $team = Team::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $team->owner_id]);
    TeamMember::factory()->create(['team_id' => $team->id]);
    $stranger = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $stranger->id]);

    $this->actingAs($stranger)
        ->post("/tournaments/{$tournament->id}/enroll", ['team_id' => $team->id])
        ->assertForbidden();

    expect(TournamentEntry::query()->where('tournament_id', $tournament->id)->where('team_id', $team->id)->exists())
        ->toBeFalse();
});

it('creates a report on POST by a match participant', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $reporter = User::find($entry1->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    expect(MatchReport::query()->where('match_id', $match->id)->exists())->toBeTrue();
    expect($match->fresh()->status->value)->toBe('reported');
});

it('rejects a tied score report with a validation error', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $reporter = User::find($entry1->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 2])
        ->assertInvalid(['score2']);

    expect(MatchReport::query()->where('match_id', $match->id)->exists())->toBeFalse();
    expect($match->fresh()->status->value)->toBe('ready');
});

it('forbids a non-participant from reporting a match result', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertForbidden();

    expect(MatchReport::query()->where('match_id', $match->id)->exists())->toBeFalse();
});

it('forbids a non-participant from confirming a report', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $reporter = User::find($entry1->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $report = MatchReport::query()->where('match_id', $match->id)->firstOrFail();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])
        ->assertForbidden();

    expect($report->fresh()->status->value)->toBe('pending');
});

it('lets the opponent confirm a report, advancing the match to completed', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry1->user_id);
    $opponent = User::find($entry2->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $lockVersion = $match->fresh()->lock_version;

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $lockVersion])
        ->assertRedirect();

    expect($match->fresh()->status->value)->toBe('completed');
});

it('lets the opponent dispute a report', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry1->user_id);
    $opponent = User::find($entry2->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/dispute")
        ->assertRedirect();

    expect($match->fresh()->status->value)->toBe('disputed');
});

it('refuses confirming a report that has already been disputed with a friendly redirect (Ruling G)', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry1->user_id);
    $opponent = User::find($entry2->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/dispute")
        ->assertRedirect();

    // pendingReportFor() must no longer resolve the (now Disputed) report,
    // so confirm is refused (friendly redirect, Ruling G) instead of re-confirming a disputed report.
    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])
        ->assertRedirect();

    expect($match->fresh()->status->value)->toBe('disputed');
});

it('refuses disputing a report on a match that already completed with a friendly redirect (Ruling G)', function () {
    $tournament = startEightEntrySingleElim();
    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $entry1 = TournamentEntry::find($match->entry1_id);
    $entry2 = TournamentEntry::find($match->entry2_id);
    $reporter = User::find($entry1->user_id);
    $opponent = User::find($entry2->user_id);

    $this->actingAs($reporter)
        ->post("/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1])
        ->assertRedirect();

    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/confirm", ['lock_version' => $match->fresh()->lock_version])
        ->assertRedirect();

    // pendingReportFor() must no longer resolve the (now Confirmed) report,
    // so dispute is refused (friendly redirect, Ruling G) instead of flipping a Completed match back to Disputed.
    $this->actingAs($opponent)
        ->post("/matches/{$match->id}/dispute")
        ->assertRedirect();

    expect($match->fresh()->status->value)->toBe('completed');
});

it('creates an entry on POST checkin while the entry owner acts during the check-in window', function () {
    $tournament = Tournament::factory()->checkIn()->create();
    $user = User::factory()->create();
    $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->post("/tournaments/{$tournament->id}/checkin")
        ->assertRedirect();

    expect($entry->fresh()->status->value)->toBe('checked_in');
});

it('resolves the german tournaments index and show titles', function () {
    app()->setLocale('de');

    expect(__('tournaments.page.index_title'))->toBe('Turniere')
        ->and(__('tournaments.page.show_title'))->toBe('Turnier');
});

it('gives team tournaments a team picker with only the viewer-owned teams on the index', function () {
    $tournament = Tournament::factory()->for(Event::factory()->live())->enrollment()->create(['team_size' => 2]);
    $owner = User::factory()->create();
    $full = Team::factory()->create(['owner_id' => $owner->id, 'name' => 'Full Squad']);
    TeamMember::factory()->create(['team_id' => $full->id, 'user_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $full->id]);
    $short = Team::factory()->create(['owner_id' => $owner->id, 'name' => 'Short Squad']);
    TeamMember::factory()->create(['team_id' => $short->id, 'user_id' => $owner->id]);
    $foreign = Team::factory()->create(['name' => 'Foreign']);
    TeamMember::factory()->create(['team_id' => $foreign->id, 'user_id' => $owner->id]);
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->get("/events/{$tournament->event->slug}/tournaments")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tournaments.0.teamPicker.teamSize', 2)
            ->has('tournaments.0.teamPicker.teams', 2)
            ->where('tournaments.0.teamPicker.teams.0.name', 'Full Squad')
            ->where('tournaments.0.teamPicker.teams.0.eligible', true)
            ->where('tournaments.0.teamPicker.teams.1.eligible', false)
            ->where('tournaments.0.teamPicker.createUrl', route('teams.index'))
        );
});

it('has no team picker for solo tournaments and an empty one for users without a team', function () {
    $solo = Tournament::factory()->for(Event::factory()->live())->enrollment()->create(['team_size' => 1]);
    $teamT = Tournament::factory()->for($solo->event)->enrollment()->create(['team_size' => 3]);

    $this->actingAs(User::factory()->create())->get("/events/{$solo->event->slug}/tournaments")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tournaments', fn ($list) => collect($list)->firstWhere('id', $solo->id)['teamPicker'] === null)
            ->where('tournaments', fn ($list) => collect(collect($list)->firstWhere('id', $teamT->id)['teamPicker']['teams'])->isEmpty())
        );
});

it('exposes the team picker on the tournament show page as well', function () {
    $tournament = Tournament::factory()->for(Event::factory()->live())->enrollment()->create(['team_size' => 2]);

    $this->actingAs(User::factory()->create())->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teamPicker.teamSize', 2)
            ->has('teamPicker.teams', 0)
        );
});

it('flashes an error instead of a 404 when a team tournament is enrolled without a team', function () {
    $tournament = Tournament::factory()->for(Event::factory()->live())->enrollment()->create(['team_size' => 2]);
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);

    $this->actingAs($user)->post("/tournaments/{$tournament->id}/enroll")->assertRedirect();

    expect(TournamentEntry::query()->where('tournament_id', $tournament->id)->exists())->toBeFalse();
});

// F-S4-11
it('lists live and open tournaments before finished ones, with enrollment counts', function () {
    $event = Event::factory()->live()->create();
    $finished = Tournament::factory()->for($event)->create(['name' => 'Done Cup', 'status' => TournamentStatus::Finished, 'starts_at' => now()->subDay()]);
    $open = Tournament::factory()->for($event)->enrollment()->create(['name' => 'Open Cup', 'max_entries' => 8, 'team_size' => 1, 'starts_at' => now()->addDays(3)]);
    $live = Tournament::factory()->for($event)->live()->create(['name' => 'Live Cup', 'starts_at' => now()->addDays(4)]);
    TournamentEntry::factory()->count(3)->create(['tournament_id' => $open->id]);
    TournamentEntry::factory()->create(['tournament_id' => $open->id, 'status' => EntryStatus::Withdrawn]);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/tournaments")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tournaments.0.name', 'Live Cup')
            ->where('tournaments.1.name', 'Open Cup')
            ->where('tournaments.2.name', 'Done Cup')
            ->where('tournaments.1.entryCount', 3)
            ->where('tournaments.1.maxEntries', 8)
            ->where('tournaments.1.teamSize', 1)
        );
});

it('shows a plain team member that their team is entered, without owner actions', function () {
    $tournament = Tournament::factory()->for(Event::factory()->live()->create())->enrollment()->create(['team_size' => 2]);
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id]);
    TournamentEntry::factory()->team()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id]);

    $this->actingAs($member)->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn ($page) => $page
            ->where('myEntry.status', 'registered')
            ->where('myEntry.isOwner', false)
            ->where('myEntry.canWithdraw', false));

    $this->actingAs($member)->get("/events/{$tournament->event->slug}/tournaments")
        ->assertInertia(fn ($page) => $page
            ->where('tournaments.0.myEntry.isOwner', false));

    $this->actingAs($owner)->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn ($page) => $page->where('myEntry.isOwner', true));
});
