<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Presence\Support\PresenceProjection;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;

it('lists only checked-in non-cancelled participants and counts them', function () {
    $event = Event::factory()->create();

    $ada = User::factory()->create(['name' => 'Ada']);
    EventRegistration::factory()->for($event)->for($ada, 'user')->checkedIn()->create();

    $pending = User::factory()->create(['name' => 'Pending Pete']);
    EventRegistration::factory()->for($event)->for($pending, 'user')->pending()->create();

    $cancelledUser = User::factory()->create(['name' => 'Cancelled Carl']);
    EventRegistration::factory()->for($event)->for($cancelledUser, 'user')->cancelled()->checkedIn()->create();

    $notCheckedIn = User::factory()->create(['name' => 'Not Checked Nina']);
    EventRegistration::factory()->for($event)->for($notCheckedIn, 'user')->create();

    $board = PresenceProjection::forEvent($event);

    expect($board->checkedInCount)->toBe(1)
        ->and($board->participants)->toHaveCount(1)
        ->and($board->participants[0]->name)->toBe('Ada');
});

it('resolves seatLabel for a seated participant and null for an unseated one', function () {
    $event = Event::factory()->create();

    $seated = User::factory()->create(['name' => 'Ada']);
    $seatedRegistration = EventRegistration::factory()->for($event)->for($seated, 'user')->checkedIn()->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'A1']);
    SeatAssignment::factory()->for($seat)->for($seatedRegistration, 'registration')->create();

    $unseated = User::factory()->create(['name' => 'Bob']);
    EventRegistration::factory()->for($event)->for($unseated, 'user')->checkedIn()->create();

    $board = PresenceProjection::forEvent($event);

    $byName = collect($board->participants)->keyBy('name');

    expect($byName['Ada']->seatLabel)->toBe('A1')
        ->and($byName['Ada']->registrationId)->toBe($seatedRegistration->id)
        ->and($byName['Bob']->seatLabel)->toBeNull();
});

it('flags a roster member of a live warmup/ready match as playing with activity, others as idle', function () {
    $event = Event::factory()->create();
    $game = Game::factory()->create(['name' => 'Quake']);

    $player1 = User::factory()->create(['name' => 'Ada']);
    EventRegistration::factory()->for($event)->for($player1, 'user')->checkedIn()->create();
    $entry1 = TournamentEntry::factory()->create(['user_id' => $player1->id, 'team_id' => null, 'display_name' => 'Ada']);

    $player2 = User::factory()->create(['name' => 'Bob']);
    EventRegistration::factory()->for($event)->for($player2, 'user')->checkedIn()->create();
    $entry2 = TournamentEntry::factory()->create(['user_id' => $player2->id, 'team_id' => null, 'display_name' => 'Bob']);

    $tournament = Tournament::factory()->for($event)->for($game)->live()->create();
    $entry1->update(['tournament_id' => $tournament->id]);
    $entry2->update(['tournament_id' => $tournament->id]);

    $match = GameMatch::factory()->for($tournament)->create([
        'status' => MatchStatus::Warmup,
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
    ]);

    $idleUser = User::factory()->create(['name' => 'Carl']);
    EventRegistration::factory()->for($event)->for($idleUser, 'user')->checkedIn()->create();

    $board = PresenceProjection::forEvent($event);
    $byName = collect($board->participants)->keyBy('name');

    expect($byName['Ada']->isPlaying)->toBeTrue()
        ->and($byName['Ada']->activity)->toBe('Quake · Ada vs Bob')
        ->and($byName['Bob']->isPlaying)->toBeTrue()
        ->and($byName['Bob']->activity)->toBe('Quake · Ada vs Bob')
        ->and($byName['Carl']->isPlaying)->toBeFalse()
        ->and($byName['Carl']->activity)->toBeNull();

    expect($board->liveMatches)->toHaveCount(1);
    $liveMatch = $board->liveMatches[0];
    expect($liveMatch->matchId)->toBe($match->id)
        ->and($liveMatch->game)->toBe('Quake')
        ->and($liveMatch->label)->toBe('Ada vs Bob')
        ->and($liveMatch->players)->toEqualCanonicalizing(['Ada', 'Bob']);
});

