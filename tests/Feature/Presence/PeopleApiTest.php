<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Discord\Models\DiscordVoiceState;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Presence\Events\PresenceUpdated;
use App\Modules\Presence\Support\LocationSnapshotStore;
use App\Modules\Presence\Support\PresenceProjection;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/PresenceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->world = presenceWorld();
    $this->event = $this->world['event'];
    $this->users = $this->world['users'];
    $this->me = $this->users['Max'];
    $this->headers = playBearer($this->me);
});

function peopleIds(array $json): array
{
    return array_column($json['data'], 'id');
}

// ---------------------------------------------------------------- authentication

it('requires a valid bearer token on both routes', function () {
    $follow = presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow');

    $this->getJson(presencePeopleUrl($this->event))->assertUnauthorized();
    $this->getJson($follow)->assertUnauthorized();

    $garbage = ['Authorization' => 'Bearer garbage'];
    $this->getJson(presencePeopleUrl($this->event), $garbage)->assertUnauthorized();
    $this->getJson($follow, $garbage)->assertUnauthorized();

    $revoked = playBearer($this->me);
    VoiceClientToken::query()->update(['revoked_at' => now()]);
    $this->getJson(presencePeopleUrl($this->event), $revoked)->assertUnauthorized();
    $this->getJson($follow, $revoked)->assertUnauthorized();
});

it('does not accept the bearer token on the web presence page', function () {
    $this->getJson("/events/{$this->event->slug}/presence", $this->headers)->assertUnauthorized();
});

// ---------------------------------------------------------------- authorization

it('refuses somebody who is no participant of the event, but admits a participant and orga', function () {
    $stranger = User::factory()->create();

    $this->getJson(presencePeopleUrl($this->event), playBearer($stranger))->assertForbidden();
    $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow'), playBearer($stranger))->assertForbidden();

    $this->getJson(presencePeopleUrl($this->event), $this->headers)->assertOk();

    $orga = User::factory()->create(['role' => Role::Orga]);
    $this->getJson(presencePeopleUrl($this->event), playBearer($orga))->assertOk();
});

it('does not count a cancelled registration as participation', function () {
    $quitter = User::factory()->create();
    EventRegistration::factory()->cancelled()->create(['event_id' => $this->event->id, 'user_id' => $quitter->id]);

    $this->getJson(presencePeopleUrl($this->event), playBearer($quitter))->assertForbidden();

    // And a cancelled participant is not in anybody's list.
    $this->getJson(presencePeopleUrl($this->event), $this->headers)->assertOk();
    expect(peopleIds($this->getJson(presencePeopleUrl($this->event), $this->headers)->json()))->not->toContain($quitter->id);
});

it('answers 404 for a draft event and for an unknown one', function () {
    $draft = Event::factory()->create(['status' => EventStatus::Draft]);
    EventRegistration::factory()->create(['event_id' => $draft->id, 'user_id' => $this->me->id]);

    $this->getJson(presencePeopleUrl($draft), $this->headers)->assertNotFound();
    $this->getJson(presencePeopleUrl($draft, '/'.$this->users['Ada']->id.'/follow'), $this->headers)->assertNotFound();
    $this->getJson('/api/voice-client/v1/events/does-not-exist/people', $this->headers)->assertNotFound();
});

it('validates the filters', function () {
    $this->getJson(presencePeopleUrl($this->event).'?per_page=500', $this->headers)->assertUnprocessable();
    $this->getJson(presencePeopleUrl($this->event).'?page=0', $this->headers)->assertUnprocessable();
    $this->getJson(presencePeopleUrl($this->event).'?team=maybe', $this->headers)->assertUnprocessable();
});

// ---------------------------------------------------------------- the list

it('lists the participants with where they are, and never myself in the list', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    $this->world['web']->join($room->roomName(), $this->me->id);
    presenceRefresh();

    $json = $this->getJson(presencePeopleUrl($this->event), $this->headers)->assertOk()->json();

    expect(peopleIds($json))->toBe([$this->users['Ada']->id, $this->users['Bea']->id, $this->users['Cem']->id])
        ->and($json['me']['id'])->toBe($this->me->id)
        ->and($json['me']['isMe'])->toBeTrue()
        ->and($json['me']['voice'][0]['roomLabel'])->toBe('Lounge')
        ->and($json['data'][0])->toHaveKeys(['id', 'name', 'avatarUrl', 'team', 'isMe', 'isTeammate', 'isFriend', 'voice', 'game', 'lastSeenAt'])
        ->and($json['data'][0]['voice'][0])->toMatchArray(['provider' => 'webvoice', 'roomLabel' => 'Lounge', 'roomRef' => (string) $room->id, 'kind' => 'general'])
        ->and($json['data'][1]['voice'])->toBe([])
        ->and($json['data'][1]['game'])->toBeNull()
        ->and($json['meta']['total'])->toBe(3);
});

