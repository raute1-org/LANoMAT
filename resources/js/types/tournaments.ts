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

/** A `mumble://` deep link to the viewer's own match voice channel, or null if none has been provisioned (yet, or any more). */
export type MatchVoiceLink = string | null;

export type MatchStatusValue =
    'pending' | 'ready' | 'warmup' | 'reported' | 'disputed' | 'completed';

export type ServerLinkStatusValue =
    'pending' | 'provisioning' | 'ready' | 'failed' | 'stopped';

/**
 * The pre-start RAM readout for a match's game server (roadmap 6.7),
 * populated only while the server isn't Ready yet and only when the
 * tournament's game can be estimated from — mirrors the exact numbers
 * `GuardrailPolicy`/`ResourceEstimate` enforce server-side, so this display
 * can never drift from what's actually allowed.
 */
export interface ServerResourceEstimate {
    ramMb: number;
    maxRamMb: number;
    overCap: boolean;
}

/**
 * The match's provisioned game server, or null when no ServerLink exists yet
 * (manual mode with nothing set, or the tournament's game has no Pelican
 * egg). `address`/`port`/`connectString` are only populated once `status` is
 * `'ready'` — while Provisioning/Failed, only `status` (and `estimate`) is
 * meaningful.
 */
export interface MatchServerDto {
    address: string | null;
    port: number | null;
    connectString: string | null;
    status: ServerLinkStatusValue;
    estimate: ServerResourceEstimate | null;
}

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
    server: MatchServerDto | null;
    /** Set once the match enters warmup (Task 11); null before/after. */
    warmupStartedAt: string | null;
}