it('does not mark a participant as playing for a Ready match of a non-live tournament', function () {
    $event = Event::factory()->create();

    $player = User::factory()->create(['name' => 'Ada']);
    EventRegistration::factory()->for($event)->for($player, 'user')->checkedIn()->create();
    $entry = TournamentEntry::factory()->create(['user_id' => $player->id, 'team_id' => null]);

    $tournament = Tournament::factory()->for($event)->enrollment()->create();
    $entry->update(['tournament_id' => $tournament->id]);

    GameMatch::factory()->for($tournament)->create([
        'status' => MatchStatus::Ready,
        'entry1_id' => $entry->id,
    ]);

    $board = PresenceProjection::forEvent($event);
    $byName = collect($board->participants)->keyBy('name');

    expect($byName['Ada']->isPlaying)->toBeFalse()
        ->and($byName['Ada']->activity)->toBeNull()
        ->and($board->liveMatches)->toHaveCount(0);
});

it('computes freeSlots openSpots and excludes non-open or full tournaments', function () {
    $event = Event::factory()->create();
    $game = Game::factory()->create(['name' => 'Quake']);

    $open = Tournament::factory()->for($event)->for($game)->enrollment()->create(['max_entries' => 8, 'name' => 'Open Cup']);
    TournamentEntry::factory()->count(3)->for($open, 'tournament')->create();

    $checkInOpen = Tournament::factory()->for($event)->checkIn()->create(['max_entries' => null, 'name' => 'Unlimited Cup']);

    $draft = Tournament::factory()->for($event)->create(['status' => TournamentStatus::Draft, 'max_entries' => 8, 'name' => 'Draft Cup']);

    $live = Tournament::factory()->for($event)->live()->create(['max_entries' => 8, 'name' => 'Live Cup']);

    $finished = Tournament::factory()->for($event)->create(['status' => TournamentStatus::Finished, 'max_entries' => 8, 'name' => 'Finished Cup']);

    $full = Tournament::factory()->for($event)->enrollment()->create(['max_entries' => 2, 'name' => 'Full Cup']);
    TournamentEntry::factory()->count(2)->for($full, 'tournament')->create();

    $board = PresenceProjection::forEvent($event);
    $byName = collect($board->freeSlots)->keyBy('name');

    expect($board->freeSlots)->toHaveCount(2)
        ->and($byName['Open Cup']->openSpots)->toBe(5)
        ->and($byName['Open Cup']->game)->toBe('Quake')
        ->and($byName['Open Cup']->tournamentId)->toBe($open->id)
        ->and($byName['Unlimited Cup']->openSpots)->toBeNull()
        ->and($byName)->not->toHaveKey('Draft Cup')
        ->and($byName)->not->toHaveKey('Live Cup')
        ->and($byName)->not->toHaveKey('Finished Cup')
        ->and($byName)->not->toHaveKey('Full Cup');
});

it('orders participants deterministically by name', function () {
    $event = Event::factory()->create();

    foreach (['Zoe', 'Ada', 'Mia'] as $name) {
        $user = User::factory()->create(['name' => $name]);
        EventRegistration::factory()->for($event)->for($user, 'user')->checkedIn()->create();
    }

    $board = PresenceProjection::forEvent($event);

    expect(collect($board->participants)->pluck('name')->all())->toBe(['Ada', 'Mia', 'Zoe']);
});

it('produces toArray() with camelCase keys for every DTO', function () {
    $event = Event::factory()->create();

    $user = User::factory()->create(['name' => 'Ada']);
    $registration = EventRegistration::factory()->for($event)->for($user, 'user')->checkedIn()->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'A1']);
    SeatAssignment::factory()->for($seat)->for($registration, 'registration')->create();

    $tournament = Tournament::factory()->for($event)->enrollment()->create(['max_entries' => 8, 'name' => 'Open Cup']);
    TournamentEntry::factory()->count(3)->for($tournament, 'tournament')->create();

    $board = PresenceProjection::forEvent($event);
    $array = $board->toArray();

    expect($array)->toHaveKeys(['participants', 'freeSlots', 'liveMatches', 'checkedInCount'])
        ->and($array['participants'][0])->toHaveKeys(['registrationId', 'name', 'avatarUrl', 'seatLabel', 'activity', 'isPlaying'])
        ->and($array['participants'][0]['registrationId'])->toBe($registration->id)
        ->and($array['freeSlots'][0])->toHaveKeys(['tournamentId', 'name', 'game', 'openSpots']);
});
