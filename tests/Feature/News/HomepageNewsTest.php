<?php

use App\Modules\Events\Models\Event;
use App\Modules\News\Models\NewsPost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows only published news posts on the homepage', function () {
    Event::factory()->live()->create();

    $published = NewsPost::factory()->create([
        'title' => 'Sichtbare Neuigkeit',
        'published_at' => now()->subHour(),
    ]);
    $draft = NewsPost::factory()->draft()->create([
        'title' => 'Entwurf-Neuigkeit',
    ]);
    $future = NewsPost::factory()->scheduledInFuture()->create([
        'title' => 'Zukuenftige-Neuigkeit',
    ]);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Event/Show')
        ->has('news', 1)
        ->where('news.0.id', $published->id)
        ->where('news.0.title', $published->title)
    );

    expect($draft->title)->not->toBe($published->title)
        ->and($future->title)->not->toBe($published->title);
});

it('shows published news on a specific event show page too', function () {
    $event = Event::factory()->announced()->create();
    NewsPost::factory()->create(['title' => 'Event-Neuigkeit', 'published_at' => now()->subHour()]);

    $response = $this->get('/events/'.$event->slug);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Event/Show')
        ->has('news', 1)
    );
});

it('renders an empty news array when there are no published posts', function () {
    Event::factory()->live()->create();
    NewsPost::factory()->draft()->create();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Event/Show')
        ->has('news', 0)
    );
});
