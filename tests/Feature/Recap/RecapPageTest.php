<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('404s the recap page for a live (not yet wrapped) event', function () {
    $event = Event::factory()->live()->create();

    $this->get("/events/{$event->slug}/recap")->assertNotFound();
});

it('404s the recap page for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->get("/events/{$event->slug}/recap")->assertNotFound();
});

it('renders the recap page for a finished event with the projection props', function () {
    $event = Event::factory()->finished()->create();

    EventRegistration::factory()->for($event)->count(2)->create();

    $winnerEntry = TournamentEntry::factory()->create(['display_name' => 'Team Rocket']);
    $tournament = Tournament::factory()->for($event)->create([
        'name' => 'Quake Cup',
        'status' => TournamentStatus::Finished,
        'winner_entry_id' => $winnerEntry->id,
    ]);
    $winnerEntry->update(['tournament_id' => $tournament->id]);

    $photo = EventPhoto::factory()->for($event)->highlight()->create(['caption' => 'Finale']);

    $this->get("/events/{$event->slug}/recap")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Recap/Show')
            ->where('event.name', $event->name)
            ->where('event.slug', $event->slug)
            ->where('recap.participantCount', 2)
            ->where('recap.tournamentCount', 1)
            ->where('recap.podiums.0.tournamentName', 'Quake Cup')
            ->where('recap.podiums.0.winnerName', 'Team Rocket')
            ->where('recap.topPhotos.0.caption', 'Finale')
            ->where('recap.topPhotos.0.url', route('gallery.photos.public.thumb', $photo))
            ->has('labels')
        );
});

it('renders the recap page for an archived event', function () {
    $event = Event::factory()->archived()->create();

    $this->get("/events/{$event->slug}/recap")->assertOk();
});

it('is reachable without a logged-in user', function () {
    $event = Event::factory()->finished()->create();

    expect(auth()->check())->toBeFalse();

    $this->get("/events/{$event->slug}/recap")->assertOk();
});
