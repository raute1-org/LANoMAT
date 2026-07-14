<?php

use App\Models\User;
use App\Modules\Teams\Actions\CreateTeam;
use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('lists teams with german labels', function () {
    Team::factory()->create(['name' => 'Alpha Squad', 'tag' => 'ALP']);

    $this->get('/teams')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Teams/Index')
            ->where('labels.title', 'Teams')
            ->has('teams', 1)
        );
});

it('shows a team with members and owner', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha Squad', 'ALP');

    $this->get("/teams/{$team->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Teams/Show')
            ->where('team.name', 'Alpha Squad')
            ->where('team.owner.id', $owner->id)
            ->has('team.members', 1)
        );
});

it('creates a team on POST', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/teams', ['name' => 'Bravo Squad', 'tag' => 'BRV'])
        ->assertRedirect();

    $team = Team::query()->where('name', 'Bravo Squad')->first();

    expect($team)->not->toBeNull()
        ->and($team->owner_id)->toBe($user->id)
        ->and($team->members()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('rejects team creation with a missing name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/teams')
        ->post('/teams', ['tag' => 'BRV'])
        ->assertSessionHasErrors('name');
});

it('requires auth to create a team', function () {
    $this->post('/teams', ['name' => 'Bravo Squad', 'tag' => 'BRV'])
        ->assertRedirect(route('login'));
});

it('creates a join request on POST', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/teams/{$team->id}/join", ['message' => 'Let me in'])
        ->assertRedirect();

    expect(TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $user->id)->exists())
        ->toBeTrue();
});

it('forbids a non-owner from viewing the edit page', function () {
    $team = Team::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get("/teams/{$team->id}/edit")
        ->assertForbidden();
});

it('allows the owner to view the edit page with join requests and members', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha Squad', 'ALP');
    TeamJoinRequest::factory()->for($team)->create(['status' => JoinRequestStatus::Pending]);

    $this->actingAs($owner)
        ->get("/teams/{$team->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Teams/Edit')
            ->where('team.name', 'Alpha Squad')
            ->has('team.joinRequests', 1)
            ->where('labels.title', 'Teams')
        );
});

it('allows orga to view the edit page even when not the owner', function () {
    $team = Team::factory()->create();

    $this->actingAs(User::factory()->orga()->create())
        ->get("/teams/{$team->id}/edit")
        ->assertOk();
});

it('updates a team on PATCH including a logo upload', function () {
    Storage::fake('public');
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha Squad', 'ALP');

    $this->actingAs($owner)
        ->patch("/teams/{$team->id}", [
            'name' => 'Alpha Prime',
            'tag' => 'ALP',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertRedirect();

    $team->refresh();

    expect($team->name)->toBe('Alpha Prime')
        ->and($team->logo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($team->logo_path);
});

it('forbids a non-owner from updating a team', function () {
    $team = Team::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch("/teams/{$team->id}", ['name' => 'Hacked', 'tag' => 'HAX'])
        ->assertForbidden();
});

it('accepts a join request via the respond endpoint', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha Squad', 'ALP');
    $applicant = User::factory()->create();
    $request = TeamJoinRequest::factory()->for($team)->for($applicant)->create();

    $this->actingAs($owner)
        ->post("/teams/{$team->id}/requests/{$request->id}", ['accept' => true])
        ->assertRedirect();

    expect($request->fresh()->status)->toBe(JoinRequestStatus::Accepted)
        ->and($team->members()->where('user_id', $applicant->id)->exists())->toBeTrue();
});

it('forbids a non-owner from responding to a join request', function () {
    $team = Team::factory()->create();
    $request = TeamJoinRequest::factory()->for($team)->create();

    $this->actingAs(User::factory()->create())
        ->post("/teams/{$team->id}/requests/{$request->id}", ['accept' => true])
        ->assertForbidden();
});

it('lets a member leave the team on DELETE', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha Squad', 'ALP');
    $member = User::factory()->create();
    $team->members()->create(['user_id' => $member->id]);

    $this->actingAs($member)
        ->delete("/teams/{$team->id}/leave")
        ->assertRedirect();

    expect($team->members()->where('user_id', $member->id)->exists())->toBeFalse();
});
