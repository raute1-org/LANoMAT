/**
 * Shared types for the LANoMAT agent. The API shapes mirror the server contract documented in
 * `README.md`; unknown extra fields are preserved by the parser but never required.
 */

export type Os = 'windows' | 'linux';

export interface StoredUser {
    id: string | number;
    name: string;
}

/** `~/.config/lanomat/agent.json`. The token is a secret and is never printed by the CLI. */
export interface ModelRoles {
    /** Text/chat agent. Defaults to the resolved Venice chat model. */
    chat?: string | null;
    /** Future: Venice decision/classifier models (e.g. Jev). Not used at runtime yet. */
    decision?: string | null;
    /** Future: speech-to-text (e.g. whisper-large-v3). Not used at runtime yet. */
    stt?: string | null;
    /** Future: text-to-speech. Not used at runtime yet. */
    tts?: string | null;
}

export interface AgentConfig {
    url: string;
    token: string;
    token_expires_at: string;
    user: StoredUser;
    /** Last model chosen by `lanomat-agent model setup`, as `provider/model`. */
    model?: string;
    modelRoles?: ModelRoles;
}

export interface DeviceStart {
    device_code: string;
    user_code: string;
    verification_uri: string;
    expires_in: number;
    interval: number;
}

export interface DeviceToken {
    access_token: string;
    token_type: string;
    expires_in: number;
}

export interface MeResponse {
    user: { id: string | number; name: string; role?: string };
    server: { name: string; url: string; version: string };
    modules: string[];
}

export interface RecipeSummary {
    slug: string;
    title: string;
    summary?: string;
    version?: string;
    platforms?: string[];
    status?: string;
    updated_at?: string;
}

export interface RecipeDownload {
    url: string;
    filename: string;
    sha256?: string;
    source?: 'lan' | 'external';
    size_bytes?: number;
    fallback_url?: string;
}

export type VerifyCheck =
    | { type: 'file_exists'; path: string; optional?: boolean }
    | { type: 'dir_exists'; path: string; optional?: boolean }
    | { type: 'command'; command: string; args?: string[]; shell?: 'powershell' | 'cmd' | 'bash'; optional?: boolean };

export interface PlanCommand {
    shell: 'powershell' | 'cmd' | 'bash';
    run: string;
    optional?: boolean;
}

export interface PlanStep {
    id: string;
    title: string;
    os?: Os | Os[];
    optional?: boolean;
    detail?: string;
    commands?: PlanCommand[];
    downloads?: RecipeDownload[];
    verify?: VerifyCheck[];
}

export interface PlanFile {
    requirement: string;
    status: 'matched' | 'missing' | 'external';
    name?: string;
    size_bytes?: number;
    sha256?: string;
    download_url?: string;
    fallback_url?: string;
    /** Some servers send the source explicitly; the plan renderer derives it when absent. */
    source?: 'lan' | 'external';
}

export interface Avatar {
    id: string;
    label: string;
    author?: string;
    license?: string;
    size_bytes?: number;
    sha256?: string;
    download_url?: string;
    match_name?: string;
    recipe_slugs?: string[];
    /** Present in the plan payload only. */
    required?: boolean;
}

export interface RecipeDetail {
    slug: string;
    title: string;
    summary?: string;
    version?: string;
    platforms?: string[];
    status?: string;
    updated_at?: string;
    /** Web game (browser) launch target. Takes precedence over local launch_target. */
    play_url?: string | null;
    /** Local command or executable path, e.g. `C:\Games\...\UT2004.exe`. */
    launch_target?: string | null;
    /** Named ways to start the game (flat, vr, …); `launch_target` is the default. */
    launch_targets?: LaunchTarget[] | null;
    supports_multiplayer?: boolean;
    install_command?: string | null;
    launch_command?: string | null;
    requirements?: unknown[];
    steps?: unknown[];
    files?: unknown[];
    avatars?: Avatar[];
    [key: string]: unknown;
}

/** One entry of `metadata.launch_targets` (server-normalised). */
export interface LaunchTarget {
    id: string;
    label?: string;
    os?: string;
    command: string;
    shell?: string | null;
    notes?: string | null;
}

export interface RecipePlan {
    recipe: { slug: string; title: string; version?: string };
    os: Os;
    target_dir: string;
    steps: PlanStep[];
    files: PlanFile[];
    avatars?: Avatar[];
    missing?: string[];
    /** Not in the base contract; used when a recipe stores avatars somewhere else. */
    avatars_dir?: string;
    /** Recipe-level launch fields are repeated on the plan payload. */
    play_url?: string | null;
    launch_target?: string | null;
    supports_multiplayer?: boolean;
    install_command?: string | null;
    launch_command?: string | null;
}

export interface AvatarCatalog {
    data: Avatar[];
}

export interface AvatarManifestEntry {
    id: string;
    label: string;
    filename: string;
    sha256?: string;
    size_bytes?: number;
    author?: string;
    license?: string;
    download_url?: string;
}

export interface AvatarManifest {
    source: { url: string; name?: string };
    fetched_at: string;
    avatars: AvatarManifestEntry[];
}

export interface DownloadProgress {
    filename: string;
    received: number;
    total?: number;
    percent?: number;
    bytesPerSecond?: number;
}

export interface DownloadTask {
    url: string;
    filename: string;
    destDir: string;
    sha256?: string;
    size_bytes?: number;
    source?: 'lan' | 'external';
    /** Additional headers for same-origin LANoMAT URLs (bearer auth). */
    headers?: Record<string, string>;
    label?: string;
}

export interface DownloadResult {
    task: DownloadTask;
    path: string;
    bytes: number;
    skipped: boolean;
    resumed: boolean;
}
