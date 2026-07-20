export type SceneType =
    | 'bracket'
    | 'upcoming_matches'
    | 'schedule'
    | 'announcement'
    | 'seatmap'
    | 'payment_qr'
    | 'sponsors'
    | 'tombola'
    | 'status'
    | 'servers'
    | 'presence'
    | 'now_playing'
    | 'winner'
    | 'gong'
    | 'scoreboard';

/**
 * The wire shape produced by `ScenePayload::for()` — the single scene ->
 * wire projection shared by the initial page load (`scenes` prop) and the
 * `scene.override` broadcast. `config` mirrors `SceneConfig::toArray()`
 * (snake_case keys, only the ones set). `data` carries type-specific
 * derived data filled in by later tasks; it is always present but may be
 * empty.
 */
export interface ScenePayloadDto {
    id: number;
    type: SceneType;
    durationSec: number;
    config: Record<string, unknown>;
    data: Record<string, unknown>;
}

/**
 * The public track metadata subset produced by `ScenePayload::nowPlayingData()`
 * — deliberately narrower than the Jukebox module's own `NowPlayingDto`/
 * `QueueItemDto` (see `@/types/jukebox`): no vote counts, no `addedByName`, no
 * ids. This is a public, unauthenticated beamer surface.
 */
export interface NowPlayingTrackDto {
    title: string;
    artist: string | null;
    imageUrl: string | null;
}
