/**
 * Tiny JSON client for the web voice endpoints. Laravel accepts the CSRF token from the
 * `XSRF-TOKEN` cookie as an `X-XSRF-TOKEN` header, which is read on every call so a token
 * rotated by a logout/login never goes stale in a long-lived tab.
 */
export class WebVoiceHttpError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

export async function webVoiceRequest<T>(
    method: 'GET' | 'POST' | 'DELETE',
    url: string,
    body?: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const payload = (await response.json().catch(() => ({}))) as {
        message?: string;
    };

    if (!response.ok) {
        throw new WebVoiceHttpError(response.status, payload.message ?? '');
    }

    return payload as T;
}

/** Paths of the web voice endpoints (routes/web.php, prefix `/talk`). */
export const webVoiceUrls = {
    token: (channelId: number) => `/talk/channels/${channelId}/token`,
    latencyTestToken: '/talk/latency-test/token',
    messages: (channelId: number) => `/talk/channels/${channelId}/messages`,
    message: (messageId: number) => `/talk/messages/${messageId}`,
    mute: (channelId: number, userId: number) =>
        `/talk/channels/${channelId}/participants/${userId}/mute`,
    remove: (channelId: number, userId: number) =>
        `/talk/channels/${channelId}/participants/${userId}/remove`,
};
