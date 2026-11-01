<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/PlayHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
    $this->event = playEvent();
    $this->me = User::factory()->create();
    $this->them = User::factory()->create();
    $this->stranger = User::factory()->create();
    playRegister($this->me, $this->event);
    playRegister($this->them, $this->event);
    $this->headers = playBearer($this->me);
    $this->base = '/api/voice-client/v1';
});

it('requires a valid bearer token on every tournament route', function () {
    $slug = $this->event->slug;
    $garbage = ['Authorization' => 'Bearer garbage'];

    foreach (["/events/{$slug}/tournaments", "/events/{$slug}/my-matches"] as $path) {
        $this->getJson($this->base.$path)->assertUnauthorized();
        $this->getJson($this->base.$path, $garbage)->assertUnauthorized();
    }

    foreach (['/tournaments/1/checkin', '/matches/1/report', '/matches/1/confirm', '/matches/1/dispute', '/matches/1/report/withdraw'] as $path) {
        $this->postJson($this->base.$path)->assertUnauthorized();
        $this->postJson($this->base.$path, [], $garbage)->assertUnauthorized();
    }

    $token = playBearer($this->me);
    $this->getJson($this->base."/events/{$slug}/tournaments", $token)->assertOk();
    // A revoked token is a 401, like on every other route.
    VoiceClientToken::query()->update(['revoked_at' => now()]);
    $this->getJson($this->base."/events/{$slug}/tournaments", $token)->assertUnauthorized();
});

it('does not accept the bearer token on the web tournament pages', function () {
    $this->get("/events/{$this->event->slug}/tournaments", $this->headers)->assertRedirect('/login');
    $this->postJson('/matches/1/report', [], $this->headers)->assertUnauthorized();
});

/* ------------------------------------------------------------ tournaments */

it('lists the event\'s visible tournaments with my enrollment state', function () {
    $open = Tournament::factory()->enrollment()->create(['event_id' => $this->event->id, 'name' => 'Offen']);
    $live = Tournament::factory()->live()->create(['event_id' => $this->event->id, 'name' => 'Läuft']);
    Tournament::factory()->create(['event_id' => $this->event->id, 'name' => 'Entwurf']);
    Tournament::factory()->live()->create(['name' => 'Fremdes Event']);
    playEntry($live, $this->me);

    $response = $this->getJson($this->base."/events/{$this->event->slug}/tournaments", $this->headers)->assertOk();
    $byName = collect($response->json('tournaments'))->keyBy('name');

    expect($byName->keys()->all())->toBe(['Läuft', 'Offen'])
        ->and($byName['Läuft']['myEntry'])->toMatchArray(['status' => 'registered', 'isOwner' => true])
        ->and($byName['Offen']['myEntry'])->toBeNull()
        ->and($byName['Offen']['can'])->toBe(['checkIn' => false, 'withdraw' => false])
        ->and($open->id)->toBe($byName['Offen']['id']);

    $orga = User::factory()->create(['role' => Role::Orga]);
    $names = collect($this->getJson($this->base."/events/{$this->event->slug}/tournaments", playBearer($orga))->json('tournaments'))->pluck('name')->all();
    expect($names)->toContain('Entwurf');
});

it('tells a user who is not registered for the event so', function () {
    Tournament::factory()->enrollment()->create(['event_id' => $this->event->id]);

    $response = $this->getJson($this->base."/events/{$this->event->slug}/tournaments", playBearer($this->stranger))->assertOk();

    expect($response->json('tournaments.0.registration.required'))->toBeTrue()
        ->and($response->json('tournaments.0.myEntry'))->toBeNull();
});

it('hides draft events from the tournament and match lists', function () {
    $draft = Event::factory()->draft()->create();

    $this->getJson($this->base."/events/{$draft->slug}/tournaments", $this->headers)->assertNotFound();
    $this->getJson($this->base."/events/{$draft->slug}/my-matches", $this->headers)->assertNotFound();
});

/* ------------------------------------------------------------- my matches */

