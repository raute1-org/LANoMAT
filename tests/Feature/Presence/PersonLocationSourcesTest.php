<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Discord\Models\DiscordVoiceState;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Presence\Support\LocationSnapshotStore;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/PresenceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->world = presenceWorld();
    $this->users = $this->world['users'];
});

function snapshotPeople(): array
{
    return app(LocationSnapshotStore::class)->get()?->people ?? [];
}

it('locates a user in a web voice room exactly through the u<id> identity', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id, 'Whatever Name');

    presenceRefresh();

    $voice = snapshotPeople()[$this->users['Ada']->id]->voice;
    expect($voice)->toHaveCount(1)
        ->and($voice[0]->provider)->toBe('webvoice')
        ->and($voice[0]->roomLabel)->toBe('Lounge')
        ->and($voice[0]->kind)->toBe('general')
        ->and($voice[0]->audience)->toBeNull();
});

it('gives a web match room and a team room the roster as audience', function () {
    $match = presenceWebRoom('Team Alpha', [$this->users['Ada']->id, $this->users['Bea']->id]);
    $team = presenceWebRoom('Squad', [$this->users['Cem']->id], 'team');
    $this->world['web']->join($match->roomName(), $this->users['Ada']->id);
    $this->world['web']->join($team->roomName(), $this->users['Cem']->id);

    presenceRefresh();

    $people = snapshotPeople();
    expect($people[$this->users['Ada']->id]->voice[0]->kind)->toBe('match')
        ->and($people[$this->users['Ada']->id]->voice[0]->audience)->toBe([$this->users['Ada']->id, $this->users['Bea']->id])
        ->and($people[$this->users['Cem']->id]->voice[0]->kind)->toBe('team');
});

it('maps a Discord voice state through the user it is mapped to and ignores unmapped accounts', function () {
    DiscordVoiceState::query()->create(['discord_user_id' => '111', 'channel_id' => 'chan-1', 'channel_name' => 'Allgemein', 'user_id' => $this->users['Bea']->id]);
    DiscordVoiceState::query()->create(['discord_user_id' => '999', 'channel_id' => 'chan-1', 'channel_name' => 'Allgemein', 'user_id' => null]);

    presenceRefresh();

    $people = snapshotPeople();
    expect(array_keys($people))->toBe([$this->users['Bea']->id])
        ->and($people[$this->users['Bea']->id]->voice[0]->provider)->toBe('discord')
        ->and($people[$this->users['Bea']->id]->voice[0]->roomRef)->toBe('chan-1');

    // The No-PII rule: nothing about the unmapped Discord account reaches the snapshot.
    expect(json_encode(app(LocationSnapshotStore::class)->get()?->toArray()))->not->toContain('999');
});

it('finds somebody on Mumble by the name the join link gives them, case insensitively', function () {
    $mumble = $this->world['voice']['mumble'];
    $channel = $mumble->createChannel('Lobby');
    $mumble->setOccupantNames($channel->id, ['ada', 'Stranger']);

    presenceRefresh();

    $people = snapshotPeople();
    expect(array_keys($people))->toBe([$this->users['Ada']->id])
        ->and($people[$this->users['Ada']->id]->voice[0]->provider)->toBe('mumble')
        ->and($people[$this->users['Ada']->id]->voice[0]->roomLabel)->toBe('Lobby');
});

it('does not guess when two users have the same name, nor for an opted-out namesake', function () {
    User::factory()->create(['name' => 'Twin']);
    User::factory()->create(['name' => 'Twin', 'share_location' => false]);
    $mumble = $this->world['voice']['mumble'];
    $channel = $mumble->createChannel('Lobby');
    $mumble->setOccupantNames($channel->id, ['Twin']);

    presenceRefresh();

    expect(snapshotPeople())->toBe([]);
});

