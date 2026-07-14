<?php

use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Notifications\DiscordDirectMessage;
use App\Modules\Discord\Testing\FakeDiscordClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
});

it('sends a Discord DM to a user with a discord_id', function () {
    $user = User::factory()->create(['discord_id' => 'discord-123']);

    $user->notify(new DiscordDirectMessage('hello there'));

    $this->fake->assertDmSent('discord-123');
    expect($this->fake->dms[0]['content'])->toBe('hello there');
});

it('sends nothing and does not throw for a user without a discord_id', function () {
    $user = User::factory()->create(['discord_id' => null]);

    $user->notify(new DiscordDirectMessage('hello there'));

    $this->fake->assertNothingSent();
});

it('suppresses the DM when the notification category is disabled', function () {
    $user = User::factory()->create([
        'discord_id' => 'discord-123',
        'notification_prefs' => ['matches' => false],
    ]);

    $user->notify(new DiscordDirectMessage('your match starts soon', category: 'matches'));

    $this->fake->assertNothingSent();
});

it('sends when the category is not explicitly disabled', function () {
    $user = User::factory()->create([
        'discord_id' => 'discord-123',
        'notification_prefs' => ['matches' => true],
    ]);

    $user->notify(new DiscordDirectMessage('your match starts soon', category: 'matches'));

    $this->fake->assertDmSent('discord-123');
});
