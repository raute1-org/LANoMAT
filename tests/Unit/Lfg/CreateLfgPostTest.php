<?php

use App\Models\User;
use App\Modules\Events\Models\Event as EventModel;
use App\Modules\Lfg\Actions\CreateLfgPost;
use App\Modules\Lfg\Events\LfgPostCreated;
use App\Modules\Lfg\Exceptions\LfgException;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function createLfgPost(EventModel $event, User $user, array $attributes = []): LfgPost
{
    return app(CreateLfgPost::class)->handle($event, $user, $attributes);
}

it('creates a post with a computed expires_at default', function () {
    $event = EventModel::factory()->registration()->create();
    $user = User::factory()->create();

    $post = createLfgPost($event, $user, [
        'title' => 'Need one more for ranked',
        'game' => 'Valorant',
    ]);

    expect($post)->toBeInstanceOf(LfgPost::class)
        ->and($post->exists)->toBeTrue()
        ->and($post->title)->toBe('Need one more for ranked')
        ->and($post->expires_at)->not->toBeNull()
        ->and($post->expires_at->diffInMinutes(now()->addHours(3), false))->toBeGreaterThan(-1)
        ->and($post->expires_at->diffInMinutes(now()->addHours(3), false))->toBeLessThan(1);
});

it('computes expires_at from a validated duration_hours attribute', function () {
    $event = EventModel::factory()->registration()->create();
    $user = User::factory()->create();

    $post = createLfgPost($event, $user, [
        'title' => 'Need a duo',
        'duration_hours' => 1,
    ]);

    expect($post->expires_at->diffInMinutes(now()->addHour(), false))->toBeGreaterThan(-1)
        ->and($post->expires_at->diffInMinutes(now()->addHour(), false))->toBeLessThan(1);
});

it('sets user_id from the passed user, never from client input', function () {
    $event = EventModel::factory()->registration()->create();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $post = createLfgPost($event, $user, [
        'title' => 'Need one more',
        'user_id' => $otherUser->id,
    ]);

    expect($post->user_id)->toBe($user->id)
        ->and($post->user_id)->not->toBe($otherUser->id);
});

it('sets event_id from the passed event', function () {
    $event = EventModel::factory()->registration()->create();
    $user = User::factory()->create();

    $post = createLfgPost($event, $user, ['title' => 'Need one more']);

    expect($post->event_id)->toBe($event->id);
});

it('dispatches LfgPostCreated with the created post', function () {
    $event = EventModel::factory()->registration()->create();
    $user = User::factory()->create();

    Event::fake([LfgPostCreated::class]);

    $post = createLfgPost($event, $user, ['title' => 'Need one more']);

    Event::assertDispatched(LfgPostCreated::class, fn (LfgPostCreated $dispatched) => $dispatched->post->is($post));
});

it('rejects creating a post when the event is not publicly visible', function () {
    $event = EventModel::factory()->draft()->create();
    $user = User::factory()->create();

    expect(fn () => createLfgPost($event, $user, ['title' => 'Need one more']))
        ->toThrow(LfgException::class);
});

it('rejects an empty or whitespace-only title and creates no row', function (string $title) {
    $event = EventModel::factory()->registration()->create();
    $user = User::factory()->create();

    expect(fn () => createLfgPost($event, $user, ['title' => $title]))
        ->toThrow(fn (LfgException $e) => expect($e->translationKey)->toBe('lfg.errors.invalid_title'));

    expect(LfgPost::count())->toBe(0);
})->with(['empty string' => [''], 'whitespace only' => ['   ']]);

it('rejects a title longer than 120 characters and creates no row', function () {
    $event = EventModel::factory()->registration()->create();
    $user = User::factory()->create();

    expect(fn () => createLfgPost($event, $user, ['title' => str_repeat('a', 121)]))
        ->toThrow(fn (LfgException $e) => expect($e->translationKey)->toBe('lfg.errors.invalid_title'));

    expect(LfgPost::count())->toBe(0);
});