it('sorts teammates first, then people who are somewhere, then the rest by name', function () {
    $team = Team::factory()->create(['name' => 'Alpha Crew']);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $this->me->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $this->users['Cem']->id]);
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Bea']->id);
    presenceRefresh();

    $json = $this->getJson(presencePeopleUrl($this->event), $this->headers)->assertOk()->json();

    // Cem (teammate, nowhere) before Bea (somewhere) before Ada (nowhere, by name).
    expect(peopleIds($json))->toBe([$this->users['Cem']->id, $this->users['Bea']->id, $this->users['Ada']->id])
        ->and($json['data'][0]['team'])->toBe('Alpha Crew')
        ->and($json['data'][0]['isTeammate'])->toBeTrue()
        ->and($json['data'][1]['isTeammate'])->toBeFalse();
});

it('filters by team, by friends and by name', function () {
    $team = Team::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $this->me->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $this->users['Cem']->id]);
    Friendship::factory()->create(['requester_id' => $this->me->id, 'addressee_id' => $this->users['Bea']->id, 'status' => FriendshipStatus::Accepted]);
    presenceRefresh();

    $url = presencePeopleUrl($this->event);

    expect(peopleIds($this->getJson($url.'?team=1', $this->headers)->json()))->toBe([$this->users['Cem']->id])
        ->and(peopleIds($this->getJson($url.'?friends=1', $this->headers)->json()))->toBe([$this->users['Bea']->id])
        ->and(peopleIds($this->getJson($url.'?q=ad', $this->headers)->json()))->toBe([$this->users['Ada']->id])
        ->and(peopleIds($this->getJson($url.'?q=zzz', $this->headers)->json()))->toBe([]);
});

it('pages the list', function () {
    $json = $this->getJson(presencePeopleUrl($this->event).'?per_page=2&page=2', $this->headers)->assertOk()->json();

    expect($json['data'])->toHaveCount(1)
        ->and($json['meta'])->toMatchArray(['page' => 2, 'perPage' => 2, 'total' => 3, 'lastPage' => 2]);
});

// ---------------------------------------------------------------- privacy

it('leaves out somebody who opted out, but always shows me my own location', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    $this->world['web']->join($room->roomName(), $this->me->id);
    $this->me->update(['share_location' => false]);
    $this->users['Ada']->update(['share_location' => false]);
    presenceRefresh();

    $mine = $this->getJson(presencePeopleUrl($this->event), $this->headers)->assertOk()->json();
    expect(peopleIds($mine))->not->toContain($this->users['Ada']->id)
        ->and($mine['me']['voice'][0]['roomLabel'])->toBe('Lounge'); // I opted out too, and still see myself

    // Nobody else sees me while I am opted out.
    expect(peopleIds($this->getJson(presencePeopleUrl($this->event), playBearer($this->users['Bea']))->json()))->not->toContain($this->me->id);

    // Switching it back on, the others see me again.
    $this->me->update(['share_location' => true]);

    expect(peopleIds($this->getJson(presencePeopleUrl($this->event), playBearer($this->users['Bea']))->json()))->toContain($this->me->id);
});

it('honours an opt-out at once, without waiting for the next refresh', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    presenceRefresh();

    expect(peopleIds($this->getJson(presencePeopleUrl($this->event), $this->headers)->json()))->toContain($this->users['Ada']->id);

    $this->users['Ada']->update(['share_location' => false]);

    expect(peopleIds($this->getJson(presencePeopleUrl($this->event), $this->headers)->json()))->not->toContain($this->users['Ada']->id);
    $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow'), $this->headers)->assertNotFound();
});

it('answers following an opted-out user exactly like following an unknown user', function () {
    $this->users['Ada']->update(['share_location' => false]);

    $optedOut = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow'), $this->headers);
    $unknown = $this->getJson(presencePeopleUrl($this->event, '/999999/follow'), $this->headers);
    $garbage = $this->getJson(presencePeopleUrl($this->event, '/not-an-id/follow'), $this->headers);
    $outsider = User::factory()->create();
    $nonParticipant = $this->getJson(presencePeopleUrl($this->event, '/'.$outsider->id.'/follow'), $this->headers);
    $myself = $this->getJson(presencePeopleUrl($this->event, '/'.$this->me->id.'/follow'), $this->headers);

    $garbage->assertNotFound();

    foreach ([$optedOut, $unknown, $nonParticipant, $myself] as $response) {
        $response->assertNotFound();
        // Same status and the same message (the debug trace of the test env differs by line only).
        expect($response->json('message'))->toBe($unknown->json('message'))
            ->and($response->json('message'))->not->toContain('User');
    }
});

