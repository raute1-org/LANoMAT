<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a recap scene payload matching the RecapProjection board with no PII', function () {
    $event = Event::factory()->finished()->create();
    $scene = InfoscreenScene::factory()->for($event)->create(['type' => SceneType::Recap]);

    $winnerEntry = TournamentEntry::factory()->create(['display_name' => 'Team Rocket']);
    $tournament = Tournament::factory()->for($event)->create([
        'name' => 'Quake Cup',
        'status' => TournamentStatus::Finished,
        'winner_entry_id' => $winnerEntry->id,
    ]);
    $winnerEntry->update(['tournament_id' => $tournament->id]);

    $photo = EventPhoto::factory()->for($event)->highlight()->create(['caption' => 'Finale']);

    $payload = ScenePayload::for($scene);

    expect($payload['data'])
        ->toHaveKeys([
            'participantCount',
            'tournamentCount',
            'matchesPlayed',
            'songsPlayed',
            'podiums',
            'topPhotos',
            'mvp',
        ])
        ->and($payload['data']['podiums'][0])
        ->toHaveKey('tournamentName')
        ->toHaveKey('winnerName')
        ->not->toHaveKey('tournamentId')
        ->not->toHaveKey('entryId')
        ->and($payload['data']['topPhotos'][0])
        ->toHaveKey('url')
        ->toHaveKey('caption')
        ->not->toHaveKey('uploaderName')
        ->not->toHaveKey('uploaded_by')
        ->not->toHaveKey('id')
        ->and($payload['data']['topPhotos'][0]['url'])->toBe(route('gallery.photos.public.thumb', $photo));
});

it('returns the projection\'s own empty board when the scene has no event', function () {
    $scene = InfoscreenScene::factory()->create(['type' => SceneType::Recap]);
    $scene->event()->dissociate();

    $payload = ScenePayload::for($scene);

    expect($payload['data'])->toBe([
        'participantCount' => 0,
        'tournamentCount' => 0,
        'matchesPlayed' => 0,
        'songsPlayed' => null,
        'podiums' => [],
        'topPhotos' => [],
        'mvp' => null,
    ]);
});
