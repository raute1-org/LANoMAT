<?php

use App\Modules\Discord\Models\DiscordVoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the current voice occupancy as JSON', function () {
    DiscordVoiceState::create(['discord_user_id' => '900', 'channel_id' => 'c1', 'channel_name' => 'Turnier 1', 'user_id' => null]);

    $this->getJson('/discord/voice')
        ->assertOk()
        ->assertJsonPath('channels.0.channel', 'Turnier 1')
        ->assertJsonPath('channels.0.count', 1);
});
