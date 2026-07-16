<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Enums\StatusLevel;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Models\TombolaPrize;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

/**
 * Starts an 8-entry single-elimination tournament (7 matches total) and
 * returns it, freshly reloaded.
 */
function startEightEntrySingleElimForScene(Event $event): Tournament
{
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);

    return app(StartTournament::class)->handle($tournament)->fresh();
}

it('fills data.matches with all of the tournament matches for a bracket scene, ordered by round/position', function () {
    $event = Event::factory()->live()->create();
    $tournament = startEightEntrySingleElimForScene($event);
    $scene = InfoscreenScene::factory()->for($event)->bracket($tournament->id)->create();

    $payload = ScenePayload::for($scene);

    expect($payload['data'])->toHaveKey('matches');
    expect($payload['data']['matches'])->toHaveCount(7);

    $matchIds = GameMatch::query()
        ->where('tournament_id', $tournament->id)
        ->orderBy('round')
        ->orderBy('position')
        ->pluck('id')
        ->all();

    expect(array_column($payload['data']['matches'], 'id'))->toBe($matchIds);

    // Shape sanity: same DTO keys the tournament page bracket relies on.
    expect($payload['data']['matches'][0])->toHaveKeys([
        'id', 'round', 'bracket', 'position', 'nextMatchId', 'nextSlot',
        'slot1', 'slot2', 'entry1Id', 'entry2Id', 'score1', 'score2',
        'winnerEntryId', 'status', 'lockVersion',
    ]);
});

it('fills data.matches with only Ready matches for an upcoming-matches scene', function () {
    $event = Event::factory()->live()->create();
    $tournament = startEightEntrySingleElimForScene($event);

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::UpcomingMatches,
        'config' => new SceneConfig(tournamentId: $tournament->id),
    ]);

    $payload = ScenePayload::for($scene);

    $readyCount = GameMatch::query()
        ->where('tournament_id', $tournament->id)
        ->where('status', 'ready')
        ->count();

    expect($readyCount)->toBeGreaterThan(0);
    expect($payload['data']['matches'])->toHaveCount($readyCount);

    foreach ($payload['data']['matches'] as $match) {
        expect($match['status'])->toBe('ready');
    }
});

it('fills data.now/data.next reflecting time-travel for a schedule scene', function () {
    $event = Event::factory()->live()->create();

    $past = ScheduleItem::factory()->for($event)->create([
        'title' => 'Opening',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(30),
    ]);
    $current = ScheduleItem::factory()->for($event)->create([
        'title' => 'Main Event',
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addMinutes(20),
    ]);
    $future = ScheduleItem::factory()->for($event)->create([
        'title' => 'Awards',
        'starts_at' => now()->addHour(),
    ]);

    $scene = InfoscreenScene::factory()->for($event)->schedule()->create();

    $payload = ScenePayload::for($scene);

    expect($payload['data']['items'])->toHaveCount(3);
    expect($payload['data']['now']['title'])->toBe('Main Event');
    expect($payload['data']['next']['title'])->toBe('Awards');

    // Time-travel: once "now" moves past the current item's end, now/next
    // shift accordingly.
    $this->travelTo($current->ends_at->clone()->addMinute());

    $payloadLater = ScenePayload::for($scene->fresh());

    expect($payloadLater['data']['now'])->toBeNull();
    expect($payloadLater['data']['next']['title'])->toBe('Awards');

    unset($past, $future);
});

