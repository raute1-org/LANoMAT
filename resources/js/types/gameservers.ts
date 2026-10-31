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
        | 'install_failed';
    /** Orga-chosen name of an event-level preset server, null for match servers. */
    title?: string | null;
    /** False when the connect string is a plain address:port (no URL scheme). */
    connectIsLink?: boolean;
    /** Last server query answer (name, map, players); null when never queried. */
    query?: GameServerQueryDto | null;
    slotsUsed?: number;
    slotsMax?: number;
    estimate: GameServerResourceEstimate | null;
    installHint: GameInstallHintDto | null;
}
