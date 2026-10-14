<?php

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

require_once __DIR__.'/ModulesProcess.php';

uses(RefreshDatabase::class);

it('builds gallery and recap scene payloads without throwing when Gallery and Recap are off', function () {
    app()->instance(Modules::class, new Modules('full', ['gallery' => false, 'recap' => false, 'news' => false]));

    $event = Event::factory()->finished()->create();
    EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    EventPhoto::factory()->for($event)->highlight()->create();

    $gallery = ScenePayload::for(InfoscreenScene::factory()->for($event)->create(['type' => SceneType::Gallery]));
    $recap = ScenePayload::for(InfoscreenScene::factory()->for($event)->create(['type' => SceneType::Recap]));

    expect($gallery['data'])->toBe(['photos' => []])
        ->and($recap['data']['topPhotos'])->toBe([])
        ->and($recap['data']['participantCount'])->toBe(0);
});

it('does not register Discord-injecting listeners when Discord is off', function () {
    $off = modulesEvents('full', ['MODULE_DISCORD' => 'false']);
    $on = modulesEvents('full');

    foreach (['AnnounceLfgPost', 'UpdateMatchSurfacesOnServerReady'] as $listener) {
        expect($off)->not->toContain($listener)
            ->and($on)->toContain($listener);
    }
});

/**
 * @param  array<string, string>  $env
 */
function modulesEvents(string $profile, array $env = []): string
{
    $p = new Process([PHP_BINARY, 'artisan', 'event:list', '--json'], base_path(), ['MODULES_PROFILE' => $profile, 'APP_ENV' => 'testing', ...$env]);
    $p->mustRun();

    return $p->getOutput();
}
