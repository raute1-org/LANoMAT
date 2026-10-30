/**
 * Advisory-only game-ownership signal for the enrollment CTA (M9 task 9.7):
 * `not_owned` renders a calm warning, `unknown` (no provider mapping, no
 * linked account, private profile, ...) and `owned` render nothing extra.
 * NEVER disables the enroll button — see GameOwnershipHint's docblock.
 */
export type OwnershipHintStatus = 'owned' | 'not_owned' | 'unknown';

/** The viewer's own active entry; `canWithdraw` mirrors TournamentPolicy::withdraw. */
export interface TournamentMyEntry {
    id: number;
    status: 'registered' | 'checked_in';
    canWithdraw: boolean;
    /** False for a plain team member: entered, but nothing to act on. */
    isOwner: boolean;
}

/** Ruling A: whether the viewer must register for the event before entering tournaments. */
export interface TournamentRegistrationGate {
    required: boolean;
    open: boolean;
    url: string;
}

/** Team picker for team tournaments (`teamSize > 1`); null on solo tournaments. */
export interface TournamentTeamPicker {
    teamSize: number;
    /** The viewer's own teams; `eligible` = roster has exactly `teamSize` members. */
    teams: {
        id: number;
        name: string;
        tag: string;
        memberCount: number;
        eligible: boolean;
    }[];
    createUrl: string;
}

export interface TournamentSummary {
    teamPicker: TournamentTeamPicker | null;
    id: number;
    name: string;
    format: 'single_elimination' | 'double_elimination' | 'round_robin';
    status: 'draft' | 'enrollment' | 'check_in' | 'live' | 'finished';
    startsAt: string | null;
    /** Non-withdrawn entries (people for solo, teams for team tournaments). */
    entryCount: number;
    maxEntries: number | null;
    teamSize: number;
    /** Null when the tournament has no game attached. */
    gameName: string | null;
    /** Null when not signed in or the tournament has no game attached. */
    ownershipHint: OwnershipHintStatus | null;
    myEntry: TournamentMyEntry | null;
}

export interface TournamentDetail {
    id: number;
    name: string;
    format: 'single_elimination' | 'double_elimination' | 'round_robin';
    status: 'draft' | 'enrollment' | 'check_in' | 'live' | 'finished';
    event: { name: string; slug: string };
    winnerEntryId: number | null;
    /** Champion's display name once the tournament is finished. */
    winnerName: string | null;
}

/**
 * The viewer's own join link for one active voice provider on their current
 * match — `provider` is the provider's config value (e.g. `"mumble"`),
 * `label` its display name, `url` a `mumble://`/`ts3server://` deep link,
 * and `isDefault` marks the installation/team's preferred provider (exactly
 * one entry, if any, has `isDefault: true`). `occupants` is the channel's
 * live headcount (issue #13); real numbers depend on the provider sidecars
 * running (mode A, deferred) — 0 until then.
 */
export interface MatchVoiceLinkItem {
    provider: string;
    label: string;
    url: string;
    isDefault: boolean;
    occupants: number;
}

/** Every active provider's join link for the viewer's current match, empty when no channel has been provisioned (yet, or any more). */
export type MatchVoiceLinks = MatchVoiceLinkItem[];

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

/**
 * The game's "So schaust du zu" spectate hint (M10 T8): how a spectator
 * watches a match — a GOTV/observer connect string, and/or free-text notes on
 * requesting an observer slot or on demo/replay availability. Null fields are
 * simply not shown; a wholly-empty hint is projected as `null` on
 * `BracketMatchDto.spectateHint`.
 */
export interface SpectateHintDto {
    gotvConnect: string | null;
    observerNote: string | null;
    replayNote: string | null;
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
    /** True when this match was won without being played (bracket padding). */
    isBye: boolean;
    lockVersion: number;
    server: MatchServerDto | null;
    /** Set once the match enters warmup (Task 11); null before/after. */
    warmupStartedAt: string | null;
    spectateHint: SpectateHintDto | null;
}

/**
 * The viewer's own pending (unconfirmed) result claim for a match, from the
 * viewer's perspective: `ownScore : opponentScore` — the same orientation as
 * the report form ("Ergebnis (du)" : "Ergebnis (Gegner)"). Only ever sent to
 * the two participants of the match.
 */
export interface PendingReportDto {
    reportedByMe: boolean;
    ownScore: number;
    opponentScore: number;
    reporterName: string | null;
    opponentName: string | null;
    reportedAt: string | null;
}

/** One row of the round-robin table (derived server-side by the pure standings calculator). */
export interface TournamentStandingRow {
    rank: number;
    entryId: number;
    name: string;
    played: number;
    wins: number;
    losses: number;
    pointsFor: number;
    pointsAgainst: number;
    scoreDifference: number;
}
