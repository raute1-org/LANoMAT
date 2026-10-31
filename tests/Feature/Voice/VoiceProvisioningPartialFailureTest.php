<?php

declare(strict_types=1);

use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voice\Jobs\ProvisionMatchVoiceJob;
use App\Modules\Voice\Jobs\ProvisionTournamentVoiceJob;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function matchWithTwoEntries(): GameMatch
{
    $tournament = Tournament::factory()->live()->create(['team_size' => 1]);
    $one = TournamentEntry::factory()->solo()->for($tournament)->create(['display_name' => 'Alice']);
    $two = TournamentEntry::factory()->solo()->for($tournament)->create(['display_name' => 'Bob']);

    return GameMatch::factory()->for($tournament)->create(['entry1_id' => $one->id, 'entry2_id' => $two->id]);
}

it('keeps the rooms of a provider that finished when another provider fails, and a retry only adds the missing one', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    $match = matchWithTwoEntries();
    $fakes['teamspeak']->failCreateWhen = fn () => true;

    expect(fn () => (new ProvisionMatchVoiceJob($match->id))->handle(app(VoiceProviders::class)))->toThrow(RuntimeException::class);

    // Mumble's rooms are stored the moment that provider is done, not lost with the failure.
    expect(array_keys($match->fresh()->voice_channels))->toBe(['mumble']);
    expect($fakes['mumble']->channels)->toHaveCount(2);

    $fakes['teamspeak']->failCreateWhen = null;
    (new ProvisionMatchVoiceJob($match->id))->handle(app(VoiceProviders::class));

    // The retry does not create Mumble's rooms a second time.
    expect($fakes['mumble']->channels)->toHaveCount(2);
    expect($fakes['teamspeak']->channels)->toHaveCount(2);
    expect(array_keys($match->fresh()->voice_channels))->toBe(['mumble', 'teamspeak']);
});

it('removes the first room again when the second room of a provider cannot be created', function () {
    $fakes = fakeVoice(['mumble']);
    $match = matchWithTwoEntries();
    $fakes['mumble']->failCreateWhen = fn (string $name) => str_ends_with($name, ' · Bob');

    expect(fn () => (new ProvisionMatchVoiceJob($match->id))->handle(app(VoiceProviders::class)))->toThrow(RuntimeException::class);

    expect($fakes['mumble']->channels)->toBeEmpty();
    expect($match->fresh()->voice_channels)->toBeNull();
});

it('keeps the tree of a provider that finished when another provider fails, and a retry does not duplicate it', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    $tournament = Tournament::factory()->live()->create(['name' => 'Cup', 'team_size' => 5]);
    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Alpha']);
    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Bravo']);
    $fakes['teamspeak']->failCreateWhen = fn () => true;

    expect(fn () => (new ProvisionTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class)))->toThrow(RuntimeException::class);

    expect(array_keys($tournament->fresh()->settings['voice']))->toBe(['mumble']);
    expect($fakes['mumble']->channels)->toHaveCount(3); // the tournament channel and two teams

    $fakes['teamspeak']->failCreateWhen = null;
    (new ProvisionTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    expect($fakes['mumble']->channels)->toHaveCount(3);
    expect($fakes['teamspeak']->channels)->toHaveCount(3);
    expect(array_keys($tournament->fresh()->settings['voice']))->toBe(['mumble', 'teamspeak']);
});

it('removes a half-built tournament tree again when a team channel cannot be created', function () {
    $fakes = fakeVoice(['mumble']);
    $tournament = Tournament::factory()->live()->create(['name' => 'Cup', 'team_size' => 5]);
    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Alpha']);
    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Bravo']);
    $fakes['mumble']->failCreateWhen = fn (string $name) => $name === 'Team Bravo';

    expect(fn () => (new ProvisionTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class)))->toThrow(RuntimeException::class);

    expect($fakes['mumble']->channels)->toBeEmpty();
    expect($tournament->fresh()->settings['voice'] ?? [])->toBe([]);
});
