/**
 * The wire shape produced by `GalleryPageController::index()` — the
 * participant gallery page (`Gallery/Index`). `thumbUrl`/`fullUrl` are
 * server-generated (`route('gallery.photos.thumb'|'show', id)`) and already
 * point at the auth-gated `PhotoController` routes; never construct these
 * client-side.
 */
export interface GalleryPhotoDto {
    id: number;
    thumbUrl: string;
    fullUrl: string;
    caption: string | null;
    uploaderName: string | null;
    visibility: 'pending' | 'approved' | 'rejected';
    mine: boolean;
    isHighlight: boolean;
}
