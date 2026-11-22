/**
 * The RAM readout for a server (roadmap 6.7) — the same numbers
 * `GuardrailPolicy`/`ResourceEstimate` enforce server-side, shown here as a
 * calm, ongoing footprint readout rather than a pre-start moment (this list
 * is Ready-only).
 */
export interface GameServerResourceEstimate {
    ramMb: number;
    maxRamMb: number;
    overCap: boolean;
}

/**
 * The game's "So kommst du ran" install hint (roadmap 7.5): how to get the
 * game installed ahead of/at the LAN — a `steam://` deeplink, a share link
 * into the Files service, and/or a free-text version/modpack note. Null
 * fields are simply not shown; a wholly-empty hint is projected as `null` on
 * `GameServerDto.installHint`.
 */
export interface GameInstallHintDto {
    steamUrl: string | null;
    shareUrl: string | null;
    versionNote: string | null;
}

/**
 * How a player gets a server's game onto their machine: the published recipe
 * that matches the server's game, its install page and — when the launcher can
 * run it — the one-click deep link that installs the game when missing and
 * starts it on that server. Absent or `null` when no recipe matches.
 */
export interface ServerInstallDto {
    recipeSlug: string;
    title: string;
    /** `/games/<slug>` — the install page, also the "Mitspieler suchen" target. */
    pageUrl: string;
    launcherSupported: boolean;
    /** `lanomat://join/<slug>?address=…` or `?url=…`; only when supported. */
    launcherJoinUrl: string | null;
    /**
     * Whether the game's own files are on the LAN right now, so it can be
     * installed fresh: every required base archive or disc image is an approved
     * upload or names an external download. False means "install fresh does
     * not work at the moment"; the launcher join above stays, because a PC that
     * already has the game can still join.
     */
    lanReady: boolean;
}

/**
 * The wire shape produced by `ServerListProjection::forEvent()` — shared by
 * the public server list page (`Servers/Index`) and the infoscreen's
 * Servers scene (`SceneServers.vue`). Ready servers, not-responding ones and
 * event-level servers that are still starting are produced (see the projection's doc), but `status` still carries the full lifecycle
 * value so both surfaces can drive the same `LiveIndicator` mapping.
 */
export interface GameServerQueryDto {
    serverName: string;
    map: string;
    players: number;
    maxPlayers: number;
    checkedAt: string | null;
    /** In-game nicknames, only sent to logged-in viewers of the server pages. */
    playerList?: GameServerPlayerDto[];
}

/** One entry of the "who is on the server" list (a game nickname, not a LANoMAT account). */
export interface GameServerPlayerDto {
    name: string;
    score: number | null;
    seconds: number | null;
}

/** One way to join a server (e.g. via Steam or a native install), built server-side. */
export interface JoinOptionDto {
    key: string;
    label: string;
    url: string;
    hint: string;
}

/**
 * One account Presence places on a server right now, as the viewer may see them
 * (`share_location` on, not blocked, or the viewer themselves).
 */
export interface ServerOccupantDto {
    id: number;
    name: string;
    /** The local avatar route the people page renders; null = monogram. */
    avatarUrl: string | null;
}

/**
 * Who of the LANoMAT accounts is on this server, from the Presence read model
 * (`ServerOccupants`). Absent or `null` while Presence is off or nobody is logged in;
 * `people` holds at most eight accounts by name and `more` counts further *visible*
 * accounts. Somebody who hides their location is in neither.
 */
export interface ServerOccupantsDto {
    people: ServerOccupantDto[];
    more: number;
}

export interface GameServerDto {
    id: number;
    game: string | null;
    matchLabel: string | null;
    address: string | null;
    port: number | null;
    connectString: string;
    status:
        | 'pending'
        | 'provisioning'
        | 'ready'
        | 'failed'
        | 'stopped'
        | 'not_responding'
        | 'install_failed'
        | 'offline';
    /** Orga-chosen name of an event-level preset server, null for match servers. */
    title?: string | null;
    /** False when the connect string is a plain address:port (no URL scheme). */
    connectIsLink?: boolean;
    /** Last server query answer (name, map, players); null when never queried. */
    query?: GameServerQueryDto | null;
    slotsUsed?: number;
    slotsMax?: number;
    /** Permanent servers only: free text from the admin (how to join, rules). */
    description?: string | null;
    estimate: GameServerResourceEstimate | null;
    installHint: GameInstallHintDto | null;
    /** The recipe that gets this game onto a machine; null when none matches. */
    install?: ServerInstallDto | null;
    /** Who of the LANoMAT accounts is on it; null while Presence is off or nobody is logged in. */
    occupants?: ServerOccupantsDto | null;
    /** Per-game join options; empty for games without templates. */
    joinOptions?: JoinOptionDto[];
    /** Event servers on the overview: the viewer plays the match this server belongs to. */
    isMine?: boolean;
    /** Permanent servers page only: set server-side by the policy, never derived in the client. */
    canManage?: boolean;
    /** Only present when `canManage` is true. */
    admin?: {
        gunGame: boolean;
        canReloadMap: boolean;
        running: boolean;
        /** Only for a server controlled as a container on a remote host. */
        remote?: {
            quickCommands: string[];
            labels: Record<string, string>;
            quickLabels: Record<string, string>;
        };
    };
}

/**
 * One row of the global server browser (`Servers/Global`, `ServerListProjection::forGlobalBrowser()`):
 * the event lists of all publicly visible, non-archived events plus the permanent
 * servers, each tagged with the event it belongs to (`null` for permanent servers).
 */
export interface GlobalServerRowDto extends GameServerDto {
    /** The event whose page links back here; null for a permanent server. */
    event: { name: string; slug: string } | null;
    /** True for a permanent server (runs all year, independent of an event). */
    permanent: boolean;
    /** Permanent servers only: Pelican, Docker or external (`ServerSource`). */
    source?: string;
}
