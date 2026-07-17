export interface SharedFileDto {
    id: number;
    originalName: string;
    sizeBytes: number;
    uploaderName: string | null;
    visibility: 'pending' | 'approved' | 'rejected';
    createdAt: string;
    mine: boolean;
}

/**
 * The viewer's per-event upload quota (see FilePageController::quotaDto) —
 * null for a guest, who has nothing to upload against.
 */
export interface FileQuotaDto {
    usedBytes: number;
    capBytes: number;
}
