<?php

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the public screen page with german idle label and no auth required', function () {
    $event = Event::factory()->live()->create();

    $this->get("/screen/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Screen/Show')
            ->where('labels.idle', 'Bereit')
            ->where('event.id', $event->id)
            ->where('event.slug', $event->slug)
        );
});

it('lists enabled scenes in sort order and omits disabled scenes', function () {
    $event = Event::factory()->live()->create();

    $second = InfoscreenScene::factory()->for($event)->announcement()->sort(2)->create();
    $first = InfoscreenScene::factory()->for($event)->announcement()->sort(1)->create();
    InfoscreenScene::factory()->for($event)->announcement()->disabled()->sort(0)->create();

    $this->get("/screen/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Screen/Show')
            ->has('scenes', 2)
            ->where('scenes.0.id', $first->id)
            ->where('scenes.1.id', $second->id)
        );
});

it('404s the screen page for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/screen/{$event->slug}")
        ->assertNotFound();
});

it('is reachable without a logged-in user', function () {
    $event = Event::factory()->live()->create();

    expect(auth()->check())->toBeFalse();

    $this->get("/screen/{$event->slug}")->assertOk();
});
