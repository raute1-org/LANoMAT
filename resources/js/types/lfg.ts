export interface LfgPostDto {
    id: number;
    game: string | null;
    title: string;
    body: string | null;
    slotsNeeded: number | null;
    userName: string | null;
    expiresAt: string;
    mine: boolean;
}
