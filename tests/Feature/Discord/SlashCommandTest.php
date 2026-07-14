<?php

use App\Models\User;
use App\Modules\Discord\Jobs\SendFollowupJob;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Policies\TournamentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Signs and posts an application-command interaction payload, reusing the
 * `signedInteraction()` helper declared in InteractionsSignatureTest.php
 * (Task 15) — same signature scheme, different payload shape.
 */
function postInteraction(array $body): TestResponse
{
    [$json, $timestamp, $sig] = signedInteraction($body);

    return test()->call('POST', '/api/discord/interactions', [], [], [],
        ['HTTP_X-Signature-Ed25519' => $sig, 'HTTP_X-Signature-Timestamp' => $timestamp,
            'CONTENT_TYPE' => 'application/json'], $json);
}

function applicationCommand(string $name, array $options = [], ?string $discordUserId = null): array
{
    return [
        'type' => 2,
        'application_id' => 'app-123',
        'token' => 'interaction-token-abc',
        'data' => [
            'name' => $name,
            'options' => $options,
        ],
        'member' => [
            'user' => [
                'id' => $discordUserId ?? '999999999',
            ],
        ],
    ];
}

beforeEach(function () {
    config(['services.discord.application_id' => 'app-123']);
});

it('lists the current event tournaments for /tournament list', function () {
    $event = Event::factory()->live()->create();
    Tournament::factory()->for($event)->create(['name' => 'Valorant Cup']);

    $body = applicationCommand('tournament', [
        ['name' => 'list', 'type' => 1, 'options' => []],
    ]);

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', fn ($content) => str_contains($content, 'Valorant Cup'));
});

it('checks in the mapped user entry for /tournament checkin when the window is open', function () {
    $user = User::factory()->create(['discord_id' => '111222333']);
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subMinute(),
        'checkin_closes_at' => now()->addHour(),
    ]);
    $entry = TournamentEntry::factory()->solo()->for($tournament)->create([
        'user_id' => $user->id,
        'status' => EntryStatus::Registered,
    ]);

    $body = applicationCommand('tournament', [
        ['name' => 'checkin', 'type' => 1, 'options' => [
            ['name' => 'id', 'type' => 4, 'value' => $tournament->id],
        ]],
    ], discordUserId: '111222333');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4);

    expect($entry->fresh()->status)->toBe(EntryStatus::CheckedIn);
});

it('does not check in an entry the mapped discord user does not own', function () {
    // Regression test for the missing-authorization review finding: a
    // discord_id-mapped user must not be able to check in an entry that
    // belongs to a different user. The entry query already filters by
    // ownership, but the TournamentPolicy::checkIn check must ALSO hold
    // independently (see the next test, which proves it is load-bearing).
    $owner = User::factory()->create(['discord_id' => '777888999']);
    $intruder = User::factory()->create(['discord_id' => '111000111']);
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subMinute(),
        'checkin_closes_at' => now()->addHour(),
    ]);
    $entry = TournamentEntry::factory()->solo()->for($tournament)->create([
        'user_id' => $owner->id,
        'status' => EntryStatus::Registered,
    ]);

    expect($intruder->can('checkIn', $entry))->toBeFalse();

    $body = applicationCommand('tournament', [
        ['name' => 'checkin', 'type' => 1, 'options' => [
            ['name' => 'id', 'type' => 4, 'value' => $tournament->id],
        ]],
    ], discordUserId: '111000111');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.commands.tournament.checkin.no_entry'));

    expect($entry->fresh()->status)->toBe(EntryStatus::Registered);
});

