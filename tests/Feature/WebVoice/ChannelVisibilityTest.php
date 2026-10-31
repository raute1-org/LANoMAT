<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Queries\VisibleChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->lobby = WebVoiceChannel::factory()->create(['name' => 'Lobby']);
    $this->teamA = WebVoiceChannel::factory()->match()->create(['name' => 'Team A']);
    $this->teamB = WebVoiceChannel::factory()->match()->create(['name' => 'Team B']);
    $this->archivedLobby = WebVoiceChannel::factory()->archived()->create(['name' => 'Old lobby']);

    $this->ada = User::factory()->create();
    $this->bob = User::factory()->create();
    $this->outsider = User::factory()->create();
    $this->admin = User::factory()->create(['role' => Role::Admin]);
    $this->orga = User::factory()->create(['role' => Role::Orga]);

    $this->teamA->members()->attach($this->ada);
    $this->teamB->members()->attach($this->bob);
});

function visibleNames(User $viewer): array
{
    return app(VisibleChannels::class)->for($viewer)->pluck('name')->all();
}

it('shows a participant the general channels plus their own match room, and nothing of the opponent', function () {
    expect(visibleNames($this->ada))->toBe(['Lobby', 'Team A'])
        ->and(visibleNames($this->bob))->toBe(['Lobby', 'Team B'])
        ->and(visibleNames($this->outsider))->toBe(['Lobby']);
});

it('shows an admin every live channel and no archived one', function () {
    expect(visibleNames($this->admin))->toBe(['Lobby', 'Team A', 'Team B']);
});

it('shows orga every live channel too, but gives helpers no extra view', function () {
    expect(visibleNames($this->orga))->toBe(['Lobby', 'Team A', 'Team B']);

    $helper = User::factory()->create(['role' => Role::Helper]);
    expect(visibleNames($helper))->toBe(['Lobby']);
});

it('agrees between the list query and the policy for every viewer and channel', function () {
    $viewers = [$this->ada, $this->bob, $this->outsider, $this->admin, $this->orga];
    $channels = WebVoiceChannel::query()->get();

    foreach ($viewers as $viewer) {
        $listed = app(VisibleChannels::class)->for($viewer)->modelKeys();

        foreach ($channels as $channel) {
            $allowed = Gate::forUser($viewer)->allows('view', $channel);

            // The admin passes Gate::before even for an archived channel; the actions refuse it.
            if ($channel->isArchived()) {
                expect(in_array($channel->id, $listed, true))->toBeFalse();

                continue;
            }

            expect($allowed)->toBe(in_array($channel->id, $listed, true), "{$viewer->role->value} vs {$channel->name}");
        }
    }
});

it('splits the abilities: orga and admin moderate, only admin manages channels', function () {
    $helper = User::factory()->create(['role' => Role::Helper]);

    foreach ([$this->ada, $helper, $this->outsider] as $user) {
        expect(Gate::forUser($user)->allows('moderate', $this->lobby))->toBeFalse()
            ->and(Gate::forUser($user)->allows('viewLive', WebVoiceChannel::class))->toBeFalse()
            ->and(Gate::forUser($user)->allows('manage', WebVoiceChannel::class))->toBeFalse();
    }

    expect(Gate::forUser($this->orga)->allows('moderate', $this->teamB))->toBeTrue()
        ->and(Gate::forUser($this->orga)->allows('viewLive', WebVoiceChannel::class))->toBeTrue()
        ->and(Gate::forUser($this->orga)->allows('manage', WebVoiceChannel::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('moderate', $this->teamB))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manage', WebVoiceChannel::class))->toBeTrue();
});

it('refuses join and post on an archived channel for non-admins', function () {
    expect(Gate::forUser($this->ada)->allows('join', $this->archivedLobby))->toBeFalse()
        ->and(Gate::forUser($this->ada)->allows('post', $this->archivedLobby))->toBeFalse();
});