it('lists my matches with opponent, voice room, server and what I can do', function () {
    $match = playMatch($this->event, $this->me, $this->them);
    $room = WebVoiceChannel::factory()->match()->create(['game_match_id' => $match->id, 'tournament_entry_id' => $match->entry1_id]);
    $room->members()->attach($this->me);
    // The opponent's room of the same match is not mine.
    WebVoiceChannel::factory()->match()->create(['game_match_id' => $match->id, 'tournament_entry_id' => $match->entry2_id]);
    $link = ServerLink::factory()->create(['match_id' => $match->id, 'title' => 'Matchserver', 'status' => ServerLinkStatus::Ready, 'join_info' => new JoinInfo(address: '203.0.113.5', port: 27015)]);
    $match->forceFill(['server_link_id' => $link->id])->save();

    $row = $this->getJson($this->base."/events/{$this->event->slug}/my-matches", $this->headers)->assertOk()->json('matches.0');

    expect($row)->toMatchArray([
        'id' => $match->id,
        'phase' => 'active',
        'status' => 'ready',
        'round' => 1,
        'voiceRoomId' => $room->id,
        'lockVersion' => 0,
        'score' => null,
        'pendingReport' => null,
    ])->and($row['tournament']['name'])->toBe('Duell Cup')
        ->and($row['me']['name'])->toBe('Meine Crew')
        ->and($row['opponent']['name'])->toBe('Die Anderen')
        ->and($row['server']['joinUrl'])->toBe('steam://connect/203.0.113.5:27015')
        ->and($row['can'])->toBe(['report' => true, 'confirm' => false, 'dispute' => false, 'withdrawReport' => false, 'checkIn' => false]);
});

it('does not list matches of other players, nor matches of a withdrawn entry', function () {
    $match = playMatch($this->event, $this->them, $this->stranger);

    $this->getJson($this->base."/events/{$this->event->slug}/my-matches", $this->headers)
        ->assertOk()->assertJsonCount(0, 'matches');

    TournamentEntry::query()->whereKey($match->entry1_id)->update(['status' => EntryStatus::Withdrawn->value]);
    $this->getJson($this->base."/events/{$this->event->slug}/my-matches", playBearer($this->them))
        ->assertOk()->assertJsonCount(0, 'matches');
});

it('shows no voice room when the room policy does not let me in', function () {
    $match = playMatch($this->event, $this->me, $this->them);
    // A room exists for my entry but my roster snapshot does not contain me.
    WebVoiceChannel::factory()->match()->create(['game_match_id' => $match->id, 'tournament_entry_id' => $match->entry1_id]);

    $this->getJson($this->base."/events/{$this->event->slug}/my-matches", $this->headers)
        ->assertOk()->assertJsonPath('matches.0.voiceRoomId', null);
});

it('groups matches into active, upcoming and recently finished, finished ones expire', function () {
    $tournament = Tournament::factory()->live()->create(['event_id' => $this->event->id]);
    $live = playMatch($this->event, $this->me, $this->them, MatchStatus::Live, $tournament);
    $pending = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'round' => 2, 'entry1_id' => $live->entry1_id]);
    $fresh = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'round' => 3, 'position' => 1, 'entry1_id' => $live->entry1_id, 'entry2_id' => $live->entry2_id]);
    $fresh->forceFill(['status' => MatchStatus::Completed, 'score1' => 2, 'score2' => 1, 'winner_entry_id' => $live->entry1_id])->save();
    $old = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'round' => 3, 'position' => 2, 'entry1_id' => $live->entry1_id, 'entry2_id' => $live->entry2_id]);
    $old->forceFill(['status' => MatchStatus::Completed, 'score1' => 2, 'score2' => 1, 'winner_entry_id' => $live->entry1_id])->save();
    GameMatch::query()->whereKey($old->id)->update(['updated_at' => now()->subHours(5)]);

    $matches = collect($this->getJson($this->base."/events/{$this->event->slug}/my-matches", $this->headers)->assertOk()->json('matches'));

    expect($matches->pluck('id')->all())->toBe([$live->id, $pending->id, $fresh->id])
        ->and($matches->pluck('phase')->all())->toBe(['active', 'upcoming', 'finished'])
        ->and($matches[1]['opponent'])->toBeNull()
        ->and($matches[2]['score'])->toBe(['own' => 2, 'opponent' => 1])
        ->and($matches[2]['won'])->toBeTrue();
});

it('shows a plain team member the match but lets only the owner act', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $team->members()->create(['user_id' => $owner->id]);
    $team->members()->create(['user_id' => $member->id]);

    $tournament = Tournament::factory()->live()->create(['event_id' => $this->event->id, 'team_size' => 2]);
    $teamEntry = TournamentEntry::factory()->create([
        'tournament_id' => $tournament->id, 'team_id' => $team->id, 'user_id' => null, 'display_name' => 'Team Rot',
        'roster_snapshot' => [['user_id' => $owner->id], ['user_id' => $member->id]],
    ]);
    $theirs = playEntry($tournament, $this->them, 'Gegner');
    $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $teamEntry->id, 'entry2_id' => $theirs->id]);
    $match->forceFill(['status' => MatchStatus::Ready])->save();

    $memberRow = $this->getJson($this->base."/events/{$this->event->slug}/my-matches", playBearer($member))->assertOk()->json('matches.0');
    $ownerRow = $this->getJson($this->base."/events/{$this->event->slug}/my-matches", playBearer($owner))->assertOk()->json('matches.0');

    expect($memberRow['id'])->toBe($match->id)
        ->and($memberRow['can'])->toBe(['report' => false, 'confirm' => false, 'dispute' => false, 'withdrawReport' => false, 'checkIn' => false])
        ->and($ownerRow['can']['report'])->toBeTrue();

    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 1, 'score2' => 0], playBearer($member))->assertForbidden();
});

