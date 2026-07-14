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
