<?php

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Notifications\TeamJoinRequestAnswered;
use App\Modules\Teams\Notifications\TeamJoinRequestReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('rejects a team name that already exists, ignoring case', function () {
    Team::factory()->create(['name' => 'Alpha Squad']);

    $this->actingAs(User::factory()->create())
        ->post(route('teams.store'), ['name' => 'alpha squad', 'tag' => 'AS'])
        ->assertSessionHasErrors(['name' => 'Dieser Teamname ist bereits vergeben.']);

    expect(Team::query()->count())->toBe(1);
});

it('allows keeping the own name on update but not taking another team name', function () {
    $owner = User::factory()->create();
    $mine = Team::factory()->create(['name' => 'Mine', 'owner_id' => $owner->id]);
    Team::factory()->create(['name' => 'Other']);

    $this->actingAs($owner)
        ->patch(route('teams.update', $mine), ['name' => 'Mine', 'tag' => 'MN'])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->patch(route('teams.update', $mine), ['name' => 'OTHER', 'tag' => 'MN'])
        ->assertSessionHasErrors('name');
});

it('enforces uniqueness in the database on lower(name)', function () {
    Team::factory()->create(['name' => 'Alpha']);

    expect(fn () => DB::table('teams')->insert([
        'name' => 'ALPHA', 'tag' => 'X', 'owner_id' => User::factory()->create()->id,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('notifies the owner of a join request and the requester of the answer', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $requester = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $team->members()->make(['user_id' => $owner->id])->forceFill(['role' => 'owner'])->save();

    $this->actingAs($requester)->post(route('teams.join', $team))->assertRedirect();

    Notification::assertSentTo($owner, TeamJoinRequestReceived::class);

    $joinRequest = $team->joinRequests()->firstOrFail();

    $this->actingAs($owner)
        ->post(route('teams.respond', [$team, $joinRequest]), ['accept' => true])
        ->assertRedirect();

    Notification::assertSentTo($requester, TeamJoinRequestAnswered::class, fn ($n) => $n->accepted);
});

it('writes a bell entry for the owner and respects a disabled teams preference', function () {
    $owner = User::factory()->create();
    $quiet = User::factory()->create(['notification_prefs' => ['teams' => false]]);
    $requester = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $quietTeam = Team::factory()->create(['owner_id' => $quiet->id]);

    $this->actingAs($requester)->post(route('teams.join', $team));
    $this->actingAs($requester)->post(route('teams.join', $quietTeam));

    expect($owner->unreadNotifications()->count())->toBe(1)
        ->and($owner->unreadNotifications()->first()->data['title'])->toBe('Neue Beitrittsanfrage')
        ->and($quiet->unreadNotifications()->count())->toBe(0);
});
