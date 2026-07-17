import { router } from '@inertiajs/vue3';
import { useEchoPublic } from '@laravel/echo-vue';
import { onUnmounted } from 'vue';

/**
 * Subscribes to the public `tournament.{id}` broadcast channel (see
 * `routes/channels.php` — no auth needed, the bracket view is public) and
 * reloads the `matches`/`tournament` Inertia props whenever the backend
 * reports a bracket-affecting change.
 *
 * Event names must match each event class's `broadcastAs()` exactly
 * (Task 12): `MatchReady` -> `match.ready`, `MatchCompleted` ->
 * `match.completed`, `TournamentCompleted` -> `tournament.completed`,
 * `MatchWentLive` -> `match.went_live` (Task 11 — the warmup->go gate),
 * `MatchScoreUpdated` -> `match.score_updated` (Task 12 — CS2 live-stats
 * telemetry, roadmap 6.9): reloading on this event is what puts a live score
 * on the match page, since `score1`/`score2` are already part of
 * `BracketMatchDto` (`BracketMatchProjection`).
 * Custom broadcast names are listened to with a leading dot so Echo does not
 * namespace them under the event's fully-qualified class name.
 */
export function useTournamentChannel(tournamentId: number): void {
    const { stopListening, leaveChannel } = useEchoPublic(
        `tournament.${tournamentId}`,
        [
            '.match.ready',
            '.match.went_live',
            '.match.completed',
            '.match.score_updated',
            '.tournament.completed',
        ],
        () => {
            router.reload({ only: ['matches', 'tournament'] });
        },
    );

    onUnmounted(() => {
        stopListening();
        leaveChannel();
    });
}
