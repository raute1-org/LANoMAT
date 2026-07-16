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
 * The wire shape produced by `ServerListProjection::forEvent()` — shared by
 * the public server list page (`Servers/Index`) and the infoscreen's
 * Servers scene (`SceneServers.vue`). Only Ready servers are ever produced
 * (see the projection's doc), but `status` still carries the full lifecycle
 * value so both surfaces can drive the same `LiveIndicator` mapping.
 */
export interface GameServerDto {
    id: number;
    game: string | null;
    matchLabel: string | null;
    address: string | null;
    port: number | null;
    connectString: string;
    status: 'pending' | 'provisioning' | 'ready' | 'failed' | 'stopped';
    slotsUsed?: number;
    slotsMax?: number;
    estimate: GameServerResourceEstimate | null;
}
