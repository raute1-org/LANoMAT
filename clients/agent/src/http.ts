import type {
    Avatar,
    DeviceStart,
    DeviceToken,
    MeResponse,
    Os,
    RecipeDetail,
    RecipePlan,
    RecipeSummary,
} from './types.js';
import { joinUrl, normalizeBaseUrl, validateRecipeSlug } from './util.js';

export type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;

export class ApiError extends Error {
    readonly status: number;
    readonly code?: string;
    readonly body?: unknown;

    constructor(message: string, status: number, code?: string, body?: unknown) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.body = body;
    }
}

export interface ApiClientOptions {
    baseUrl: string;
    token?: string;
    fetchImpl?: FetchLike;
    requestTimeoutMs?: number;
}

export interface RequestOptions {
    method?: string;
    body?: unknown;
    headers?: Record<string, string>;
    auth?: boolean;
    raw?: boolean;
    timeoutMs?: number;
}

export type DevicePollResult =
    | { status: 'ok'; token: DeviceToken }
    | { status: 'error'; error: string; message: string };

const DEFAULT_TIMEOUT_MS = 30_000;

export class ApiClient {
    readonly baseUrl: string;
    readonly token?: string;
    private readonly fetchImpl: FetchLike;
    private readonly timeoutMs: number;

    constructor(options: ApiClientOptions) {
        this.baseUrl = normalizeBaseUrl(options.baseUrl);
        this.token = options.token;
        this.fetchImpl = options.fetchImpl ?? fetch;
        this.timeoutMs = options.requestTimeoutMs ?? DEFAULT_TIMEOUT_MS;
    }

    resolve(path: string): string {
        return joinUrl(this.baseUrl, path);
    }

    authHeaders(): Record<string, string> {
        return this.token ? { Authorization: `Bearer ${this.token}` } : {};
    }

    /** Bearer auth only for URLs on the LANoMAT origin; signed LAN URLs must not receive it. */
    headersFor(url: string): Record<string, string> {
        try {
            return new URL(url).origin === new URL(this.baseUrl).origin ? this.authHeaders() : {};
        } catch {
            return {};
        }
    }

    async request<T>(path: string, options: RequestOptions = {}): Promise<T> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'User-Agent': 'lanomat-agent/0.1',
            ...(options.headers ?? {}),
        };

        if (options.auth !== false) {
            Object.assign(headers, this.authHeaders());
        }

        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        const init: RequestInit = {
            method: options.method ?? (options.body === undefined ? 'GET' : 'POST'),
            headers,
            redirect: 'follow',
        };

        if (options.body !== undefined) {
            init.body = JSON.stringify(options.body);
        }

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? this.timeoutMs);
        init.signal = controller.signal;

        let response: Response;

        try {
            response = await this.fetchImpl(this.resolve(path), init);
        } catch (error) {
            throw new ApiError(
                `Cannot reach ${this.baseUrl}: ${(error as Error).message}`,
                0,
                'network_error',
            );
        } finally {
            clearTimeout(timer);
        }

        const text = await response.text();

        if (!response.ok) {
            let parsed: unknown = text;
            let code: string | undefined;
            let message = `Request failed (${response.status})`;

            try {
                const json = JSON.parse(text) as Record<string, unknown>;

                parsed = json;
                code = typeof json.error === 'string' ? json.error : undefined;
                message =
                    typeof json.message === 'string'
                        ? json.message
                        : typeof json.error_description === 'string'
                          ? json.error_description
                          : code
                            ? `${code} (${response.status})`
                            : message;
            } catch {
                // Keep the plain-text body for the error message.
            }

            if (response.status === 401) {
                message = 'Not signed in or session expired. Run "lanomat-agent login".';
            }

            throw new ApiError(message, response.status, code, parsed);
        }

        if (options.raw) {
            return text as T;
        }

        if (text.length === 0) {
            return undefined as T;
        }

        try {
            return JSON.parse(text) as T;
        } catch {
            throw new ApiError(`Expected JSON from ${path}`, response.status, 'invalid_json', text);
        }
    }

    async startDevice(deviceName: string): Promise<DeviceStart> {
        return this.request<DeviceStart>('/api/mcp/device', {
            auth: false,
            body: { device_name: deviceName },
        });
    }

    async pollDeviceToken(deviceCode: string): Promise<DevicePollResult> {
        try {
            const token = await this.request<DeviceToken>('/api/mcp/token', {
                auth: false,
                body: { device_code: deviceCode },
            });

            return { status: 'ok', token };
        } catch (error) {
            if (!(error instanceof ApiError)) {
                throw error;
            }

            if (error.status === 429) {
                return { status: 'error', error: 'slow_down', message: 'Slow down: the server asked for a longer interval.' };
            }

            const code = error.code ?? error.message;

            return { status: 'error', error: code, message: error.message };
        }
    }

    async me(): Promise<MeResponse> {
        return this.request<MeResponse>('/api/v1/me');
    }

    async listRecipes(): Promise<RecipeSummary[]> {
        const response = await this.request<{ data?: RecipeSummary[] } | RecipeSummary[]>(
            '/api/v1/recipes?status=published',
        );

        return Array.isArray(response) ? response : (response.data ?? []);
    }

    async getRecipe(slug: string): Promise<RecipeDetail> {
        return this.request<RecipeDetail>(`/api/v1/recipes/${encodeURIComponent(validateRecipeSlug(slug))}`);
    }

    async getSkill(slug: string): Promise<string> {
        return this.request<string>(`/api/v1/recipes/${encodeURIComponent(validateRecipeSlug(slug))}/skill`, {
            headers: { Accept: 'text/markdown, text/plain;q=0.9' },
            raw: true,
        });
    }

    async createPlan(
        slug: string,
        os: Os,
        options: { eventSlug?: string; includeAvatars?: boolean } = {},
    ): Promise<RecipePlan> {
        const body: Record<string, unknown> = { os, include_avatars: options.includeAvatars ?? true };

        if (options.eventSlug) {
            body.event_slug = options.eventSlug;
        }

        return this.request<RecipePlan>(
            `/api/v1/recipes/${encodeURIComponent(validateRecipeSlug(slug))}/plan`,
            { body },
        );
    }

    async listRecipeAvatars(slug: string): Promise<Avatar[]> {
        const response = await this.request<{ data?: Avatar[] } | Avatar[]>(
            `/api/v1/recipes/${encodeURIComponent(validateRecipeSlug(slug))}/avatars`,
        );

        return Array.isArray(response) ? response : (response.data ?? []);
    }

    async listAvatars(options: { recipe?: string; q?: string } = {}): Promise<Avatar[]> {
        const params = new URLSearchParams();

        if (options.recipe) {
            params.set('recipe', validateRecipeSlug(options.recipe));
        }

        if (options.q) {
            params.set('q', options.q);
        }

        const suffix = params.size > 0 ? `?${params.toString()}` : '';
        const response = await this.request<{ data?: Avatar[] } | Avatar[]>(`/api/v1/avatars${suffix}`);

        return Array.isArray(response) ? response : (response.data ?? []);
    }

    fileDownloadUrl(id: string): string {
        return this.resolve(`/api/v1/files/${encodeURIComponent(id)}/download`);
    }

    avatarDownloadUrl(id: string): string {
        return this.resolve(`/api/v1/avatars/${encodeURIComponent(id)}/download`);
    }
}

/** Replace a bearer token anywhere in text. Used by every logger. */
export function redactSecret(text: string, secret: string | undefined): string {
    if (!secret || secret.length < 4) {
        return text;
    }

    return text.split(secret).join('«redacted»');
}
