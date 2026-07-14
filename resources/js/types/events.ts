export interface EventSummary {
    name: string;
    slug: string;
    status:
        | 'draft'
        | 'announced'
        | 'registration'
        | 'live'
        | 'finished'
        | 'archived';
    startsAt: string | null;
    endsAt: string | null;
    location: string | null;
}
