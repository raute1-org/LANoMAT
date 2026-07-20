<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Friends\Support\FriendSuggestions;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('suggests co-attendees and excludes self, friends, pending, and blocked', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create();
    $coAttendee = User::factory()->create();
    $friend = User::factory()->create();
    $blocked = User::factory()->create();
    foreach ([$me, $coAttendee, $friend, $blocked] as $u) {
        EventRegistration::factory()->for($event)->for($u)->create();
    }
    Friendship::factory()->create(['requester_id' => $me->id, 'addressee_id' => $friend->id, 'status' => FriendshipStatus::Accepted]);
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $blocked->id]);

    $ids = app(FriendSuggestions::class)->for($me)->pluck('user.id');
    expect($ids)->toContain($coAttendee->id)
        ->not->toContain($me->id)->not->toContain($friend->id)->not->toContain($blocked->id);
});

it('excludes users with a pending request in either direction', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create();
    $incomingPending = User::factory()->create();
    $outgoingPending = User::factory()->create();
    foreach ([$me, $incomingPending, $outgoingPending] as $u) {
        EventRegistration::factory()->for($event)->for($u)->create();
    }
    Friendship::factory()->create(['requester_id' => $incomingPending->id, 'addressee_id' => $me->id, 'status' => FriendshipStatus::Pending]);
    Friendship::factory()->create(['requester_id' => $me->id, 'addressee_id' => $outgoingPending->id, 'status' => FriendshipStatus::Pending]);

    $ids = app(FriendSuggestions::class)->for($me)->pluck('user.id');
    expect($ids)->not->toContain($incomingPending->id)->not->toContain($outgoingPending->id);
});

it('excludes users blocked in either direction', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create();
    $blockedByMe = User::factory()->create();
    $blockedMe = User::factory()->create();
    foreach ([$me, $blockedByMe, $blockedMe] as $u) {
        EventRegistration::factory()->for($event)->for($u)->create();
    }
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $blockedByMe->id]);
    UserBlock::factory()->create(['blocker_id' => $blockedMe->id, 'blocked_id' => $me->id]);

    $ids = app(FriendSuggestions::class)->for($me)->pluck('user.id');
    expect($ids)->not->toContain($blockedByMe->id)->not->toContain($blockedMe->id);
});

it('suggests co-members of a team with the shared_team reason', function () {
    $me = User::factory()->create();
    $teammate = User::factory()->create();
    $team = Team::factory()->create();
    TeamMember::factory()->for($team)->for($me)->create();
    TeamMember::factory()->for($team)->for($teammate)->create();

    $suggestion = app(FriendSuggestions::class)->for($me)->firstWhere('user.id', $teammate->id);

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['reasons'])->toContain('shared_team')
        ->and($suggestion['shared'])->toBe(1);
});

it('suggests co-entrants of a solo tournament with the shared_tournament reason', function () {
    $me = User::factory()->create();
    $rival = User::factory()->create();
    $tournament = Tournament::factory()->create();
    TournamentEntry::factory()->solo()->for($tournament)->create(['user_id' => $me->id]);
    TournamentEntry::factory()->solo()->for($tournament)->create(['user_id' => $rival->id]);

    $suggestion = app(FriendSuggestions::class)->for($me)->firstWhere('user.id', $rival->id);

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['reasons'])->toContain('shared_tournament')
        ->and($suggestion['shared'])->toBe(1);
});

it('suggests co-entrants of a team tournament entry via the roster snapshot', function () {
    $me = User::factory()->create();
    $teammate = User::factory()->create();
    $team = Team::factory()->create();
    TeamMember::factory()->for($team)->for($me)->create();
    TeamMember::factory()->for($team)->for($teammate)->create();
    $tournament = Tournament::factory()->create();
    TournamentEntry::factory()->team()->for($tournament)->create([
        'team_id' => $team->id,
        'roster_snapshot' => [
            ['user_id' => $me->id, 'name' => $me->name],
            ['user_id' => $teammate->id, 'name' => $teammate->name],
        ],
    ]);

    $suggestion = app(FriendSuggestions::class)->for($me)->firstWhere('user.id', $teammate->id);

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['reasons'])->toContain('shared_tournament');
});

it('sums shared context counts across multiple shared events and ranks by shared count descending', function () {
    $me = User::factory()->create();
    $eventA = Event::factory()->create();
    $eventB = Event::factory()->create();
    $closeContact = User::factory()->create();
    $distantContact = User::factory()->create();

    foreach ([$eventA, $eventB] as $event) {
        EventRegistration::factory()->for($event)->for($me)->create();
        EventRegistration::factory()->for($event)->for($closeContact)->create();
    }
    EventRegistration::factory()->for($eventA)->for($distantContact)->create();

    $suggestions = app(FriendSuggestions::class)->for($me);
    $closeSuggestion = $suggestions->firstWhere('user.id', $closeContact->id);
    $distantSuggestion = $suggestions->firstWhere('user.id', $distantContact->id);

    expect($closeSuggestion['shared'])->toBe(2)
        ->and($distantSuggestion['shared'])->toBe(1)
        ->and($suggestions->pluck('user.id')->search($closeContact->id))
        ->toBeLessThan($suggestions->pluck('user.id')->search($distantContact->id));
});

it('respects the limit and eager-loads candidate users', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create();
    EventRegistration::factory()->for($event)->for($me)->create();
    foreach (range(1, 5) as $i) {
        EventRegistration::factory()->for($event)->for(User::factory()->create())->create();
    }

    $suggestions = app(FriendSuggestions::class)->for($me, limit: 3);

    expect($suggestions)->toHaveCount(3);
    foreach ($suggestions as $suggestion) {
        expect($suggestion['user'])->toBeInstanceOf(User::class);
    }
});
