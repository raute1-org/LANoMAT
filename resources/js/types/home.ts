/**
 * Wire shape of the start page's chat hero (see
 * `App\Modules\Events\Support\HomeChatData`). The data is small and bounded —
 * the chat itself is deterministic browser logic, not an LLM.
 */

export interface HomeChatGame {
    slug: string;
    title: string;
    summary: string | null;
    version: string | null;
    /** A required LAN file is present, or a server for this game is running. */
    lan_ready: boolean;
    server_count: number;
    installable: boolean;
    /**
     * Whether the launcher can install this game today (pilot: UT2004 only).
     * Only these cards offer the primary "Installieren" action; everything
     * else links to its game page instead.
     */
    launcher_supported: boolean;
    /** A browser recipe's entry point; it has no install path. */
    play_url: string | null;
    /** Short browser-server line ("Ein Server · Nickname · Join/Leave"). */
    server_note: string | null;
    /** `/launcher` when a build is published, otherwise null. */
    launcher_url: string | null;
    /** `lanomat://install/{slug}` — opens the launcher when it is installed. */
    install_deeplink: string;
}

export interface HomeChatServer {
    title: string;
    game: string | null;
    map: string | null;
    players: number | null;
    max: number | null;
    status: string;
    /** Per-game join deep link, when the game offers one. */
    join_url: string | null;
    /** Set for event/tournament servers; null for permanent ones. */
    event_slug: string | null;
}

export interface HomeChatData {
    greeting: string;
    suggestions: string[];
    games: HomeChatGame[];
    servers: HomeChatServer[];
}