it('treats a TeamSpeak collision suffix as unknown, not as the name with a digit chopped off', function () {
    $ts = $this->world['voice']['teamspeak'];
    $channel = $ts->createChannel('Lobby');
    $ts->setOccupantNames($channel->id, ['Ada1']);

    presenceRefresh();

    expect(snapshotPeople())->toBe([]);
});

it('builds the path of a Mumble sub-channel for the join link and the roster audience of a match room', function () {
    $mumble = $this->world['voice']['mumble'];
    $root = $mumble->createChannel('🏆 Cup');
    $team = $mumble->createChannel('Team Alpha', $root->id);
    $mumble->setOccupantNames($team->id, ['Ada']);

    $tournament = Tournament::factory()->live()->create([
        'event_id' => $this->world['event']->id,
        'team_size' => 2,
        'settings' => ['voice' => ['mumble' => ['tournament_channel_id' => $root->id, 'team_channel_ids' => [$team->id]]]],
    ]);
    TournamentEntry::factory()->team()->for($tournament)->create([
        'display_name' => 'Team Alpha',
        'roster_snapshot' => [['user_id' => $this->users['Ada']->id], ['user_id' => $this->users['Bea']->id]],
    ]);

    presenceRefresh();

    $voice = snapshotPeople()[$this->users['Ada']->id]->voice[0];
    expect($voice->kind)->toBe('team')
        ->and($voice->joinPath)->toBe('🏆 Cup/Team Alpha')
        ->and($voice->audience)->toBe([$this->users['Ada']->id, $this->users['Bea']->id]);
});

it('puts one person in several providers at once', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    DiscordVoiceState::query()->create(['discord_user_id' => '111', 'channel_id' => 'chan-1', 'channel_name' => 'Allgemein', 'user_id' => $this->users['Ada']->id]);
    $mumble = $this->world['voice']['mumble'];
    $mumble->setOccupantNames($mumble->createChannel('Lobby')->id, ['Ada']);

    presenceRefresh();

    $providers = array_map(fn ($v) => $v->provider, snapshotPeople()[$this->users['Ada']->id]->voice);
    sort($providers);
    expect($providers)->toBe(['discord', 'mumble', 'webvoice']);
});

it('reads the game from steam for a linked account and asks steam once for everybody', function () {
    presenceLinkSteam($this->users['Ada'], '76561190000000001');
    presenceLinkSteam($this->users['Bea'], '76561190000000002');
    $this->world['steam']->playing('76561190000000001', '730', 'Counter-Strike 2', '198.51.100.7:27015')->idle('76561190000000002');

    presenceRefresh();

    $people = snapshotPeople();
    expect(array_keys($people))->toBe([$this->users['Ada']->id])
        ->and($people[$this->users['Ada']->id]->game->source)->toBe('steam')
        ->and($people[$this->users['Ada']->id]->game->game)->toBe('Counter-Strike 2')
        ->and($this->world['steam']->requests)->toHaveCount(1)
        ->and($this->world['steam']->requests[0])->toBe(['76561190000000001', '76561190000000002']);
});

it('still locates somebody who opted out, so that they can see themselves', function () {
    $this->users['Ada']->update(['share_location' => false]);
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);

    presenceRefresh();

    expect(snapshotPeople())->toHaveKey($this->users['Ada']->id);
});

it('treats a steam failure as unknown and still reports the voice rooms', function () {
    presenceLinkSteam($this->users['Ada'], '76561190000000001');
    $this->world['steam']->failing();
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);

    presenceRefresh();

    $person = snapshotPeople()[$this->users['Ada']->id];
    expect($person->voice)->toHaveCount(1)->and($person->game)->toBeNull();
});

