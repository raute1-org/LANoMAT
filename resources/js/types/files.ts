export interface SharedFileDto {
    id: number;
    originalName: string;
    sizeBytes: number;
    uploaderName: string | null;
    visibility: 'pending' | 'approved' | 'rejected';
    createdAt: string;
    mine: boolean;
}
