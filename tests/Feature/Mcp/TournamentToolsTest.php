<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\MatchResultTool;
use App\Modules\Mcp\Tools\TournamentBracketTool;
use App\Modules\Mcp\Tools\TournamentCheckinTool;
use App\Modules\Mcp\Tools\TournamentListTool;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

/**
 * A started 4-entry single-elimination tournament: two round-1 matches, both
 * Ready, each entry owned by a distinct user. Returns the tournament, the
 * round-1 matches (by position) and the four users (index 0..3).
 *
 * @return array{0: Tournament, 1: Collection<int, GameMatch>, 2: Collection<int, User>}
 */
function mcpStartedTournament(?Event $event = null): array
{
    $event ??= Event::factory()->live()->create();

    $tournament = Tournament::factory()->checkIn()->create(['event_id' => $event->id]);
    $users = User::factory()->count(4)->create();

    foreach ($users as $user) {
        TournamentEntry::factory()->checkedIn()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
        ]);
        EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);
    }

    $started = app(StartTournament::class)->handle($tournament);

    $matches = GameMatch::query()
        ->where('tournament_id', $started->id)
        ->where('round', 1)
        ->orderBy('position')
        ->get();

    return [$started, $matches, $users];
}

/** The user owning the given match slot. */
function mcpOwnerOf(GameMatch $match, int $slot): User
{
    $entryId = $slot === 1 ? $match->entry1_id : $match->entry2_id;

    return User::query()->findOrFail(TournamentEntry::query()->findOrFail($entryId)->user_id);
}

it('lists an event\'s tournaments with the caller\'s entry and hides drafts', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();

    $open = Tournament::factory()->enrollment()->create(['event_id' => $event->id, 'name' => 'Open Cup']);
    Tournament::factory()->create(['event_id' => $event->id, 'name' => 'Hidden Draft Cup']);
    TournamentEntry::factory()->create(['tournament_id' => $open->id, 'user_id' => $user->id]);

    LanomatServer::actingAs($user);

    $response = LanomatServer::tool(TournamentListTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertSee('Open Cup');

    $response->assertDontSee('Hidden Draft Cup');
    $response->assertStructuredContentCount(1, 'tournaments');
    $response->assertStructuredContentPath('tournaments.0.my_entry.status', 'registered');
});

it('returns the bracket of a visible tournament and 404s an invisible one', function () {
    [$tournament, $round1] = mcpStartedTournament();
    $match = $round1->firstOrFail();

    LanomatServer::actingAs(mcpOwnerOf($match, 1));

    $response = LanomatServer::tool(TournamentBracketTool::class, ['tournament_id' => $tournament->id])
        ->assertOk()
        ->assertStructuredContentPath('tournament.id', $tournament->id)
        ->assertStructuredContentPath('matches.0.status', 'ready');

    // The bracket tool returns the whole bracket (round 1 plus the final),
    // not only the matches of the first round.
    $response->assertStructuredContentCount(GameMatch::query()->where('tournament_id', $tournament->id)->count(), 'matches');

    $draft = Tournament::factory()->create(['event_id' => $tournament->event_id]);

    LanomatServer::tool(TournamentBracketTool::class, ['tournament_id' => $draft->id])
        ->assertHasErrors(['Not found']);
});

it('checks the caller\'s own entry in inside the window', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $tournament = Tournament::factory()->checkIn()->create([
        'event_id' => $event->id,
        'checkin_opens_at' => now()->subHour(),
        'checkin_closes_at' => now()->addHour(),
    ]);
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(TournamentCheckinTool::class, ['tournament_id' => $tournament->id])
        ->assertOk()
        ->assertStructuredContentPath('entry.id', $entry->id)
        ->assertStructuredContentPath('entry.status', 'checked_in');

    expect($entry->fresh()->checked_in_at)->not->toBeNull();
});

it('refuses check-in without an event registration (policy 403)', function () {
    $user = User::factory()->create();
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subHour(),
        'checkin_closes_at' => now()->addHour(),
    ]);
    TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(TournamentCheckinTool::class, ['tournament_id' => $tournament->id])
        ->assertHasErrors(['Forbidden']);
});

