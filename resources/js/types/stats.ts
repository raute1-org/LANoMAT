/**
 * The wire shape produced by `LeaderboardQuery::topEntrants()` — one row per
 * competitor (either a `Team` or a `User`, never both, see the query's doc),
 * already sorted by wins descending and capped to the requested limit.
 */
export interface LeaderboardRowDto {
    id: number;
    type: 'user' | 'team';
    name: string;
    wins: number;
    tournamentWins: number;
    participations: number;
    podiums: number;
    badges: string[];
}
