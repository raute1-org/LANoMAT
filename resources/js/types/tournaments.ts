export interface TournamentSummary {
    id: number;
    name: string;
    format: 'single_elimination' | 'double_elimination' | 'round_robin';
    status: 'draft' | 'enrollment' | 'check_in' | 'live' | 'finished';
    startsAt: string | null;
}

export interface TournamentDetail {
    id: number;
    name: string;
    format: 'single_elimination' | 'double_elimination' | 'round_robin';
    status: 'draft' | 'enrollment' | 'check_in' | 'live' | 'finished';
    event: { name: string; slug: string };
    winnerEntryId: number | null;
}

export type MatchStatusValue =
    'pending' | 'ready' | 'reported' | 'disputed' | 'completed';

export interface BracketMatchDto {
    id: number;
    round: number;
    bracket: 'winners' | 'losers' | 'finals';
    position: number;
    nextMatchId: number | null;
    nextSlot: number | null;
    slot1: string | null;
    slot2: string | null;
    entry1Id: number | null;
    entry2Id: number | null;
    score1: number | null;
    score2: number | null;
    winnerEntryId: number | null;
    status: MatchStatusValue;
    lockVersion: number;
}
