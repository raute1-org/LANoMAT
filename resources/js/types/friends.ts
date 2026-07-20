export interface FriendUserDto {
    id: number;
    name: string;
    avatarUrl: string | null;
}

export interface IncomingRequestDto {
    friendshipId: number;
    from: FriendUserDto;
}

export interface OutgoingRequestDto {
    friendshipId: number;
    to: FriendUserDto;
}

export type SuggestionReason =
    'shared_event' | 'shared_team' | 'shared_tournament';

export interface SuggestionDto extends FriendUserDto {
    shared: number;
    reasons: SuggestionReason[];
}

/**
 * The authenticated viewer's relationship to a profile's owner, resolved
 * server-side in ProfileController@show. Absent (null) for guests — no
 * friend/block controls are shown to unauthenticated visitors.
 */
export type RelationshipState =
    | 'self'
    | 'friends'
    | 'request_sent'
    | 'request_received'
    | 'none'
    | 'blocked';

export interface RelationshipDto {
    state: RelationshipState;
    friendshipId?: number;
}
