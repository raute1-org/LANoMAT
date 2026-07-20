import { useEchoPublic } from '@laravel/echo-vue';
import { onUnmounted, reactive } from 'vue';

export interface MatchScoreState {
    team1?: string;
    team2?: string;
    score1?: number;
    score2?: number;
    round?: number;
}

interface MatchScoreUpdatedPayload {
    tournament_id: number;
    match_id: number;
    team1: string;
    team2: string;
    score1: number;
    score2: number;
    round: number;
}

/**
 * Subscribes to the public `tournament.{tournamentId}` broadcast channel
 * (same channel/auth rule as `useTournamentChannel` — no auth needed, the
 * bracket/score data is already public) and captures every
 * `MatchScoreUpdated` (`.match.score_updated`) broadcast that matches
 * `matchId` directly into the returned reactive state.
 *
 * Deliberately does NOT call `router.reload()` like `useTournamentChannel`
 * does: the CS2 live `round` counter is carried only in the broadcast
 * payload, never persisted on {@see GameMatch} (see
 * `BroadcastScoreboardOnScoreUpdated`, which feeds the beamer's
 * `SceneScoreboard` scene the same way), so a reload would simply lose it
 * again on the next request. Capturing the payload into local reactive state
 * is therefore the only way this overlay can show the round at all.
 *
 * A tournament can carry more than one match/game in flight, so events for
 * other matches on the same channel are ignored via the `match_id` filter.
 */
export function useMatchScore(
    tournamentId: number,
    matchId: number,
): MatchScoreState {
    const state = reactive<MatchScoreState>({});

    const { stopListening, leaveChannel } =
        useEchoPublic<MatchScoreUpdatedPayload>(
            `tournament.${tournamentId}`,
            ['.match.score_updated'],
            (payload) => {
                if (payload.match_id !== matchId) {
                    return;
                }

                state.team1 = payload.team1;
                state.team2 = payload.team2;
                state.score1 = payload.score1;
                state.score2 = payload.score2;
                state.round = payload.round;
            },
        );

    onUnmounted(() => {
        stopListening();
        leaveChannel();
    });

    return state;
}
