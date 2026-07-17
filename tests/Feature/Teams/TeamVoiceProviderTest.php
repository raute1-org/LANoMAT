<?php

declare(strict_types=1);

use App\Modules\Teams\Models\Team;
use App\Modules\Voice\Domain\VoiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists and casts a team voice provider choice', function () {
    $team = Team::factory()->create(['voice_provider' => VoiceProvider::TeamSpeak]);

    expect($team->fresh()->voice_provider)->toBe(VoiceProvider::TeamSpeak);
});

it('reads back null when a team never chose a voice provider', function () {
    $team = Team::factory()->create();

    expect($team->fresh()->voice_provider)->toBeNull();
});