it('reports a match result as one participant', function () {
    [, $matches] = mcpStartedTournament();
    $match = $matches->first();

    LanomatServer::actingAs(mcpOwnerOf($match, 1));

    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id,
        'action' => 'report',
        'score1' => 2,
        'score2' => 1,
    ])
        ->assertOk()
        ->assertStructuredContentPath('match.status', 'reported')
        ->assertStructuredContentPath('report.status', 'pending');

    expect($match->fresh()->status->value)->toBe('reported');
});

it('confirms a reported result as the opponent', function () {
    [, $matches] = mcpStartedTournament();
    $match = $matches->first();

    LanomatServer::actingAs(mcpOwnerOf($match, 1));
    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id, 'action' => 'report', 'score1' => 2, 'score2' => 0,
    ])->assertOk();

    LanomatServer::actingAs(mcpOwnerOf($match, 2));

    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id,
        'action' => 'confirm',
        'lock_version' => $match->fresh()->lock_version,
    ])
        ->assertOk()
        ->assertStructuredContentPath('match.status', 'completed')
        ->assertStructuredContentPath('match.score1', 2)
        ->assertStructuredContentPath('match.score2', 0)
        ->assertStructuredContentPath('report.status', 'confirmed');
});

it('disputes a reported result as the opponent', function () {
    [, $matches] = mcpStartedTournament();
    $match = $matches->first();

    LanomatServer::actingAs(mcpOwnerOf($match, 1));
    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id, 'action' => 'report', 'score1' => 1, 'score2' => 3,
    ])->assertOk();

    LanomatServer::actingAs(mcpOwnerOf($match, 2));

    LanomatServer::tool(MatchResultTool::class, ['match_id' => $match->id, 'action' => 'dispute'])
        ->assertOk()
        ->assertStructuredContentPath('match.status', 'disputed')
        ->assertStructuredContentPath('report.status', 'disputed');
});

it('withdraws a reported result as the reporter', function () {
    [, $matches] = mcpStartedTournament();
    $match = $matches->first();
    $reporter = mcpOwnerOf($match, 1);

    LanomatServer::actingAs($reporter);
    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id, 'action' => 'report', 'score1' => 3, 'score2' => 1,
    ])->assertOk();

    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id,
        'action' => 'withdraw',
        'lock_version' => $match->fresh()->lock_version,
    ])
        ->assertOk()
        ->assertStructuredContentPath('match.status', 'ready')
        ->assertStructuredContentPath('report.status', 'withdrawn');
});

it('refuses a reporter confirming their own report (policy 403)', function () {
    [, $matches] = mcpStartedTournament();
    $match = $matches->first();
    $reporter = mcpOwnerOf($match, 1);

    LanomatServer::actingAs($reporter);
    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id, 'action' => 'report', 'score1' => 2, 'score2' => 1,
    ])->assertOk();

    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id,
        'action' => 'confirm',
        'lock_version' => $match->fresh()->lock_version,
    ])->assertHasErrors(['Forbidden']);
});

it('answers invalid match input with a readable validation error', function () {
    [, $matches] = mcpStartedTournament();
    $match = $matches->first();

    LanomatServer::actingAs(mcpOwnerOf($match, 1));

    LanomatServer::tool(MatchResultTool::class, ['match_id' => $match->id, 'action' => 'report'])
        ->assertHasErrors(['score1']);

    LanomatServer::tool(MatchResultTool::class, ['match_id' => $match->id, 'action' => 'report', 'score1' => 2, 'score2' => 2])
        ->assertHasErrors(['score2']);

    LanomatServer::tool(MatchResultTool::class, ['match_id' => $match->id, 'action' => 'nonsense', 'score1' => 1, 'score2' => 0])
        ->assertHasErrors(['action']);
});

it('404s a foreign match for a non-entrant', function () {
    [, $matches] = mcpStartedTournament();
    $match = $matches->first();

    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(MatchResultTool::class, [
        'match_id' => $match->id, 'action' => 'report', 'score1' => 2, 'score2' => 1,
    ])->assertHasErrors(['Not found']);
});