it('takes the game from the player list of our own server when the name is unambiguous', function () {
    $game = Game::factory()->create(['name' => 'Minecraft']);
    $link = ServerLink::factory()->create([
        'event_id' => $this->world['event']->id,
        'game_id' => $game->id,
        'title' => 'Survival',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.9', port: 25565),
        'query_info' => ['name' => 'Survival', 'map' => '', 'players' => 2, 'max_players' => 20, 'player_names' => ['Bea', 'Unknown Player']],
        'queried_at' => now(),
    ]);

    presenceRefresh();

    $person = snapshotPeople()[$this->users['Bea']->id];
    expect($person->game->source)->toBe('query')
        ->and($person->game->linkId)->toBe($link->id)
        ->and($person->game->game)->toBe('Minecraft')
        ->and($person->game->serverTitle)->toBe('Survival');
});

it('matches a player-list name to a linked steam persona name too', function () {
    presenceLinkSteam($this->users['Cem'], '76561190000000003', nickname: 'x_CemTheDream_x');
    ServerLink::factory()->create([
        'event_id' => $this->world['event']->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.9', port: 25565),
        'query_info' => ['name' => 'S', 'map' => '', 'players' => 1, 'max_players' => 8, 'player_names' => ['x_cemthedream_x']],
    ]);

    presenceRefresh();

    expect(snapshotPeople()[$this->users['Cem']->id]->game->source)->toBe('query');
});

it('keeps since while somebody stays in the same room and restarts it in another', function () {
    $lounge = presenceWebRoom('Lounge');
    $other = presenceWebRoom('Other');
    $web = $this->world['web'];
    $web->join($lounge->roomName(), $this->users['Ada']->id);

    $this->travelTo(now()->startOfMinute());
    presenceRefresh();
    $first = snapshotPeople()[$this->users['Ada']->id]->voice[0]->since;

    $this->travel(2)->minutes();
    presenceRefresh();
    expect(snapshotPeople()[$this->users['Ada']->id]->voice[0]->since)->toBe($first);

    $web->rooms = [];
    $web->join($other->roomName(), $this->users['Ada']->id);
    $this->travel(1)->minutes();
    presenceRefresh();
    expect(snapshotPeople()[$this->users['Ada']->id]->voice[0]->since)->not->toBe($first);
});

it('remembers when somebody was last seen after they left', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);

    presenceRefresh();
    $seen = app(LocationSnapshotStore::class)->get()->lastSeen[$this->users['Ada']->id];

    $this->world['web']->rooms = [];
    $this->travel(4)->minutes();
    presenceRefresh();

    $snapshot = app(LocationSnapshotStore::class)->get();
    expect($snapshot->people)->toBe([])
        ->and($snapshot->lastSeen[$this->users['Ada']->id])->toBe($seen);
});

it('survives one voice backend being down and reports the others', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    DiscordVoiceState::query()->create(['discord_user_id' => '111', 'channel_id' => 'chan-1', 'channel_name' => 'Allgemein', 'user_id' => $this->users['Bea']->id]);
    $this->world['web']->unavailable = true;

    presenceRefresh();

    expect(array_keys(snapshotPeople()))->toBe([$this->users['Bea']->id]);
});

it('only asks the voice providers that are switched on', function () {
    config(['services.voice.providers' => ['mumble']]);
    $ts = $this->world['voice']['teamspeak'];
    $ts->setOccupantNames($ts->createChannel('Lobby')->id, ['Ada']);

    presenceRefresh();

    expect(snapshotPeople())->toBe([]);
});

it('leaves out the voice backends whose module is off', function () {
    $modules = new Modules('full', ['web_voice' => false]);
    app()->instance(Modules::class, $modules);

    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    DiscordVoiceState::query()->create(['discord_user_id' => '111', 'channel_id' => 'chan-1', 'channel_name' => 'Allgemein', 'user_id' => $this->users['Bea']->id]);

    presenceRefresh();

    expect(array_keys(snapshotPeople()))->toBe([$this->users['Bea']->id]);
});

it('is refreshed by a scheduled command that runs while an event is live', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('presence:refresh-locations');

    $this->artisan('presence:refresh-locations')->expectsOutputToContain('located')->assertSuccessful();
});
