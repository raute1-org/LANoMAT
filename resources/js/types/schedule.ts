export interface ScheduleItemDto {
    id: number;
    type: 'custom' | 'tournament' | 'catering' | 'break';
    typeLabel: string;
    title: string;
    description: string | null;
    startsAt: string;
    endsAt: string | null;
    location: string | null;
}
