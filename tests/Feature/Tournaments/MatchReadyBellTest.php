<?php

use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Notifications\MatchReadyBell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $this->fake);
    config([
        'services.discord.guild_id' => 'guild-1',
        'services.discord.match_category_id' => 'category-1',
    ]);

    // MatchReady also triggers the Discord match-channel job and the
    // voice-provisioning listener; fake/stub them so this bell-focused test
    // never depends on their side effects.
    fakeMumble();
});

function readyMatchForBell(): array
{
    $tournament = Tournament::factory()->create(['name' => 'Valorant Cup']);

    $teamUser1 = User::factory()->create(['discord_id' => '111111']);
    $teamUser2 = User::factory()->create(['discord_id' => null]);
    $soloUser = User::factory()->create(['discord_id' => '222222']);

    $entry1 = TournamentEntry::factory()->team()->for($tournament)->create([
        'display_name' => 'Team Alpha',
        'roster_snapshot' => [
            ['user_id' => $teamUser1->id, 'name' => 'Alpha One'],
            ['user_id' => $teamUser2->id, 'name' => 'Alpha Two'],
        ],
    ]);

    $entry2 = TournamentEntry::factory()->solo()->for($tournament)->create([
        'display_name' => 'Solo Bravo',
        'user_id' => $soloUser->id,
        'roster_snapshot' => null,
    ]);

    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Ready,
    ]);

    return compact('match', 'teamUser1', 'teamUser2', 'soloUser');
}

it('notifies both rosters users with a database MatchReadyBell when a match becomes ready', function () {
    Notification::fake();

    ['match' => $match, 'teamUser1' => $teamUser1, 'teamUser2' => $teamUser2, 'soloUser' => $soloUser] = readyMatchForBell();

    event(new MatchReady($match));

    Notification::assertSentTo($teamUser1, MatchReadyBell::class);
    Notification::assertSentTo($teamUser2, MatchReadyBell::class);
    Notification::assertSentTo($soloUser, MatchReadyBell::class);
});

it('actually persists the database notification (not only the fake) with the match url and German title', function () {
    ['match' => $match, 'soloUser' => $soloUser] = readyMatchForBell();

    event(new MatchReady($match));

    $notification = $soloUser->fresh()->unreadNotifications()->firstOrFail();

    expect($notification->data['category'])->toBe('match')
        ->and($notification->data['title'])->toBe(__('tournaments.notifications.match_ready.title'))
        ->and($notification->data['body'])->toContain(route('tournaments.show', $match->tournament));
});

it('suppresses the Discord mirror but not the database entry when the match category preference is disabled', function () {
    ['match' => $match, 'soloUser' => $soloUser] = readyMatchForBell();
    $soloUser->update(['notification_prefs' => ['match' => false]]);

    event(new MatchReady($match));

    expect($soloUser->fresh()->unreadNotifications()->count())->toBe(1);
    expect(collect($this->fake->dms)->where('userDiscordId', $soloUser->discord_id))->toBeEmpty();
});
