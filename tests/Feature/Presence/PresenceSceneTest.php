<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fills data with the beamer-tailored presence subset for a presence scene', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'Quake']);

    $ada = User::factory()->create(['name' => 'Ada']);
    EventRegistration::factory()->for($event)->for($ada, 'user')->checkedIn()->create();
    $entry1 = TournamentEntry::factory()->create(['user_id' => $ada->id, 'team_id' => null, 'display_name' => 'Ada']);

    $bob = User::factory()->create(['name' => 'Bob']);
    EventRegistration::factory()->for($event)->for($bob, 'user')->checkedIn()->create();
    $entry2 = TournamentEntry::factory()->create(['user_id' => $bob->id, 'team_id' => null, 'display_name' => 'Bob']);

    $liveTournament = Tournament::factory()->for($event)->for($game)->live()->create();
    $entry1->update(['tournament_id' => $liveTournament->id]);
    $entry2->update(['tournament_id' => $liveTournament->id]);

    GameMatch::factory()->for($liveTournament)->create([
        'status' => MatchStatus::Warmup,
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
    ]);

    $openTournament = Tournament::factory()->for($event)->for($game)->enrollment()->create(['max_entries' => 8, 'name' => 'Open Cup']);
    TournamentEntry::factory()->count(3)->for($openTournament, 'tournament')->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Presence,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['type'])->toBe('presence');
    expect($payload['data'])->toHaveKeys(['checkedInCount', 'liveMatches', 'freeSlots']);
    expect($payload['data'])->not->toHaveKey('participants');

    expect($payload['data']['checkedInCount'])->toBe(2);

    expect($payload['data']['liveMatches'])->toHaveCount(1);
    expect($payload['data']['liveMatches'][0])->toMatchArray([
        'game' => 'Quake',
        'label' => 'Ada vs Bob',
    ]);
    expect($payload['data']['liveMatches'][0]['players'])->toEqualCanonicalizing(['Ada', 'Bob']);

    expect($payload['data']['freeSlots'])->toHaveCount(1);
    expect($payload['data']['freeSlots'][0])->toMatchArray([
        'name' => 'Open Cup',
        'game' => 'Quake',
        'openSpots' => 5,
    ]);
});

it('returns zeroed/empty presence data for an event with no activity yet', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Presence,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['checkedInCount'])->toBe(0);
    expect($payload['data']['liveMatches'])->toBe([]);
    expect($payload['data']['freeSlots'])->toBe([]);
});
