export interface EventHypePollTeaser {
    id: number;
    question: string;
}

export interface EventHype {
    startsAt: string;
    registrationCount: number;
    activePoll: EventHypePollTeaser | null;
}

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
    arrivalInfo: string | null;
    hype: EventHype | null;
}
