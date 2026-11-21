import type { MyFoodPayment } from '@/types/catering';
import type { ServerInstallDto } from '@/types/gameservers';
import type { HomeChatData } from '@/types/home';

export type NowState = 'live' | 'upcoming' | 'none';

export interface NowCardDto {
    kind: 'match' | 'vote' | 'order' | 'schedule' | 'notification' | 'payment';
    id: string;
    state: string;
    title: string;
    body: string | null;
    at: string | null;
    data: Record<string, unknown>;
    href: string | null;
}

export interface NowJoin {
    serverId: number;
    title: string;
    connectString: string;
    connectIsLink: boolean;
    address: string | null;
    port: number | null;
    players: number;
    maxPlayers: number;
    /** The matching recipe: install page and launcher join link. */
    install?: ServerInstallDto | null;
}

export interface NowPlayingRow {
    game: string;
    people: number;
    servers: number;
    players: number;
    maxPlayers: number;
    join: NowJoin | null;
    /** Set when the row has no join server but still a matching recipe. */
    install?: ServerInstallDto | null;
}

export interface WantToPlayGame {
    game: string;
    count: number;
    names: string[];
    mine: boolean;
}

export interface WantToPlayDto {
    games: WantToPlayGame[];
    suggestions: string[];
    hours: number;
    toggleUrl: string;
}

export interface FoodNowDto {
    open: null | {
        title: string;
        closesAt: string | null;
        orderCount: number;
        mine: string | null;
        payment: MyFoodPayment | null;
        orderable: boolean;
        href: string;
    };
    next: null | { title: string; opensAt: string };
}

export interface ScheduleItemDto {
    title: string;
    startsAt: string;
    endsAt: string | null;
}

export interface PermanentShortDto {
    id: number;
    title: string;
    game: string | null;
    status: string;
    players: number | null;
    maxPlayers: number | null;
    connectString: string;
    connectIsLink: boolean;
    /** The matching recipe: install page and launcher join link. */
    install?: ServerInstallDto | null;
}

export interface NowEvent {
    id: number;
    name: string;
    slug: string;
    status: string;
    startsAt: string | null;
    endsAt: string | null;
    location: string | null;
    availability: string;
    freeSpots: number | null;
    checkedInCount: number;
    registeredCount: number;
}

export interface NowNewsItem {
    id: number;
    title: string;
    publishedAt: string | null;
}

export interface NowProps {
    state: NowState;
    event: NowEvent | null;
    forYou: { cards: NowCardDto[]; more: NowCardDto[] };
    nowPlaying?: NowPlayingRow[] | null;
    wantToPlay?: WantToPlayDto | null;
    food: FoodNowDto | null;
    schedule: null | {
        current: ScheduleItemDto | null;
        upcoming: ScheduleItemDto[];
    };
    me: null | {
        registration: { checkedIn?: boolean } | null;
        seat: string | null;
        team: string | null;
        steamLinked: boolean;
    };
    news: NowNewsItem[];
    permanent: PermanentShortDto[] | null;
    chat: HomeChatData;
    labels: Record<string, string>;
    clientHint: Record<string, string>;
}

/** Shared Inertia prop `navSignals`: live hints for the sidebar (null for guests). */
export interface NavSignals {
    serversOnline: number | null;
    tournament: 'ready' | 'checkin' | null;
    foodClosesAt: string | null;
    seat: string | null;
    present: number | null;
    registered?: boolean | null;
}