/* ---------------------------------------------------------------- check-in */

it('checks me in inside the window and rejects outside it', function () {
    $tournament = Tournament::factory()->checkIn()->create([
        'event_id' => $this->event->id,
        'checkin_opens_at' => now()->subMinutes(5),
        'checkin_closes_at' => now()->addMinutes(20),
    ]);
    $entry = playEntry($tournament, $this->me);

    $listed = $this->getJson($this->base."/events/{$this->event->slug}/tournaments", $this->headers)->json('tournaments.0');
    expect($listed['can']['checkIn'])->toBeTrue();

    $response = $this->postJson($this->base."/tournaments/{$tournament->id}/checkin", [], $this->headers)->assertOk();

    expect($response->json('tournament.myEntry.status'))->toBe('checked_in')
        ->and($response->json('tournament.can.checkIn'))->toBeFalse()
        ->and($entry->refresh()->status)->toBe(EntryStatus::CheckedIn);

    $closed = Tournament::factory()->checkIn()->create(['event_id' => $this->event->id, 'checkin_opens_at' => now()->subHour(), 'checkin_closes_at' => now()->subMinute()]);
    playEntry($closed, $this->me);
    $this->postJson($this->base."/tournaments/{$closed->id}/checkin", [], $this->headers)
        ->assertStatus(422)->assertJsonPath('error', 'tournaments.errors.checkin_closed');
});

it('refuses check-in without an entry, without registration, and for hidden tournaments', function () {
    $tournament = Tournament::factory()->checkIn()->create(['event_id' => $this->event->id]);

    // No entry of mine.
    $this->postJson($this->base."/tournaments/{$tournament->id}/checkin", [], $this->headers)->assertNotFound();

    // An entry, but no event registration.
    playEntry($tournament, $this->stranger);
    $this->postJson($this->base."/tournaments/{$tournament->id}/checkin", [], playBearer($this->stranger))
        ->assertStatus(422)->assertJsonPath('error', 'tournaments.errors.registration_required');

    // A draft tournament does not exist for a participant.
    $draft = Tournament::factory()->create(['event_id' => $this->event->id]);
    playEntry($draft, $this->me);
    $this->postJson($this->base."/tournaments/{$draft->id}/checkin", [], $this->headers)->assertNotFound();

    // A draft event neither.
    $draftEvent = Event::factory()->draft()->create();
    $hidden = Tournament::factory()->checkIn()->create(['event_id' => $draftEvent->id]);
    playEntry($hidden, $this->me);
    $this->postJson($this->base."/tournaments/{$hidden->id}/checkin", [], $this->headers)->assertNotFound();
});

/* ------------------------------------------------------------ result flow */

it('runs report, confirm and the dispute and withdraw variants like the web flow', function () {
    $match = playMatch($this->event, $this->me, $this->them);

    // Report: own : opponent, same validation as the web (no ties, integers).
    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 1, 'score2' => 1], $this->headers)
        ->assertStatus(422)->assertJsonValidationErrors('score2');
    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 'x'], $this->headers)->assertStatus(422);

    $reported = $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 2, 'score2' => 1], $this->headers)->assertOk();
    expect($reported->json('match.status'))->toBe('reported')
        ->and($reported->json('match.pendingReport'))->toBe(['reportedByMe' => true, 'ownScore' => 2, 'opponentScore' => 1])
        ->and($reported->json('match.can.withdrawReport'))->toBeTrue()
        ->and($reported->json('match.can.confirm'))->toBeFalse();

    // A second report of the same match is refused by the Action.
    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 0, 'score2' => 5], playBearer($this->them))
        ->assertStatus(422)->assertJsonPath('error', 'tournaments.errors.match_already_reported');

    // The reporter cannot confirm their own claim.
    $this->postJson($this->base."/matches/{$match->id}/confirm", ['lock_version' => 0], $this->headers)->assertForbidden();

    // The opponent sees the claim from their side and confirms it.
    $theirs = $this->getJson($this->base."/events/{$this->event->slug}/my-matches", playBearer($this->them))->json('matches.0');
    expect($theirs['pendingReport'])->toBe(['reportedByMe' => false, 'ownScore' => 1, 'opponentScore' => 2])
        ->and($theirs['can']['confirm'])->toBeTrue()
        ->and($theirs['can']['dispute'])->toBeTrue()
        ->and($theirs['can']['report'])->toBeFalse();

    // lock_version is required and optimistic: a stale one is a 409, nothing changes.
    $this->postJson($this->base."/matches/{$match->id}/confirm", [], playBearer($this->them))->assertStatus(422)->assertJsonValidationErrors('lock_version');
    $this->postJson($this->base."/matches/{$match->id}/confirm", ['lock_version' => 7], playBearer($this->them))
        ->assertStatus(409)->assertJsonPath('error', 'tournaments.errors.stale_match');
    expect($match->refresh()->status)->toBe(MatchStatus::Reported);

    $confirmed = $this->postJson($this->base."/matches/{$match->id}/confirm", ['lock_version' => $theirs['lockVersion']], playBearer($this->them))->assertOk();
    expect($confirmed->json('match.status'))->toBe('completed')
        ->and($confirmed->json('match.won'))->toBeFalse()
        ->and($confirmed->json('match.lockVersion'))->toBe(1)
        ->and($match->refresh()->status)->toBe(MatchStatus::Completed);

    // A stale second click is told so, not a server error.
    $this->postJson($this->base."/matches/{$match->id}/confirm", ['lock_version' => 1], playBearer($this->them))
        ->assertStatus(409)->assertJsonPath('error', 'tournaments.errors.report_not_pending');
});

