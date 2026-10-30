<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('redirects guests to login for event pages', function (string $path) {
    $event = Event::factory()->registration()->create(['slug' => 'secret-lan']);

    $this->get(str_replace('{slug}', $event->slug, $path))->assertRedirect(route('login'));
})->with([
    '/events',
    '/events/{slug}',
    '/events/{slug}/seating',
    '/events/{slug}/tournaments',
    '/events/{slug}/schedule',
    '/events/{slug}/schedule.ics',
    '/events/{slug}/catering',
    '/events/{slug}/polls',
    '/events/{slug}/lfg',
    '/events/{slug}/servers',
    '/events/{slug}/files',
    '/events/{slug}/presence',
    '/events/{slug}/recap',
    '/events/{slug}/jukebox',
    '/events/{slug}/gallery',
    '/stats/leaderboard',
]);

it('redirects guests to login for a tournament page', function () {
    $tournament = Tournament::factory()->create();

    $this->get("/tournaments/{$tournament->id}")->assertRedirect(route('login'));
});

it('lets authenticated users open event pages', function (string $path) {
    $event = Event::factory()->registration()->create(['slug' => 'open-lan']);

    $this->actingAs(User::factory()->create())
        ->get(str_replace('{slug}', $event->slug, $path))
        ->assertOk();
})->with([
    '/events',
    '/events/{slug}',
    '/events/{slug}/seating',
    '/events/{slug}/tournaments',
    '/events/{slug}/schedule',
]);

it('shows guests a landing without any event data', function () {
    Event::factory()->registration()->create(['name' => 'Zebrakuchen LAN 2099']);

    $response = $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('currentEvent', null)
            ->missing('event')
        );

    expect($response->getContent())->not->toContain('Zebrakuchen');
});

it('shows authenticated users the current event on home', function () {
    Event::factory()->registration()->create(['name' => 'Zebrakuchen LAN 2099']);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Show')
            ->where('event.name', 'Zebrakuchen LAN 2099')
        );
});
