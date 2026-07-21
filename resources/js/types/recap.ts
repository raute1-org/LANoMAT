/**
 * The wire shape produced by `RecapProjection::forEvent()->toArray()`
 * (`RecapBoard::toArray()`) — shared by the public recap page
 * (`Recap/Show.vue`) and the infoscreen's Recap scene. Already public/no-PII:
 * `topPhotos[].url` points at the public-only `gallery.photos.public.thumb`
 * route, and `podiums`/`topPhotos` carry only display names/captions, never
 * ids or uploader/participant identities beyond a tournament winner's public
 * name.
 */
export interface RecapPodiumDto {
    tournamentName: string;
    winnerName: string;
}

export interface RecapPhotoDto {
    url: string;
    caption: string | null;
}

export interface RecapBoardDto {
    participantCount: number;
    tournamentCount: number;
    matchesPlayed: number;
    songsPlayed: number | null;
    podiums: RecapPodiumDto[];
    topPhotos: RecapPhotoDto[];
    mvp: { name: string } | null;
}
