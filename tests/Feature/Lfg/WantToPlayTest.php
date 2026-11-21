<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Games\Models\Game;
use App\Modules\Lfg\Actions\ToggleWantToPlay;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Lfg\Policies\LfgPostPolicy;
use App\Modules\Lfg\Support\WantToPlayBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

class DenyingLfgPostPolicy extends LfgPostPolicy
{
    public function create(User $user): bool
    {
        return false;
    }
}

function signal(Event $event, User $user, string $game, array $state = []): LfgPost
{
    return LfgPost::factory()->for($event)->for($user)->create(array_merge([
        'game' => $game,
        'title' => ToggleWantToPlay::titleFor($game),
        'body' => null,
        'slots_needed' => null,
    ], $state));
}

function board(Event $event, User $viewer): array
{
    return app(WantToPlayBoard::class)->for($event, $viewer);
}

it('groups signals by game case-insensitively with the latest spelling', function () {
    $event = Event::factory()->registration()->create();
    $viewer = User::factory()->create();
    signal($event, User::factory()->create(), 'cs2', ['created_at' => now()->subMinutes(5)]);
    signal($event, User::factory()->create(), 'CS2', ['created_at' => now()]);
    signal($event, User::factory()->create(), 'Quake');

    $games = board($event, $viewer)['games'];

    expect($games)->toHaveCount(2)
        ->and($games[0]['game'])->toBe('CS2')
        ->and($games[0]['count'])->toBe(2)
        ->and($games[1]['game'])->toBe('Quake');
});

it('ignores expired signals, normal posts and posts without a game', function () {
    $event = Event::factory()->registration()->create();
    signal($event, User::factory()->create(), 'CS2', ['expires_at' => now()->subMinute()]);
    LfgPost::factory()->for($event)->create(['game' => 'Valorant', 'title' => 'Need 2', 'body' => null, 'slots_needed' => null]);
    signal($event, User::factory()->create(), 'X', ['game' => null]);

    expect(board($event, User::factory()->create())['games'])->toBe([]);
});

it('excludes the viewer and blocked users from names and flags mine', function () {
    $event = Event::factory()->registration()->create();
    $viewer = User::factory()->create();
    $blockedByMe = User::factory()->create(['name' => 'Blocked']);
    $blocksMe = User::factory()->create(['name' => 'Blocker']);
    $ok = User::factory()->create(['name' => 'Okay']);
    UserBlock::query()->create(['blocker_id' => $viewer->id, 'blocked_id' => $blockedByMe->id]);
    UserBlock::query()->create(['blocker_id' => $blocksMe->id, 'blocked_id' => $viewer->id]);
    signal($event, $viewer, 'CS2');
    signal($event, $blockedByMe, 'CS2');
    signal($event, $blocksMe, 'CS2');
    signal($event, $ok, 'CS2');

    $row = board($event, $viewer)['games'][0];

    expect($row['names'])->toBe(['Okay'])->and($row['mine'])->toBeTrue()->and($row['count'])->toBe(2);
    expect(board($event, $ok)['games'][0]['mine'])->toBeTrue();
    expect(board($event, User::factory()->create())['games'][0]['mine'])->toBeFalse();
});

it('limits names to three and sorts by count then game', function () {
    $event = Event::factory()->registration()->create();
    foreach (range(1, 4) as $i) {
        signal($event, User::factory()->create(), 'Zed');
    }
    signal($event, User::factory()->create(), 'Alpha');

    $games = board($event, User::factory()->create())['games'];

    expect($games[0]['game'])->toBe('Zed')->and($games[0]['names'])->toHaveCount(3)->and($games[1]['game'])->toBe('Alpha');
});

it('suggests library games not already signalled, alphabetical, max 8', function () {
    $event = Event::factory()->registration()->create();
    foreach (range(1, 10) as $i) {
        Game::factory()->create(['name' => 'Game '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    }
    signal($event, User::factory()->create(), 'game 01');

    $result = board($event, User::factory()->create());

    expect($result['suggestions'])->toHaveCount(8)
        ->and($result['suggestions'][0])->toBe('Game 02')
        ->and($result['suggestions'])->not->toContain('Game 01')
        ->and($result['hours'])->toBe(3);
});

it('toggles a signal on and off', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post("/events/{$event->slug}/want-to-play", ['game' => '  CS2 '])->assertRedirect();
    $post = LfgPost::sole();
    expect($post->title)->toBe('Bock auf CS2')->and($post->user_id)->toBe($user->id)->and($post->body)->toBeNull()
        ->and($post->slots_needed)->toBeNull()->and($post->expires_at->isBetween(now()->addHours(3)->subMinute(), now()->addHours(3)->addMinute()))->toBeTrue();

    $this->actingAs($user)->post("/events/{$event->slug}/want-to-play", ['game' => 'cs2'])->assertRedirect();
    expect(LfgPost::count())->toBe(0);
});

it('does not remove someone else\'s signal', function () {
    $event = Event::factory()->registration()->create();
    $other = User::factory()->create();
    signal($event, $other, 'CS2');

    $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/want-to-play", ['game' => 'CS2'])->assertRedirect();

    expect(LfgPost::where('user_id', $other->id)->count())->toBe(1)->and(LfgPost::count())->toBe(2);
});

it('validates the game', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post("/events/{$event->slug}/want-to-play", ['game' => str_repeat('a', 81)])->assertSessionHasErrors('game');
    $this->actingAs($user)->post("/events/{$event->slug}/want-to-play", [])->assertSessionHasErrors('game');
});

it('redirects guests to login', function () {
    $event = Event::factory()->registration()->create();

    $this->post("/events/{$event->slug}/want-to-play", ['game' => 'CS2'])->assertRedirect('/login');
});

it('forbids users the policy does not allow to post', function () {
    $event = Event::factory()->registration()->create();
    Gate::policy(LfgPost::class, DenyingLfgPostPolicy::class);

    $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/want-to-play", ['game' => 'CS2'])->assertForbidden();
    expect(LfgPost::count())->toBe(0);
});

it('returns 404 for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())->post("/events/{$event->slug}/want-to-play", ['game' => 'CS2'])->assertNotFound();
});
