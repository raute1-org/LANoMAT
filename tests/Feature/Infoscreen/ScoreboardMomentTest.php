<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Events\MatchScoreUpdated;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\CreateInfoscreenScene;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('broadcasts a scoreboard SceneOverride on the event channel for a live score update', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create(['name' => 'CS2 Clash']);
    $match = GameMatch::factory()->for($tournament)->create();

    event(new MatchScoreUpdated($match, 'Team Alpha', 'Team Beta', 5, 3, 8));

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($event) {
        return $dispatched->eventId === $event->id
            && $dispatched->scene['type'] === 'scoreboard'
            && $dispatched->scene['data']['team1'] === 'Team Alpha'
            && $dispatched->scene['data']['team2'] === 'Team Beta'
            && $dispatched->scene['data']['score1'] === 5
            && $dispatched->scene['data']['score2'] === 3
            && $dispatched->scene['data']['round'] === 8;
    });

    EventFacade::assertDispatchedTimes(SceneOverride::class, 1);
});

it('excludes the synthetic scoreboard/gong/winner scene types from the actual Filament rotation form', function () {
    $this->actingAs(User::factory()->orga()->create());

    $options = Livewire::test(CreateInfoscreenScene::class)
        ->instance()
        ->getSchema('form')
        ->getComponent('type')
        ->getOptions();

    expect($options)->not->toHaveKey('scoreboard')
        ->and($options)->not->toHaveKey('winner')
        ->and($options)->not->toHaveKey('gong')
        ->and($options)->toHaveKey('servers');
});
