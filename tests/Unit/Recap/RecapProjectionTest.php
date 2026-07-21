<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Recap\Support\RecapProjection;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;

it('returns an empty board for an event with no activity', function () {
    $board = RecapProjection::forEvent(Event::factory()->create())->toArray();

    expect($board)
        ->participantCount->toBe(0)
        ->tournamentCount->toBe(0)
        ->matchesPlayed->toBe(0)
        ->and($board['podiums'])->toBe([])
        ->and($board['topPhotos'])->toBe([])
        ->and($board['mvp'])->toBeNull();
});

it('counts participants, tournaments, matches played, and songs played', function () {
    $event = Event::factory()->create();

    EventRegistration::factory()->for($event)->count(3)->create();

    $tournament = Tournament::factory()->for($event)->create();
    Tournament::factory()->for($event)->create();

    GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Completed]);
    GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Completed]);
    GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Pending]);

    JukeboxItem::factory()->for($event)->create(['status' => QueueItemStatus::Played]);
    JukeboxItem::factory()->for($event)->create(['status' => QueueItemStatus::Played]);
    JukeboxItem::factory()->for($event)->create(['status' => QueueItemStatus::Queued]);

    $board = RecapProjection::forEvent($event);

    expect($board->participantCount)->toBe(3)
        ->and($board->tournamentCount)->toBe(2)
        ->and($board->matchesPlayed)->toBe(2)
        ->and($board->songsPlayed)->toBe(2);
});

it('lists finished tournaments winners as podiums, skipping tournaments without a winner', function () {
    $event = Event::factory()->create();

    $winnerEntry = TournamentEntry::factory()->create(['display_name' => 'Team Rocket']);
    $finished = Tournament::factory()->for($event)->create([
        'name' => 'Quake Cup',
        'status' => TournamentStatus::Finished,
        'winner_entry_id' => $winnerEntry->id,
    ]);
    $winnerEntry->update(['tournament_id' => $finished->id]);

    // Finished but somehow no winner recorded — must be skipped, not crash.
    Tournament::factory()->for($event)->create([
        'name' => 'No Winner Cup',
        'status' => TournamentStatus::Finished,
        'winner_entry_id' => null,
    ]);

    // Still live — must not appear in podiums.
    Tournament::factory()->for($event)->create([
        'name' => 'Still Live Cup',
        'status' => TournamentStatus::Live,
    ]);

    $board = RecapProjection::forEvent($event)->toArray();

    expect($board['podiums'])->toHaveCount(1)
        ->and($board['podiums'][0]['tournamentName'])->toBe('Quake Cup')
        ->and($board['podiums'][0]['winnerName'])->toBe('Team Rocket');
});

it('prefers highlighted photos, excluding pending and rejected ones', function () {
    $event = Event::factory()->create();

    EventPhoto::factory()->for($event)->create(); // pending → excluded
    EventPhoto::factory()->for($event)->rejected()->create(); // rejected → excluded
    $highlight = EventPhoto::factory()->for($event)->highlight()->create(['caption' => 'Star']);

    $board = RecapProjection::forEvent($event)->toArray();

    expect($board['topPhotos'])->toHaveCount(1)
        ->and($board['topPhotos'][0]['caption'])->toBe('Star')
        ->and($board['topPhotos'][0]['url'])->toBe(route('gallery.photos.public.thumb', $highlight));
});

it('caps top photos at six, preferring highlights then most recent approved', function () {
    $event = Event::factory()->create();

    EventPhoto::factory()->for($event)->approved()->count(8)->create();

    $board = RecapProjection::forEvent($event)->toArray();

    expect($board['topPhotos'])->toHaveCount(6);
});

it('returns null mvp until the MVP poll feature exists', function () {
    $board = RecapProjection::forEvent(Event::factory()->create())->toArray();

    expect($board['mvp'])->toBeNull();
});

it('camelCases toArray keys', function () {
    $board = RecapProjection::forEvent(Event::factory()->create())->toArray();

    expect(array_keys($board))->toBe([
        'participantCount',
        'tournamentCount',
        'matchesPlayed',
        'songsPlayed',
        'podiums',
        'topPhotos',
        'mvp',
    ]);
});

it('bounds the gallery query to a single query regardless of photo count', function () {
    $event = Event::factory()->create();
    EventPhoto::factory()->for($event)->approved()->count(10)->create();

    DB::enableQueryLog();
    RecapProjection::forEvent($event);
    $photoQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], '"event_photos"'));
    DB::disableQueryLog();

    expect($photoQueries)->toHaveCount(1);
});
