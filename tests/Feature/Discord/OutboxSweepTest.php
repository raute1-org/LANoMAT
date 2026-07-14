<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Models\DiscordOutbox;
use App\Modules\Discord\Testing\FakeDiscordClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config(['services.discord.announce_channel_id' => 'announce-1']);
});

it('resends a stale unsent outbox row and marks it sent', function () {
    $row = DiscordOutbox::create([
        'kind' => 'registration_open',
        'dedup_key' => 'event-1-registration-open',
        'channel_id' => 'announce-1',
        'content' => 'Registration is open for Event 1!',
        'sent_at' => null,
    ]);
    $row->created_at = now()->subMinutes(10);
    $row->save();

    $this->artisan('lanomat:sweep-discord-outbox')->assertSuccessful();

    expect($row->fresh()->sent_at)->not->toBeNull();
    $this->fake->assertMessageSent('announce-1', 'Event 1');
});

it('does not touch a row younger than 5 minutes', function () {
    $row = DiscordOutbox::create([
        'kind' => 'registration_open',
        'dedup_key' => 'event-2-registration-open',
        'channel_id' => 'announce-1',
        'content' => 'Registration is open for Event 2!',
        'sent_at' => null,
    ]);

    $this->artisan('lanomat:sweep-discord-outbox')->assertSuccessful();

    expect($row->fresh()->sent_at)->toBeNull();
    $this->fake->assertNothingSent();
});

it('does not touch a row that already has sent_at', function () {
    $row = DiscordOutbox::create([
        'kind' => 'registration_open',
        'dedup_key' => 'event-3-registration-open',
        'channel_id' => 'announce-1',
        'content' => 'Registration is open for Event 3!',
        'sent_at' => now(),
    ]);
    $row->created_at = now()->subMinutes(10);
    $row->save();

    $this->artisan('lanomat:sweep-discord-outbox')->assertSuccessful();

    $this->fake->assertNothingSent();
});

it('isolates a failing row so the other rows are still processed', function () {
    $failing = DiscordOutbox::create([
        'kind' => 'registration_open',
        'dedup_key' => 'event-fail-registration-open',
        'channel_id' => 'announce-1',
        'content' => 'This one always fails to send',
        'sent_at' => null,
    ]);
    $failing->created_at = now()->subMinutes(10);
    $failing->save();

    $goodBefore = DiscordOutbox::create([
        'kind' => 'registration_open',
        'dedup_key' => 'event-good-before-registration-open',
        'channel_id' => 'announce-1',
        'content' => 'Good message sent before the failing row',
        'sent_at' => null,
    ]);
    $goodBefore->created_at = now()->subMinutes(20);
    $goodBefore->save();

    $goodAfter = DiscordOutbox::create([
        'kind' => 'registration_open',
        'dedup_key' => 'event-good-after-registration-open',
        'channel_id' => 'announce-1',
        'content' => 'Good message sent after the failing row',
        'sent_at' => null,
    ]);
    $goodAfter->created_at = now()->subMinutes(8);
    $goodAfter->save();

    $failingContent = $failing->content;

    $client = new class($failingContent) extends FakeDiscordClient
    {
        public function __construct(private readonly string $failingContent) {}

        public function sendMessage(string $channelId, string $content, array $embeds = []): void
        {
            if ($content === $this->failingContent) {
                throw new RuntimeException('simulated Discord outage for this row');
            }

            parent::sendMessage($channelId, $content, $embeds);
        }
    };
    app()->instance(DiscordClient::class, $client);

    $this->artisan('lanomat:sweep-discord-outbox')->assertSuccessful();

    expect($failing->fresh()->sent_at)->toBeNull()
        ->and($goodBefore->fresh()->sent_at)->not->toBeNull()
        ->and($goodAfter->fresh()->sent_at)->not->toBeNull();
});