it('hides people who blocked me or whom I blocked, like an opt-out', function () {
    UserBlock::factory()->create(['blocker_id' => $this->me->id, 'blocked_id' => $this->users['Ada']->id]);
    UserBlock::factory()->create(['blocker_id' => $this->users['Bea']->id, 'blocked_id' => $this->me->id]);

    expect(peopleIds($this->getJson(presencePeopleUrl($this->event), $this->headers)->json()))->toBe([$this->users['Cem']->id]);

    $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Bea']->id.'/follow'), $this->headers)->assertNotFound();
});

it('broadcasts nothing and puts no location into the public presence surfaces', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    presenceRefresh();

    // The board (beamer scene + web page) is built without any location data.
    $board = PresenceProjection::forEvent($this->event)->toArray();
    expect(json_encode($board))->not->toContain('Lounge')->and(json_encode($board))->not->toContain('voice');

    // The refresh itself dispatches no broadcast event.
    Illuminate\Support\Facades\Event::fake();
    presenceRefresh();
    Illuminate\Support\Facades\Event::assertNotDispatched(PresenceUpdated::class);
});

it('shows the web presence page without any location data to a participant', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    presenceRefresh();

    $this->actingAs($this->me)->get("/events/{$this->event->slug}/presence")
        ->assertOk()
        ->assertDontSee('Lounge');
});

// ---------------------------------------------------------------- game servers

function presenceMatchServer(array $world, User $a, User $b, string $address = '203.0.113.5'): ServerLink
{
    $match = playMatch($world['event'], $a, $b);

    return ServerLink::factory()->create([
        'match_id' => $match->id,
        'title' => 'Matchserver',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: $address, port: 27015),
    ]);
}

it('shows my own match server with its join link and hides every other match server', function () {
    $mine = presenceMatchServer($this->world, $this->me, $this->users['Ada']);
    presenceMatchServer($this->world, $this->users['Bea'], $this->users['Cem'], '198.51.100.7');

    presenceLinkSteam($this->users['Ada'], '76561190000000001');
    presenceLinkSteam($this->users['Bea'], '76561190000000002');
    $this->world['steam']->playing('76561190000000001', '730', 'Counter-Strike 2', '203.0.113.5:27015')
        ->playing('76561190000000002', '730', 'Counter-Strike 2', '198.51.100.7:27015');
    presenceRefresh();

    $json = $this->getJson(presencePeopleUrl($this->event), $this->headers)->assertOk()->json();
    $byId = collect($json['data'])->keyBy('id');

    expect($byId[$this->users['Ada']->id]['game'])->toMatchArray([
        'source' => 'steam',
        'game' => 'Counter-Strike 2',
        'serverTitle' => 'Matchserver',
        'address' => '203.0.113.5',
        'port' => 27015,
        'linkId' => $mine->id,
        'joinUrl' => 'steam://connect/203.0.113.5:27015',
    ]);

    // Bea plays on another match's server: only the game is visible, not even a hint where.
    expect($byId[$this->users['Bea']->id]['game'])->toMatchArray([
        'source' => 'steam', 'game' => 'Counter-Strike 2',
        'serverTitle' => null, 'address' => null, 'port' => null, 'linkId' => null, 'joinUrl' => null,
    ]);
    expect(json_encode($json))->not->toContain('198.51.100.7');
});

it('shows orga every match server', function () {
    presenceMatchServer($this->world, $this->users['Bea'], $this->users['Cem'], '198.51.100.7');
    presenceLinkSteam($this->users['Bea'], '76561190000000002');
    $this->world['steam']->playing('76561190000000002', '730', 'CS2', '198.51.100.7:27015');
    presenceRefresh();

    $orga = User::factory()->create(['role' => Role::Orga]);
    $game = collect($this->getJson(presencePeopleUrl($this->event), playBearer($orga))->json('data'))->firstWhere('id', $this->users['Bea']->id)['game'];

    expect($game['address'])->toBe('198.51.100.7')->and($game['joinUrl'])->toBe('steam://connect/198.51.100.7:27015');
});

