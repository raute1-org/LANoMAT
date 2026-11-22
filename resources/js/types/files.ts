export interface SharedFileDto {
    id: number;
    originalName: string;
    sizeBytes: number;
    uploaderName: string | null;
    visibility: 'pending' | 'approved' | 'rejected';
    createdAt: string;
    mine: boolean;
    /**
     * Whether a shared game can be built from this file (see
     * FilePageController::fileDto) — the recipe catalogue's archive rule,
     * answered by the server. False while the game recipes module is off.
     */
    shareableAsGame: boolean;
}

/**
 * The viewer's per-event upload quota (see FilePageController::quotaDto) —
 * null for a guest, who has nothing to upload against.
 */
export interface FileQuotaDto {
    usedBytes: number;
    capBytes: number;
}

/**
 * How the Files page uploads (see FilePageController::uploadDto): `resumable`
 * sends chunks straight to the file host with a ticket from `ticketUrl`,
 * `form` is the legacy post through the app.
 */
export interface FileUploadConfigDto {
    mode: 'resumable' | 'form';
    maxFileBytes: number;
    ticketUrl: string | null;
}
