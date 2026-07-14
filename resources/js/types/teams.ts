export interface TeamSummary {
    id: number;
    name: string;
    tag: string;
    logoUrl: string | null;
    memberCount: number;
}

export interface TeamMemberDto {
    id: number;
    role: 'owner' | 'member';
    user: { id: number; name: string };
}

export interface TeamJoinRequestDto {
    id: number;
    message: string | null;
    user: { id: number; name: string };
}

export interface TeamDetail extends TeamSummary {
    owner: { id: number; name: string };
    members: TeamMemberDto[];
}

export interface TeamEditDetail extends TeamDetail {
    joinRequests: TeamJoinRequestDto[];
}
