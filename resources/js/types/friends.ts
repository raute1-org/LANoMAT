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
