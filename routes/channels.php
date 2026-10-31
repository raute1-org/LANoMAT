<?php

use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Tournament bracket updates (`TournamentStarted`, `MatchReady`,
// `MatchCompleted`, `TournamentCompleted`) broadcast on the public
// `tournament.{id}` channel — no authorization callback is registered here
// because the bracket view is public and requires no auth. If a
// participant-only or orga-only stream is wanted later (e.g. dispute
// details), switch that channel to a PresenceChannel/PrivateChannel and add
// a Broadcast::channel(...) callback here.

// Poll results (`PollUpdated`) broadcast on the public `event.{id}` channel
// — no authorization callback is registered here because poll results are
// public, mirroring the `tournament.{id}` channel above.

// Infoscreen (`SceneOverride` -> 'scene.override', a future scene-list
// change -> 'scenes.updated') also broadcast on the public `event.{id}`
// channel — no authorization callback is registered here because the
// beamer screen page is public and its payloads carry no private data,
// mirroring `PollUpdated` above.

// Discord voice occupancy (`DiscordVoicePresenceUpdated` -> 'voice.updated')
// broadcasts on the public `discord-voice` channel — no authorization
// callback (payload is empty, consumers pull VoicePresenceProjection).

// Web voice chat (WebVoice module). Private channels, authorized with the same policy that gates
// the HTTP endpoints, so the websocket can never show more than the page does:
// `web-voice.channel.{id}` carries the chat messages of one channel (view ability: general
// channel or roster snapshot, admins everywhere, archived nowhere); `web-voice.directory` carries
// an empty "your channel list changed" ping and is open to any logged-in user.
if (app(Modules::class)->enabled(Module::WebVoice)) {
    Broadcast::channel('web-voice.channel.{channelId}', function ($user, $channelId) {
        $channel = WebVoiceChannel::query()->find($channelId);

        return $channel !== null && Gate::forUser($user)->allows('view', $channel);
    });

    Broadcast::channel('web-voice.directory', fn ($user) => true);
}

// Public event/tournament/discord-voice channels carry no auth callback; nothing to gate.