it('gives a steam player on a server that is not ours the public address and a steam link', function () {
    presenceLinkSteam($this->users['Ada'], '76561190000000001');
    $this->world['steam']->playing('76561190000000001', '440', 'Team Fortress 2', '192.0.2.50:27016');
    presenceRefresh();

    $game = $this->getJson(presencePeopleUrl($this->event), $this->headers)->json('data.0.game');

    expect($game)->toMatchArray(['source' => 'steam', 'address' => '192.0.2.50', 'port' => 27016, 'linkId' => null, 'joinUrl' => 'steam://connect/192.0.2.50:27016']);
});

it('shows a minecraft player from the player list with the server join text and no steam link', function () {
    ServerLink::factory()->create([
        'event_id' => $this->event->id,
        'title' => 'Survival',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.9', port: 25565, connectString: '203.0.113.9:25565'),
        'query_info' => ['name' => 'Survival', 'map' => '', 'players' => 1, 'max_players' => 20, 'player_names' => ['Cem']],
    ]);
    presenceRefresh();

    $json = $this->getJson(presencePeopleUrl($this->event), $this->headers)->json();
    $game = collect($json['data'])->firstWhere('id', $this->users['Cem']->id)['game'];

    expect($game)->toMatchArray(['source' => 'query', 'serverTitle' => 'Survival', 'address' => '203.0.113.9', 'port' => 25565, 'joinUrl' => null]);

    $follow = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Cem']->id.'/follow'), $this->headers)->assertOk()->json();
    expect($follow['game']['copyText'])->toBe('203.0.113.9:25565');
});

// ---------------------------------------------------------------- follow

it('gives the voice targets per provider, with my own name in the Mumble and TeamSpeak links', function () {
    $room = presenceWebRoom('Lounge');
    $web = $this->world['web'];
    $web->join($room->roomName(), $this->users['Ada']->id);
    DiscordVoiceState::query()->create(['discord_user_id' => '111', 'channel_id' => 'chan-9', 'channel_name' => 'Allgemein', 'user_id' => $this->users['Ada']->id]);
    $mumble = $this->world['voice']['mumble'];
    $lobby = $mumble->createChannel('Lobby');
    $mumble->setOccupantNames($lobby->id, ['Ada']);
    $ts = $this->world['voice']['teamspeak'];
    $ts->setOccupantNames($ts->createChannel('Lobby')->id, ['Ada']);
    presenceRefresh();

    $json = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow'), $this->headers)->assertOk()->json();
    $byProvider = collect($json['voice'])->keyBy('provider');

    expect($json['user'])->toBe(['id' => $this->users['Ada']->id, 'name' => 'Ada'])
        ->and($json['preferredProvider'])->toBeNull()
        ->and($byProvider)->toHaveCount(4)
        ->and($byProvider['webvoice']['join'])->toBe(['roomId' => $room->id, 'tokenPath' => "/api/voice-client/v1/rooms/{$room->id}/token"])
        ->and($byProvider['discord']['join'])->toBe(['url' => 'https://discord.com/channels/guild-1/chan-9'])
        ->and($byProvider['mumble']['join']['url'])->toBe('mumble://Max@voice.example.test:64738/Lobby')
        ->and($byProvider['teamspeak']['join']['url'])->toBe('ts3server://ts.example.test?port=9987&nickname=Max&channel=Lobby')
        ->and(collect($json['voice'])->pluck('joinable')->unique()->all())->toBe([true]);
});

it('puts the provider I am already connected to first and names it as preferred', function () {
    $lounge = presenceWebRoom('Lounge');
    $web = $this->world['web'];
    $web->join($lounge->roomName(), $this->users['Ada']->id);
    DiscordVoiceState::query()->create(['discord_user_id' => '111', 'channel_id' => 'chan-9', 'channel_name' => 'Allgemein', 'user_id' => $this->users['Ada']->id]);
    // I am on Discord (somewhere else) already.
    DiscordVoiceState::query()->create(['discord_user_id' => '222', 'channel_id' => 'chan-1', 'channel_name' => 'Lounge Discord', 'user_id' => $this->me->id]);
    presenceRefresh();

    $json = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow'), $this->headers)->assertOk()->json();

    expect($json['preferredProvider'])->toBe('discord')
        ->and(array_column($json['voice'], 'provider'))->toBe(['discord', 'webvoice'])
        ->and(array_key_exists('connected', $json['voice'][0]))->toBeFalse();
});

