import { router } from '@inertiajs/vue3';
import { useEchoPublic } from '@laravel/echo-vue';
import { onUnmounted } from 'vue';
import { useResync } from '@/composables/useResync';

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
 * `MatchReportUpdated` -> `match.report_updated` (a result was submitted or
 * disputed; the opponent's page then shows the pending report) and
 * `TournamentStarted` -> `tournament.started` (so a page opened before the
 * start flips to the bracket even if no `match.ready` reaches it).
 * Missed signals are recovered by {@see useResync} (reconnect / tab visible).
 * Custom broadcast names are listened to with a leading dot so Echo does not
 * namespace them under the event's fully-qualified class name.
 */
/** Everything a bracket-affecting change can alter on the tournament page. */
const LIVE_PROPS = ['matches', 'tournament', 'pendingReports', 'standings'];

export function useTournamentChannel(tournamentId: number): {
    connection: ReturnType<typeof useResync>['status'];
} {
    const reload = () => router.reload({ only: LIVE_PROPS });
    const { status } = useResync(reload);

    const { stopListening, leaveChannel } = useEchoPublic(
        `tournament.${tournamentId}`,
        [
            '.tournament.started',
            '.match.report_updated',
            '.match.ready',
            '.match.went_live',
            '.match.completed',
            '.match.score_updated',
            '.tournament.completed',
        ],
        reload,
    );

    onUnmounted(() => {
        stopListening();
        leaveChannel();
    });

    return { connection: status };
}
