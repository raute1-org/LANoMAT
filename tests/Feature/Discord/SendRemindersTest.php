<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config(['services.discord.announce_channel_id' => 'announce-1']);
});

it('sends the 24h reminder exactly once within the window', function () {
    $event = Event::factory()->registration()->create(['starts_at' => now()->addHours(24)]);

    $this->travelTo(now(), function () {
        $this->artisan('lanomat:send-reminders')->assertSuccessful();
        $this->artisan('lanomat:send-reminders')->assertSuccessful(); // second tick
    });

    $sent = collect($this->fake->messages)->filter(fn ($m) => str_contains($m['content'], '24'));
    expect($sent)->toHaveCount(1);
});

it('sends the 1h reminder when starts_at is within an hour', function () {
    Event::factory()->registration()->create(['starts_at' => now()->addMinutes(45)]);

    $this->artisan('lanomat:send-reminders')->assertSuccessful();

    expect(collect($this->fake->messages))->not->toBeEmpty();
});

it('does not send a reminder for events far in the future', function () {
    Event::factory()->registration()->create(['starts_at' => now()->addDays(10)]);

    $this->artisan('lanomat:send-reminders')->assertSuccessful();

    $this->fake->assertNothingSent();
});
