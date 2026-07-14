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

it('does nothing when no announce channel is configured', function () {
    config(['services.discord.announce_channel_id' => null]);
    Event::factory()->registration()->create(['starts_at' => now()->addMinutes(45)]);

    $this->artisan('lanomat:send-reminders')->assertSuccessful();

    $this->fake->assertNothingSent();
});

it('sends both the 24h and 1h reminders for the same event as separate, independent dedup windows', function () {
    // One event, ticking the command as time travels through both reminder
    // windows. Proves the 24h and 1h dedup keys are independent: reaching
    // the 1h window must not be suppressed by the 24h send already having
    // fired, and re-ticking within a window must not resend.
    $event = Event::factory()->registration()->create(['starts_at' => now()->addHours(24)]);
    $reminder24h = __('discord.reminder', ['event' => $event->name, 'hours' => 24]);
    $reminder1h = __('discord.reminder', ['event' => $event->name, 'hours' => 1]);

    $this->travelTo(now(), function () {
        $this->artisan('lanomat:send-reminders')->assertSuccessful();
    });

    $sentAfter24h = collect($this->fake->messages)->filter(fn ($m) => $m['content'] === $reminder24h);
    expect($sentAfter24h)->toHaveCount(1);

    $this->travelTo($event->starts_at->copy()->subMinutes(45), function () {
        $this->artisan('lanomat:send-reminders')->assertSuccessful();
        $this->artisan('lanomat:send-reminders')->assertSuccessful(); // second tick in the same window
    });

    $sentAfter1h = collect($this->fake->messages)->filter(fn ($m) => $m['content'] === $reminder1h);
    expect($sentAfter1h)->toHaveCount(1)
        ->and(collect($this->fake->messages))->toHaveCount(2); // exactly one 24h + one 1h, no duplicates
});
