/**
 * The frozen contract of the share page (`GET /games/share`, page
 * `Games/Share`) — see docs/superpowers/plans/2026-11-21-game-sharing-swarm.md
 * § 3.2. The page renders exactly these props and posts exactly this body.
 */

export type GameSharePlatform = 'windows' | 'linux';

/** How a Linux build starts: through Wine or as its own program. */
export type GameShareLinuxRunner = 'native' | 'wine';

/** The visibility states a file the viewer may pick can have. */
export type GameSharePickableVisibility = 'pending' | 'approved';

/** The visibility states a file attached to an already shared game can have. */
export type GameShareSharedFileVisibility = 'pending' | 'approved' | 'rejected';

export type GameShareStatus = 'draft' | 'published' | 'archived';

/** The public event that currently takes uploads, or null between LANs. */
export interface GameShareEvent {
    name: string;
    slug: string;
}

/**
 * How the page uploads — the same switch as the files page. `storeUrl` is the
 * form-mode endpoint; `ticketUrl` feeds the resumable uploader.
 */
export interface GameShareUpload {
    mode: 'resumable' | 'form';
    ticketUrl: string | null;
    storeUrl: string;
    maxFileBytes: number;
}

export interface GameShareQuota {
    usedBytes: number;
    capBytes: number;
}

/** One of the viewer's own files of the current event. */
export interface GameShareFile {
    id: number;
    name: string;
    sizeBytes: number;
    visibility: GameSharePickableVisibility;
    createdAt: string;
    /** False when the name is not an allowed archive extension. */
    usable: boolean;
}

export interface GameShareSharedFile {
    name: string;
    visibility: GameShareSharedFileVisibility;
}

export interface GameSharePullRequest {
    number: number | null;
    url: string | null;
    state: string;
    label: string;
}

/** One game the viewer already shared. */
export interface GameShareShare {
    slug: string;
    title: string;
    status: GameShareStatus;
    statusLabel: string;
    inLineup: boolean;
    /** Set only when the recipe is published and the lineup allows it. */
    gameUrl: string | null;
    file: GameShareSharedFile | null;
    pullRequest: GameSharePullRequest | null;
    updatedAt: string;
}

export interface GameShareLimits {
    titleMax: number;
    summaryMax: number;
    executableMax: number;
    archiveExtensions: string[];
}

export interface GameSharePageProps {
    event: GameShareEvent | null;
    upload: GameShareUpload | null;
    quota: GameShareQuota | null;
    files: GameShareFile[];
    shares: GameShareShare[];
    /** From `?file=<id>` when that id is one of `files`. */
    selectedFileId: number | null;
    limits: GameShareLimits;
    /** `trans('files.page')`, for the resumable uploader. */
    uploadLabels: Record<string, string>;
}

/** What `POST /games/share` accepts (the server validates it again). */
export interface GameShareForm {
    title: string;
    shared_file_id: number | null;
    platforms: GameSharePlatform[];
    /** Path inside the unpacked folder, e.g. "bin/Game.exe". */
    executable: string;
    linux_runner: GameShareLinuxRunner | null;
    linux_executable: string;
    summary: string;
    version: string;
    homepage: string;
    players: string;
    multiplayer: boolean;
}
