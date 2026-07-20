/**
 * The wire shape produced by `PresenceProjection::forEvent()->toArray()` —
 * shared by the participant presence page (`Presence/Index`) and the
 * infoscreen's Presence scene. `activity` is `null` when idle (checked in but
 * not currently in a live match); `openSpots` is `null` when a tournament has
 * no `max_entries` cap ("offen, keine feste Grenze") but is still joinable.
 */
export interface ParticipantPresenceDto {
    registrationId: number;
    name: string;
    avatarUrl: string | null;
    seatLabel: string | null;
    activity: string | null;
    isPlaying: boolean;
}

export interface FreeSlotDto {
    tournamentId: number;
    name: string;
    game: string | null;
    openSpots: number | null;
}

export interface LiveMatchPresenceDto {
    matchId: number;
    game: string | null;
    label: string;
    players: string[];
}

export interface PresenceBoardDto {
    participants: ParticipantPresenceDto[];
    freeSlots: FreeSlotDto[];
    liveMatches: LiveMatchPresenceDto[];
    checkedInCount: number;
}