it('lets the reporter withdraw a pending claim and the opponent dispute it', function () {
    $match = playMatch($this->event, $this->me, $this->them);
    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 3, 'score2' => 0], $this->headers)->assertOk();

    // Only the opponent may dispute or withdraw as... the reporter withdraws, the opponent cannot.
    $this->postJson($this->base."/matches/{$match->id}/report/withdraw", ['lock_version' => 0], playBearer($this->them))->assertForbidden();
    $this->postJson($this->base."/matches/{$match->id}/dispute", [], $this->headers)->assertForbidden();

    $withdrawn = $this->postJson($this->base."/matches/{$match->id}/report/withdraw", ['lock_version' => 0], $this->headers)->assertOk();
    expect($withdrawn->json('match.status'))->toBe('ready')
        ->and($withdrawn->json('match.lockVersion'))->toBe(1)
        ->and(MatchReport::query()->where('match_id', $match->id)->first()->status)->toBe(ReportStatus::Withdrawn);

    // The claim is gone: confirming it now says so (409), like the web flash.
    $this->postJson($this->base."/matches/{$match->id}/confirm", ['lock_version' => 1], playBearer($this->them))
        ->assertStatus(409)->assertJsonPath('error', 'tournaments.errors.report_withdrawn');

    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 0, 'score2' => 3], $this->headers)->assertOk();
    $disputed = $this->postJson($this->base."/matches/{$match->id}/dispute", [], playBearer($this->them))->assertOk();
    expect($disputed->json('match.status'))->toBe('disputed');
});

it('answers 404 for result actions of people who are not in the match', function () {
    $match = playMatch($this->event, $this->me, $this->them);
    $stranger = playBearer($this->stranger);

    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 1, 'score2' => 0], $stranger)->assertForbidden();
    $this->postJson($this->base."/matches/{$match->id}/dispute", [], $stranger)->assertNotFound();
    $this->postJson($this->base."/matches/{$match->id}/confirm", ['lock_version' => 0], $stranger)->assertNotFound();
    $this->postJson($this->base."/matches/{$match->id}/report/withdraw", ['lock_version' => 0], $stranger)->assertNotFound();
    $this->postJson($this->base.'/matches/999999/report', ['score1' => 1, 'score2' => 0], $stranger)->assertNotFound();
    expect($match->refresh()->status)->toBe(MatchStatus::Ready);
});

it('refuses to report a match that is not in play', function () {
    $match = playMatch($this->event, $this->me, $this->them, MatchStatus::Pending);

    $this->postJson($this->base."/matches/{$match->id}/report", ['score1' => 1, 'score2' => 0], $this->headers)
        ->assertStatus(422)->assertJsonPath('error', 'tournaments.errors.match_not_ready');
});

it('answers in JSON even without an Accept header', function () {
    $match = playMatch($this->event, $this->me, $this->them);

    $this->post($this->base."/matches/{$match->id}/report", ['score1' => 1, 'score2' => 1], $this->headers)
        ->assertStatus(422)->assertHeader('Content-Type', 'application/json')->assertJsonValidationErrors('score2');
    $this->post($this->base.'/matches/999999/confirm', [], $this->headers)->assertNotFound()->assertHeader('Content-Type', 'application/json');
});
