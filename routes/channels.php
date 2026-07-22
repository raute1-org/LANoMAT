<?php

use Illuminate\Support\Facades\Broadcast;

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
