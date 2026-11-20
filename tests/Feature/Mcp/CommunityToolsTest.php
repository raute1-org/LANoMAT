<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\CateringMenuTool;
use App\Modules\Mcp\Tools\LfgCreateTool;
use App\Modules\Mcp\Tools\LfgListTool;
use App\Modules\Mcp\Tools\PollsListTool;
use App\Modules\Mcp\Tools\PollVoteTool;
use App\Modules\Mcp\Tools\ScheduleListTool;
use App\Modules\Mcp\Tools\ServersListTool;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists polls with their options and the caller\'s own vote', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $event->id, 'question' => 'Best game?']);

    $chosen = $poll->options()->orderBy('sort')->firstOrFail();
    PollVote::factory()->create([
        'poll_id' => $poll->id,
        'poll_option_id' => $chosen->id,
        'user_id' => $user->id,
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(PollsListTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertStructuredContentPath('polls.0.question', 'Best game?')
        ->assertStructuredContentPath('polls.0.my_vote_option_id', $chosen->id)
        ->assertStructuredContentCount(2, 'polls.0.options');
});

it('casts a vote and reports the chosen option', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $event->id]);
    $option = $poll->options()->orderBy('sort')->get()->last();

    LanomatServer::actingAs($user);

    LanomatServer::tool(PollVoteTool::class, ['poll_id' => $poll->id, 'option_id' => $option?->id])
        ->assertOk()
        ->assertStructuredContentPath('vote.poll_id', $poll->id)
        ->assertStructuredContentPath('vote.option_id', $option?->id);

    expect(PollVote::query()->where('poll_id', $poll->id)->where('user_id', $user->id)->exists())->toBeTrue();
});

it('rejects a vote on a closed poll with a readable error', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $poll = Poll::factory()->closed()->withOptions(2)->create(['event_id' => $event->id]);
    $option = $poll->options()->firstOrFail();

    LanomatServer::actingAs($user);

    LanomatServer::tool(PollVoteTool::class, ['poll_id' => $poll->id, 'option_id' => $option->id])
        ->assertHasErrors(['not currently open']);
});

it('404s a vote on a poll of a draft event', function () {
    $user = User::factory()->create();
    $event = Event::factory()->draft()->create();
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $event->id]);
    $option = $poll->options()->firstOrFail();

    LanomatServer::actingAs($user);

    LanomatServer::tool(PollVoteTool::class, ['poll_id' => $poll->id, 'option_id' => $option->id])
        ->assertHasErrors(['Not found']);
});

it('lists only active LFG posts of an event', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    LfgPost::factory()->create(['event_id' => $event->id, 'title' => 'Need a fifth', 'game' => 'CS2']);
    LfgPost::factory()->expired()->create(['event_id' => $event->id, 'title' => 'Old post']);

    LanomatServer::actingAs($user);

    $response = LanomatServer::tool(LfgListTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertSee('Need a fifth')
        ->assertDontSee('Old post');

    $response->assertStructuredContentCount(1, 'posts');
});

it('creates an LFG post for the caller', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();

    LanomatServer::actingAs($user);

    LanomatServer::tool(LfgCreateTool::class, [
        'event_slug' => $event->slug,
        'title' => 'Suche Team für Valorant',
        'game' => 'Valorant',
        'body' => 'Wir sind vier, brauchen einen fünften.',
        'slots_needed' => 1,
    ])
        ->assertOk()
        ->assertStructuredContentPath('post.title', 'Suche Team für Valorant')
        ->assertStructuredContentPath('post.game', 'Valorant');

    $post = LfgPost::query()->where('event_id', $event->id)->firstOrFail();

    expect($post->user_id)->toBe($user->id)
        ->and($post->expires_at->isFuture())->toBeTrue();
});

it('lists the catering menu with prices in cents', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    FoodOrder::factory()->open()->create(['event_id' => $event->id, 'title' => 'Pizza am Samstag']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(CateringMenuTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertStructuredContentPath('orders.0.title', 'Pizza am Samstag')
        ->assertStructuredContentPath('orders.0.is_open_now', true)
        ->assertStructuredContentPath('orders.0.menu.0.price_cents', fn ($value): bool => is_int($value));
});

it('lists the schedule of an event in order', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->create(['event_id' => $event->id, 'title' => 'Second', 'starts_at' => now()->addHours(2)]);
    ScheduleItem::factory()->create(['event_id' => $event->id, 'title' => 'First', 'starts_at' => now()->addHour()]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(ScheduleListTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertStructuredContentPath('items.0.title', 'First')
        ->assertStructuredContentCount(2, 'items');
});

it('lists permanent servers by default and event servers on request', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    ServerLink::factory()->permanent()->create();
    ServerLink::factory()->create(['event_id' => $event->id, 'title' => 'Event only']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(ServersListTool::class)
        ->assertOk()
        ->assertStructuredContentPath('servers.0.permanent', true)
        ->assertStructuredContentCount(1, 'servers');

    LanomatServer::tool(ServersListTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertStructuredContentPath('servers.0.title', 'Event only');
});
