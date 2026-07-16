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
}
