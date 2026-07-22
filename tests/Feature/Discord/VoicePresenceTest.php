<?php

use App\Modules\Discord\Models\DiscordVoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a voice state row', function () {
    $row = DiscordVoiceState::create([
        'discord_user_id' => '900',
        'channel_id' => 'chan-1',
        'channel_name' => 'Turnier 1',
        'user_id' => null,
    ]);

    expect(DiscordVoiceState::query()->where('discord_user_id', '900')->exists())->toBeTrue()
        ->and($row->channel_name)->toBe('Turnier 1');
});
