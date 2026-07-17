<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Http;

use App\Modules\GameServers\Events\MatchScoreUpdated;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2TelemetryMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The CS2 live-stats webhook (roadmap 6.9): a MatchZy/G5API game server POSTs
 * round/score events here as they happen. Token-guarded — see
 * {@see authorize()} — because this is a public, unauthenticated (no
 * Laravel session) endpoint the game server itself calls; a per-`ServerLink`
 * bearer token (`ServerLink::$telemetry_token`, lazily generated on creation)
 * is the credential, compared in constant time via `hash_equals()` to
 * resist timing attacks.
 *
 * A bad/missing token is rejected outright (401/403) before touching the
 * payload at all. Once past the token check, an unrecognized payload shape
 * (wrong `event`, missing fields — see {@see Cs2TelemetryMapper}) is
 * "ignored gracefully": 204 No Content, no crash, no event dispatched. Only
 * a payload with no recognizable shape whatsoever also returns 204 unless
 * the request body isn't even a JSON object, in which case 422 signals a
 * genuinely malformed request (still never a 500).
 *
 * "Honest scope" (roadmap 6.9): this is a per-game recipe, only where
 * telemetry exists — not a universal live-stats claim.
 */
class MatchTelemetryController
{
    public function __invoke(Request $request, ServerLink $serverLink): Response
    {
        $this->authorize($request, $serverLink);

        $payload = $request->json()->all();

        if (! isset($payload['event'])) {
            // Valid JSON but not even MatchZy's basic envelope shape (no
            // `event` key at all) — genuinely malformed from this webhook's
            // point of view, so 422 rather than the softer 204 used for a
            // recognized-but-uninteresting event below.
            return response()->noContent(SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $match = $serverLink->match()->first();

        if ($match === null) {
            // No match associated with this ServerLink (e.g. a
            // tournament-only link, or one not yet attached to a match) —
            // nothing to update, but still not an error from the game
            // server's point of view.
            return response()->noContent();
        }

        $mapped = Cs2TelemetryMapper::map($payload);

        if ($mapped === null) {
            // Recognized as JSON but not a score-carrying MatchZy event
            // (e.g. a player_death/round_start event this recipe doesn't
            // track) — ignored gracefully, not an error.
            return response()->noContent();
        }

        $match->forceFill([
            'score1' => $mapped['score1'],
            'score2' => $mapped['score2'],
        ])->save();

        MatchScoreUpdated::dispatch(
            $match,
            $mapped['team1'],
            $mapped['team2'],
            $mapped['score1'],
            $mapped['score2'],
            $mapped['round'],
        );

        return response()->noContent();
    }

    private function authorize(Request $request, ServerLink $serverLink): void
    {
        $token = $serverLink->telemetry_token;
        $provided = $request->bearerToken();

        if ($token === null || $provided === null) {
            abort(401);
        }

        if (! hash_equals($token, $provided)) {
            abort(403);
        }
    }
}
