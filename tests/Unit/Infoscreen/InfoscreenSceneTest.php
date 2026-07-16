<?php

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;

it('has german labels for each scene type', function () {
    expect(SceneType::Bracket->label())->toBe('Turnierbaum')
        ->and(SceneType::UpcomingMatches->label())->toBe('Nächste Matches')
        ->and(SceneType::Schedule->label())->toBe('Programm')
        ->and(SceneType::Announcement->label())->toBe('Ansage')
        ->and(SceneType::Seatmap->label())->toBe('Sitzplan')
        ->and(SceneType::PaymentQr->label())->toBe('Bezahl-QR')
        ->and(SceneType::Sponsors->label())->toBe('Sponsoren')
        ->and(SceneType::Tombola->label())->toBe('Tombola')
        ->and(SceneType::Status->label())->toBe('Statusanzeige');
});

it('creates an infoscreen scene via the factory with typed type and config', function () {
    $scene = InfoscreenScene::factory()->create();

    expect($scene->event)->toBeInstanceOf(Event::class)
        ->and($scene->fresh()->type)->toBeInstanceOf(SceneType::class)
        ->and($scene->fresh()->type)->toBe(SceneType::Announcement)
        ->and($scene->fresh()->config)->toBeInstanceOf(SceneConfig::class)
        ->and($scene->duration_sec)->toBe(15)
        ->and($scene->enabled)->toBeTrue()
        ->and($scene->enabled)->toBeBool();
});

it('casts the bracket factory state with a tournamentId', function () {
    $scene = InfoscreenScene::factory()->bracket(42)->create();

    expect($scene->fresh()->type)->toBe(SceneType::Bracket)
        ->and($scene->fresh()->config->tournamentId)->toBe(42);
});

it('casts the schedule factory state', function () {
    $scene = InfoscreenScene::factory()->schedule()->create();

    expect($scene->fresh()->type)->toBe(SceneType::Schedule);
});

it('casts the seatmap factory state', function () {
    $scene = InfoscreenScene::factory()->seatmap()->create();

    expect($scene->fresh()->type)->toBe(SceneType::Seatmap);
});

it('casts the sponsors factory state with logo paths', function () {
    $scene = InfoscreenScene::factory()->sponsors(['logos/a.png'])->create();

    expect($scene->fresh()->type)->toBe(SceneType::Sponsors)
        ->and($scene->fresh()->config->sponsorLogoPaths)->toBe(['logos/a.png']);
});

it('casts the disabled factory state', function () {
    $scene = InfoscreenScene::factory()->disabled()->create();

    expect($scene->fresh()->enabled)->toBeFalse();
});

it('casts the sort factory state', function () {
    $scene = InfoscreenScene::factory()->sort(5)->create();

    expect($scene->fresh()->sort)->toBe(5);
});

it('enabledOrdered excludes disabled scenes and sorts by sort then id', function () {
    $event = Event::factory()->create();

    $third = InfoscreenScene::factory()->for($event)->sort(2)->create();
    $first = InfoscreenScene::factory()->for($event)->sort(1)->create();
    InfoscreenScene::factory()->for($event)->sort(1)->disabled()->create();
    $second = InfoscreenScene::factory()->for($event)->sort(1)->create();

    $ordered = InfoscreenScene::enabledOrdered()->get();

    expect($ordered)->toHaveCount(3)
        ->and($ordered->pluck('id')->all())->toBe([$first->id, $second->id, $third->id]);
});

it('has event_id, type, duration_sec fillable but not config, sort, enabled', function () {
    $scene = new InfoscreenScene;

    expect($scene->getFillable())->toBe(['event_id', 'type', 'duration_sec']);
});
