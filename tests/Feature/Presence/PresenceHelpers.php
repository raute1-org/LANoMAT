<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Contracts\SteamPresence;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Testing\FakeSteamPresence;
use App\Modules\Presence\Support\LocationSnapshotBuilder;
use App\Modules\Voice\Testing\FakeVoiceClient;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;

require_once __DIR__.'/../WebVoice/NativeClient/PlayHelpers.php';

/** Shared helpers of the "where is everybody" tests. */
if (! function_exists('presenceWorld')) {
    /**
     * A live event, the fakes of every location source, and registered participants by name.
     *
     * @param  list<string>  $names
     * @return array{event: Event, users: array<string, User>, discord: FakeDiscordClient, voice: array<string, FakeVoiceClient>, web: FakeWebVoiceClient, steam: FakeSteamPresence}
     */
    function presenceWorld(array $names = ['Max', 'Ada', 'Bea', 'Cem']): array
    {
        config([
            'services.discord.guild_id' => 'guild-1',
            'services.mumble.host' => 'voice.example.test',
            'services.mumble.port' => 64738,
            'services.teamspeak.host' => 'ts.example.test',
            'services.teamspeak.port' => 9987,
        ]);

        $discord = fakeDiscord();
        $voice = fakeVoice(['mumble', 'teamspeak']);
        $web = new FakeWebVoiceClient;
        app()->instance(WebVoiceClient::class, $web);
        $steam = new FakeSteamPresence;
        app()->instance(SteamPresence::class, $steam);

        $event = playEvent();
        $users = [];

        foreach ($names as $name) {
            $users[$name] = User::factory()->create(['name' => $name]);
            playRegister($users[$name], $event);
        }

        return compact('event', 'users', 'discord', 'voice', 'web', 'steam');
    }

    /** What the scheduler does every 30 seconds. */
    function presenceRefresh(): void
    {
        app(LocationSnapshotBuilder::class)->refresh();
    }

    function presenceLinkSteam(User $user, string $steamId, ?string $nickname = null): LinkedAccount
    {
        return LinkedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => LinkedAccountProvider::Steam,
            'provider_user_id' => $steamId,
            'nickname' => $nickname ?? 'steam-'.$user->id,
        ]);
    }

    /**
     * @param  list<int>|null  $memberIds  null makes an open general room
     */
    function presenceWebRoom(string $name, ?array $memberIds = null, string $kind = 'match'): WebVoiceChannel
    {
        if ($memberIds === null) {
            return WebVoiceChannel::factory()->create(['name' => $name]);
        }

        $room = $kind === 'team'
            ? WebVoiceChannel::factory()->create(['name' => $name, 'kind' => ChannelKind::Team])
            : WebVoiceChannel::factory()->match()->create(['name' => $name]);
        $room->members()->sync($memberIds);

        return $room;
    }

    function presencePeopleUrl(Event $event, string $suffix = ''): string
    {
        return "/api/voice-client/v1/events/{$event->slug}/people{$suffix}";
    }
}