it('never invokes CheckInEntry when the resolved entry fails the checkIn policy for the mapped user', function () {
    // Directly proves the authorization guard is what gates the write: spy
    // on CheckInEntry and swap in a fake Gate response denying `checkIn` for
    // this entry, then assert `handle()` is never reached — i.e. the guard
    // in TournamentCommand::checkin() is load-bearing, not dead code shadowed
    // by the query filter.
    $user = User::factory()->create(['discord_id' => '222333444']);
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subMinute(),
        'checkin_closes_at' => now()->addHour(),
    ]);
    $entry = TournamentEntry::factory()->solo()->for($tournament)->create([
        'user_id' => $user->id,
        'status' => EntryStatus::Registered,
    ]);

    $this->partialMock(CheckInEntry::class, function ($mock) {
        $mock->shouldNotReceive('handle');
    });

    $this->partialMock(TournamentPolicy::class, function ($mock) {
        $mock->shouldReceive('checkIn')->andReturn(false);
    });

    $body = applicationCommand('tournament', [
        ['name' => 'checkin', 'type' => 1, 'options' => [
            ['name' => 'id', 'type' => 4, 'value' => $tournament->id],
        ]],
    ], discordUserId: '222333444');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.commands.tournament.checkin.no_entry'));

    expect($entry->fresh()->status)->toBe(EntryStatus::Registered);
});

it('returns a friendly not-linked response instead of a crash for an unmapped discord user', function () {
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subMinute(),
        'checkin_closes_at' => now()->addHour(),
    ]);

    $body = applicationCommand('tournament', [
        ['name' => 'checkin', 'type' => 1, 'options' => [
            ['name' => 'id', 'type' => 4, 'value' => $tournament->id],
        ]],
    ], discordUserId: 'unmapped-discord-id');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.not_linked'));
});

it('returns an error message (not a 500) when checkin happens outside the window', function () {
    $user = User::factory()->create(['discord_id' => '444555666']);
    $tournament = Tournament::factory()->enrollment()->create();
    TournamentEntry::factory()->solo()->for($tournament)->create([
        'user_id' => $user->id,
        'status' => EntryStatus::Registered,
    ]);

    $body = applicationCommand('tournament', [
        ['name' => 'checkin', 'type' => 1, 'options' => [
            ['name' => 'id', 'type' => 4, 'value' => $tournament->id],
        ]],
    ], discordUserId: '444555666');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('tournaments.errors.checkin_closed'));
});

it('returns tournament info for /tournament info', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create(['name' => 'CS Masters']);

    $body = applicationCommand('tournament', [
        ['name' => 'info', 'type' => 1, 'options' => [
            ['name' => 'id', 'type' => 4, 'value' => $tournament->id],
        ]],
    ]);

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', fn ($content) => str_contains($content, 'CS Masters'));
});

it('defers /tournament bracket and delivers the bracket link via a follow-up job', function () {
    Bus::fake();

    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create(['name' => 'Bracket Cup']);

    $body = applicationCommand('tournament', [
        ['name' => 'bracket', 'type' => 1, 'options' => [
            ['name' => 'id', 'type' => 4, 'value' => $tournament->id],
        ]],
    ]);

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 5);

    Bus::assertDispatched(SendFollowupJob::class, function (SendFollowupJob $job) use ($tournament) {
        return $job->applicationId === 'app-123'
            && $job->token === 'interaction-token-abc'
            && str_contains($job->content, (string) $tournament->id);
    });
});

it('sends the follow-up job content via a PATCH to the interaction webhook', function () {
    Http::fake([
        'discord.com/api/v10/webhooks/*' => Http::response([], 200),
    ]);

    $job = new SendFollowupJob('app-123', 'interaction-token-abc', 'Here is your bracket link.');
    $job->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.com/api/v10/webhooks/app-123/interaction-token-abc/messages/@original'
            && $request->method() === 'PATCH'
            && $request['content'] === 'Here is your bracket link.';
    });
});

it('returns the help text for /help', function () {
    $body = applicationCommand('help');

    postInteraction($body)
        ->assertOk()
        ->assertJsonPath('type', 4)
        ->assertJsonPath('data.content', __('discord.help.text'));
});
