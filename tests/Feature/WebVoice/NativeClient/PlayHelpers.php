<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Support\Str;

/** Shared helpers of the native client "play" API tests (required by each test file). */
if (! function_exists('playBearer')) {
    /** @return array<string, string> */
    function playBearer(User $user): array
    {
        $plain = 'lvc_'.Str::random(48);
        $token = new VoiceClientToken;
        $token->forceFill([
            'user_id' => $user->id,
            'device_name' => 'Test PC',
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(90),
        ])->save();

        return ['Authorization' => 'Bearer '.$plain];
    }

    function playEvent(): Event
    {
        return Event::factory()->live()->create();
    }

    function playRegister(User $user, Event $event): EventRegistration
    {
        return EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);
    }

    /** A solo entry of `$user` in `$tournament`. */
    function playEntry(Tournament $tournament, User $user, ?string $name = null): TournamentEntry
    {
        return TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'display_name' => $name ?? 'Player '.$user->id,
        ]);
    }

    /** A match between two users' solo entries in a live tournament of `$event`. */
    function playMatch(Event $event, User $me, User $them, MatchStatus $status = MatchStatus::Ready, ?Tournament $tournament = null): GameMatch
    {
        $tournament ??= Tournament::factory()->live()->create(['event_id' => $event->id, 'name' => 'Duell Cup']);
        $mine = playEntry($tournament, $me, 'Meine Crew');
        $theirs = playEntry($tournament, $them, 'Die Anderen');

        $match = GameMatch::factory()->create([
            'tournament_id' => $tournament->id,
            'entry1_id' => $mine->id,
            'entry2_id' => $theirs->id,
        ]);
        $match->forceFill(['status' => $status])->save();

        return $match->refresh();
    }
}
