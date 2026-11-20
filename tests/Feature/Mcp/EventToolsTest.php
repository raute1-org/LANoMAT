<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\EventGetTool;
use App\Modules\Mcp\Tools\EventRegisterTool;
use App\Modules\Mcp\Tools\EventsListTool;
use App\Modules\Mcp\Tools\GamesSearchTool;
use App\Modules\Mcp\Tools\TeamsListTool;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists visible events only and flags the caller\'s own registration', function () {
    $user = User::factory()->create();
    $visible = Event::factory()->registration()->create(['name' => 'Visible LAN']);
    Event::factory()->draft()->create(['name' => 'Hidden LAN']);
    EventRegistration::factory()->create(['event_id' => $visible->id, 'user_id' => $user->id, 'ticket_type' => 'participant']);

    LanomatServer::actingAs($user);

    $response = LanomatServer::tool(EventsListTool::class)
        ->assertOk()
        ->assertSee('Visible LAN')
        ->assertDontSee('Hidden LAN');

    $response->assertStructuredContentPath('events.0.my_registration.ticket_type', 'participant');
});

it('drops finished events when include_finished is false', function () {
    $user = User::factory()->create();
    Event::factory()->finished()->create(['name' => 'Old LAN']);
    Event::factory()->registration()->create(['name' => 'Next LAN']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(EventsListTool::class, ['include_finished' => false])
        ->assertSee('Next LAN')
        ->assertDontSee('Old LAN');
});

it('hides draft events from event_get', function () {
    $user = User::factory()->create();
    $draft = Event::factory()->draft()->create(['name' => 'Secret Draft LAN']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(EventGetTool::class, ['slug' => $draft->slug])
        ->assertHasErrors(['Not found']);
});

it('returns one event with tournaments, schedule and my registration', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);
    Tournament::factory()->announced()->create(['event_id' => $event->id, 'name' => 'Visible Cup']);
    Tournament::factory()->create(['event_id' => $event->id, 'name' => 'Hidden Cup']);
    ScheduleItem::factory()->create(['event_id' => $event->id, 'title' => 'Opening']);

    LanomatServer::actingAs($user);

    $response = LanomatServer::tool(EventGetTool::class, ['slug' => $event->slug])
        ->assertOk()
        ->assertSee('Visible Cup')
        ->assertSee('Opening');

    // Draft tournaments stay orga-only even inside an event the user may see.
    $response->assertDontSee('Hidden Cup');
    $response->assertStructuredContentPath('event.slug', $event->slug);
    $response->assertStructuredContentCount(1, 'tournaments');
    // The announced tournament syncs its own schedule item, so the manual
    // "Opening" item plus the tournament item are both present.
    $response->assertStructuredContentCount(2, 'schedule');
    $response->assertStructuredContentPath('schedule.0.title', 'Opening');
});

it('leaves the schedule empty when the schedule module is off', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    ScheduleItem::factory()->create(['event_id' => $event->id, 'title' => 'Should not appear']);

    app()->instance(Modules::class, new Modules('core'));

    LanomatServer::actingAs($user);

    LanomatServer::tool(EventGetTool::class, ['slug' => $event->slug])
        ->assertOk()
        ->assertStructuredContentCount(0, 'schedule');
});

it('registers with the event\'s own default ticket when none is given', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create([
        'settings' => ['tickets' => ['participant', 'helper']],
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(EventRegisterTool::class, ['slug' => $event->slug])
        ->assertOk()
        ->assertStructuredContentPath('registration.ticket_type', 'participant')
        ->assertStructuredContentPath('registration.status', 'confirmed');

    expect(EventRegistration::query()->where('event_id', $event->id)->where('user_id', $user->id)->first()?->ticket_type)
        ->toBe('participant');
});

it('registers with an explicitly chosen ticket type', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create([
        'settings' => ['tickets' => ['participant', 'helper']],
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(EventRegisterTool::class, ['slug' => $event->slug, 'ticket_type' => 'helper'])
        ->assertOk()
        ->assertStructuredContentPath('registration.ticket_type', 'helper');
});

it('refuses registration outside the registration window with a readable error', function () {
    $user = User::factory()->create();
    $event = Event::factory()->announced()->create();

    LanomatServer::actingAs($user);

    LanomatServer::tool(EventRegisterTool::class, ['slug' => $event->slug])
        ->assertHasErrors(['Forbidden']);
});

it('reports a full event as a readable domain error', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create(['max_participants' => 1]);
    EventRegistration::factory()->create(['event_id' => $event->id]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(EventRegisterTool::class, ['slug' => $event->slug])
        ->assertHasErrors(['participant limit']);
});

it('lists teams with member counts and the caller\'s membership', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Die Zocker']);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
    Team::factory()->create(['name' => 'Andere']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(TeamsListTool::class, ['query' => 'Zocker'])
        ->assertOk()
        ->assertStructuredContentPath('teams.0.name', 'Die Zocker')
        ->assertStructuredContentPath('teams.0.members_count', 1)
        ->assertStructuredContentPath('teams.0.my_membership', 'member');
});

it('searches the games catalogue by name or slug fragment', function () {
    $user = User::factory()->create();
    Game::factory()->create(['name' => 'Unreal Tournament 2004', 'slug' => 'unreal-tournament-2004']);
    Game::factory()->create(['name' => 'Counter-Strike 2', 'slug' => 'counter-strike-2']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(GamesSearchTool::class, ['query' => 'unreal'])
        ->assertOk()
        ->assertStructuredContentCount(1, 'games')
        ->assertStructuredContentPath('games.0.slug', 'unreal-tournament-2004');
});