it('fills data.seats with id/label/x/y/occupant for a seatmap scene', function () {
    $event = Event::factory()->live()->create();

    $registration = EventRegistration::factory()->for($event)->create([
        'user_id' => User::factory()->create(['name' => 'Ada']),
    ]);

    $occupiedSeat = Seat::factory()->for($event)->create(['label' => 'A1', 'pos_x' => 0, 'pos_y' => 0]);
    SeatAssignment::factory()->create([
        'seat_id' => $occupiedSeat->id,
        'registration_id' => $registration->id,
    ]);
    $freeSeat = Seat::factory()->for($event)->create(['label' => 'A2', 'pos_x' => 1, 'pos_y' => 0]);

    $scene = InfoscreenScene::factory()->for($event)->seatmap()->create();

    $payload = ScenePayload::for($scene);

    expect($payload['data']['seats'])->toHaveCount(2);

    $occupied = collect($payload['data']['seats'])->firstWhere('id', $occupiedSeat->id);
    $free = collect($payload['data']['seats'])->firstWhere('id', $freeSeat->id);

    expect($occupied)->toMatchArray(['id' => $occupiedSeat->id, 'label' => 'A1', 'x' => 0, 'y' => 0, 'occupant' => 'Ada']);
    expect($free)->toMatchArray(['id' => $freeSeat->id, 'label' => 'A2', 'x' => 1, 'y' => 0, 'occupant' => null]);
});

it('fills data.prizes and data.lastDraw for a tombola scene', function () {
    $event = Event::factory()->live()->create();

    $prizeA = TombolaPrize::factory()->for($event)->sort(0)->create(['title' => 'Maus']);
    $prizeB = TombolaPrize::factory()->for($event)->sort(1)->create(['title' => 'Tastatur']);

    // Only one eligible registration so the DB-random draw is deterministic
    // (DrawTombola's randomness itself is covered by DrawTombolaTest).
    $winnerA = EventRegistration::factory()->for($event)->checkedIn()->create([
        'user_id' => User::factory()->create(['name' => 'Ada']),
    ]);

    app(DrawTombola::class)->handle($event, $prizeA);

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Tombola,
        'config' => new SceneConfig,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['prizes'])->toHaveCount(2);

    $prizeAData = collect($payload['data']['prizes'])->firstWhere('id', $prizeA->id);
    $prizeBData = collect($payload['data']['prizes'])->firstWhere('id', $prizeB->id);

    expect($prizeAData['winner'])->toBe('Ada')
        ->and($prizeBData['winner'])->toBeNull()
        ->and($payload['data']['lastDraw']['prize']['title'])->toBe('Maus')
        ->and($payload['data']['lastDraw']['winner']['name'])->toBe('Ada')
        ->and($payload['data']['lastDraw']['winner']['registrationId'])->toBe($winnerA->id);
});

it('returns an empty tombola board when no prizes exist yet', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Tombola,
        'config' => new SceneConfig,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['prizes'])->toBe([])
        ->and($payload['data']['lastDraw'])->toBeNull();
});

it('fills data.signals with the current level per component for a status scene', function () {
    $event = Event::factory()->live()->create();
    $helper = User::factory()->helper()->create();

    app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Degraded, 'Langsam.', $helper);
    app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Down, 'Jetzt ganz aus.', $helper);
    app(SetStatusSignal::class)->handle($event, 'servers', StatusLevel::Ok, null, $helper);

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Status,
        'config' => new SceneConfig,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['signals'])->toHaveCount(2);

    $internet = collect($payload['data']['signals'])->firstWhere('component', 'internet');
    $servers = collect($payload['data']['signals'])->firstWhere('component', 'servers');

    expect($internet)->toMatchArray(['component' => 'internet', 'level' => 'down', 'message' => 'Jetzt ganz aus.'])
        ->and($servers)->toMatchArray(['component' => 'servers', 'level' => 'ok', 'message' => null]);
});

it('returns an empty signals list for a status scene with no reports yet', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Status,
        'config' => new SceneConfig,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['signals'])->toBe([]);
});

it('eager-loads the bracket projection without N+1 queries per match', function () {
    $event = Event::factory()->live()->create();
    $tournament = startEightEntrySingleElimForScene($event);
    $scene = InfoscreenScene::factory()->for($event)->bracket($tournament->id)->create();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    ScenePayload::for($scene);

    // A handful of fixed queries (matches + eager-loaded entries), not one
    // per match (7 matches would blow this past ~10 if N+1'd).
    expect($queryCount)->toBeLessThan(10);
});
