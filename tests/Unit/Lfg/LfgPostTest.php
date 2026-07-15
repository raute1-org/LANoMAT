<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Models\LfgPost;

it('creates a lfg post via the factory', function () {
    $post = LfgPost::factory()->create([
        'game' => 'Valorant',
        'title' => 'Need one more for ranked',
        'body' => 'Looking for a duo, Diamond+.',
        'slots_needed' => 1,
    ]);

    expect($post)
        ->exists->toBeTrue()
        ->game->toBe('Valorant')
        ->title->toBe('Need one more for ranked')
        ->body->toBe('Looking for a duo, Diamond+.')
        ->slots_needed->toBe(1)
        ->expires_at->not->toBeNull();
});

it('excludes expired posts from the active scope', function () {
    $expired = LfgPost::factory()->expired()->create();
    $active = LfgPost::factory()->create([
        'expires_at' => now()->addHours(3),
    ]);

    $result = LfgPost::active()->get();

    expect($result->pluck('id'))
        ->toContain($active->id)
        ->not->toContain($expired->id);
});

it('excludes a post the instant it expires via time travel', function () {
    $post = LfgPost::factory()->create([
        'expires_at' => now()->addHour(),
    ]);

    expect(LfgPost::active()->find($post->id))->not->toBeNull();

    test()->travelTo(now()->addHours(2), function () use ($post) {
        expect(LfgPost::active()->find($post->id))->toBeNull();
    });
});

it('resolves the event and user relationships', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    $post = LfgPost::factory()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    expect($post->event)->toBeInstanceOf(Event::class)
        ->and($post->event->id)->toBe($event->id)
        ->and($post->user)->toBeInstanceOf(User::class)
        ->and($post->user->id)->toBe($user->id);
});