it('does not hand out a room that is only for its own roster, neither in the list nor in follow', function () {
    $room = presenceWebRoom('Team Bea', [$this->users['Bea']->id, $this->users['Cem']->id]);
    $this->world['web']->join($room->roomName(), $this->users['Bea']->id);
    presenceRefresh();

    $list = $this->getJson(presencePeopleUrl($this->event), $this->headers)->json();
    $bea = collect($list['data'])->firstWhere('id', $this->users['Bea']->id);
    expect($bea['voice'][0]['roomLabel'])->toBe('Team Bea')->and($bea['voice'][0]['roomRef'])->toBeNull();

    $follow = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Bea']->id.'/follow'), $this->headers)->json();
    expect($follow['voice'][0])->toMatchArray(['provider' => 'webvoice', 'joinable' => false, 'join' => null]);

    // A teammate on the roster gets it, and so does orga.
    $cem = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Bea']->id.'/follow'), playBearer($this->users['Cem']))->json();
    expect($cem['voice'][0]['joinable'])->toBeTrue()->and($cem['voice'][0]['join']['roomId'])->toBe($room->id);

    $orga = User::factory()->create(['role' => Role::Orga]);
    expect($this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Bea']->id.'/follow'), playBearer($orga))->json('voice.0.joinable'))->toBeTrue();
});

it('gives the game target exactly as the servers api builds it, only for servers I may see', function () {
    presenceMatchServer($this->world, $this->me, $this->users['Ada']);
    presenceMatchServer($this->world, $this->users['Bea'], $this->users['Cem'], '198.51.100.7');
    presenceLinkSteam($this->users['Ada'], '76561190000000001');
    presenceLinkSteam($this->users['Bea'], '76561190000000002');
    $this->world['steam']->playing('76561190000000001', '730', 'CS2', '203.0.113.5:27015')->playing('76561190000000002', '730', 'CS2', '198.51.100.7:27015');
    presenceRefresh();

    $ada = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow'), $this->headers)->assertOk()->json('game');
    $servers = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)->json('servers.0');

    expect($ada['joinUrl'])->toBe($servers['joinUrl'])->and($ada['copyText'])->toBe($servers['copyText']);

    $bea = $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Bea']->id.'/follow'), $this->headers)->assertOk()->json('game');
    expect($bea)->toMatchArray(['game' => 'CS2', 'address' => null, 'port' => null, 'joinUrl' => null, 'linkId' => null, 'copyText' => null]);
});

it('follows somebody who is nowhere with empty targets', function () {
    presenceRefresh();

    $this->getJson(presencePeopleUrl($this->event, '/'.$this->users['Ada']->id.'/follow'), $this->headers)
        ->assertOk()
        ->assertExactJson(['user' => ['id' => $this->users['Ada']->id, 'name' => 'Ada'], 'preferredProvider' => null, 'voice' => [], 'game' => null]);
});

// ---------------------------------------------------------------- the cache

it('reads the cached snapshot and calls no source while serving a request', function () {
    presenceLinkSteam($this->users['Ada'], '76561190000000001');
    $this->world['steam']->playing('76561190000000001', '730', 'CS2');
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    presenceRefresh();
    expect($this->world['steam']->requests)->toHaveCount(1);

    // The world changes, but until the next refresh the requests still see the cached picture.
    $this->world['web']->rooms = [];
    $this->world['steam']->idle('76561190000000001');

    foreach (range(1, 3) as $ignored) {
        $json = $this->getJson(presencePeopleUrl($this->event), $this->headers)->json();
        expect(collect($json['data'])->firstWhere('id', $this->users['Ada']->id)['voice'][0]['roomLabel'])->toBe('Lounge');
    }

    expect($this->world['steam']->requests)->toHaveCount(1);

    presenceRefresh();
    expect(collect($this->getJson(presencePeopleUrl($this->event), $this->headers)->json('data'))->firstWhere('id', $this->users['Ada']->id)['voice'])->toBe([]);
});

it('builds the snapshot on a cold cache instead of failing, and says when it was taken', function () {
    app(LocationSnapshotStore::class)->forget();

    $json = $this->getJson(presencePeopleUrl($this->event), $this->headers)->assertOk()->json();

    expect($json['meta']['snapshotAt'])->not->toBeNull();
});

it('lets a snapshot expire to "nobody located" rather than serve ancient data', function () {
    $room = presenceWebRoom('Lounge');
    $this->world['web']->join($room->roomName(), $this->users['Ada']->id);
    presenceRefresh();

    $this->travel(LocationSnapshotStore::TTL_SECONDS + 1)->seconds();
    $this->world['web']->rooms = [];

    // The expired snapshot is rebuilt from the (now empty) sources on the next read.
    $json = $this->getJson(presencePeopleUrl($this->event), $this->headers)->json();
    expect(collect($json['data'])->firstWhere('id', $this->users['Ada']->id)['voice'])->toBe([]);
});
