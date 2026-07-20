/**
 * The wire shape produced by `JukeboxController::index()` — the participant
 * jukebox board (`Jukebox/Index`). `nowPlaying` is `null` when nothing is
 * currently playing; `queue` is already vote-ordered (most up-votes first,
 * tiebreak earliest) by `JukeboxQueue::upcoming()`, so array position itself
 * carries meaning — never re-sort it client-side.
 */
export interface NowPlayingDto {
    id: number;
    title: string;
    artist: string | null;
    imageUrl: string | null;
    durationSeconds: number | null;
}

export interface QueueItemDto {
    id: number;
    title: string;
    artist: string | null;
    imageUrl: string | null;
    voteCount: number;
    hasVoted: boolean;
    addedByName: string | null;
}

/**
 * One Music Assistant search hit, returned by `jukebox.search` — not yet
 * queued, so it carries a `uri` (the add payload's key) rather than an `id`.
 */
export interface TrackSearchResultDto {
    uri: string;
    title: string;
    artist: string | null;
    durationSeconds: number | null;
    imageUrl: string | null;
}
