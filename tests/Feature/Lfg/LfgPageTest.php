<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the lfg page with german labels and only active posts for a public event', function () {
    $event = Event::factory()->registration()->create();
    $activePost = LfgPost::factory()->for($event)->create(['title' => 'Suche Mitspieler für Ranked']);
    LfgPost::factory()->for($event)->expired()->create(['title' => 'Abgelaufene Anzeige']);

    $this->get("/events/{$event->slug}/lfg")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Lfg/Index')
            ->where('labels.title', 'Mitspieler finden')
            ->has('posts', 1)
            ->where('posts.0.id', $activePost->id)
            ->where('posts.0.title', 'Suche Mitspieler für Ranked')
        );
});

it('returns 404 for a draft event', function () {
    $event = Event::factory()->draft()->create();
    LfgPost::factory()->for($event)->create();

    $this->get("/events/{$event->slug}/lfg")->assertNotFound();
});

it('marks a post as mine when the viewer is its author', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $mine = LfgPost::factory()->for($event)->for($user)->create();
    $other = LfgPost::factory()->for($event)->create();

    $this->actingAs($user)
        ->get("/events/{$event->slug}/lfg")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Lfg/Index')
            ->where('posts.0.mine', $mine->user_id === $user->id)
            ->where('posts.1.mine', $other->user_id === $user->id)
        );
});

it('creates a post for the authenticated user and redirects back', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from("/events/{$event->slug}/lfg")
        ->post("/events/{$event->slug}/lfg", [
            'title' => 'Suche 2 Leute für Duo Queue',
            'game' => 'Valorant',
            'body' => 'Rang Gold+',
            'slots_needed' => 2,
        ])
        ->assertRedirect("/events/{$event->slug}/lfg");

    $post = LfgPost::where('event_id', $event->id)->where('user_id', $user->id)->first();

    expect($post)->not->toBeNull();
    expect($post->title)->toBe('Suche 2 Leute für Duo Queue');
    expect($post->game)->toBe('Valorant');
    expect($post->slots_needed)->toBe(2);
});

it('never trusts a client-supplied user id when creating a post', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/lfg", [
            'title' => 'Suche Mitspieler',
            'user_id' => $otherUser->id,
        ])
        ->assertRedirect();

    $post = LfgPost::where('event_id', $event->id)->first();

    expect($post->user_id)->toBe($user->id);
});

it('rejects a duration_hours above the sane upper bound', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/lfg", [
            'title' => 'Suche Mitspieler',
            'duration_hours' => 200,
        ])
        ->assertSessionHasErrors('duration_hours');

    expect(LfgPost::where('event_id', $event->id)->exists())->toBeFalse();
});

it('lets the owner delete their own post', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    $post = LfgPost::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->delete("/lfg/{$post->id}")
        ->assertRedirect();

    expect(LfgPost::find($post->id))->toBeNull();
});

it('forbids a non-owner from deleting a post', function () {
    $event = Event::factory()->registration()->create();
    $owner = User::factory()->create();
    $post = LfgPost::factory()->for($event)->for($owner)->create();

    $this->actingAs(User::factory()->create())
        ->delete("/lfg/{$post->id}")
        ->assertForbidden();

    expect(LfgPost::find($post->id))->not->toBeNull();
});
