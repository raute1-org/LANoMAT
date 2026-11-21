import type { StreamPart } from '@/types/assistant';

/**
 * Minimal client for the assistant endpoints. The server speaks the Vercel UI
 * message stream (`data: {json}` lines, `data: [DONE]` at the end); a small own
 * reader is enough and keeps the page free of an AI SDK dependency.
 */

function csrfHeaders(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

/** Splits an SSE buffer into complete `data:` payloads and the unfinished rest. */
export function parseSseChunk(buffer: string): {
    parts: StreamPart[];
    rest: string;
    done: boolean;
} {
    const events = buffer.split('\n\n');
    const rest = events.pop() ?? '';
    const parts: StreamPart[] = [];
    let done = false;

    for (const event of events) {
        const payload = event
            .split('\n')
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.slice(5).trimStart())
            .join('\n');

        if (payload === '') {
            continue;
        }

        if (payload === '[DONE]') {
            done = true;
            continue;
        }

        try {
            parts.push(JSON.parse(payload) as StreamPart);
        } catch {
            // A malformed line is skipped; the history reload after the stream is authoritative.
        }
    }

    return { parts, rest, done };
}

export class AssistantRequestError extends Error {
    readonly status: number;

    constructor(message: string, status: number) {
        super(message);
        this.status = status;
    }
}

/**
 * POSTs JSON and feeds every stream part to `onPart`. Resolves with the
 * conversation id from the response header.
 */
export async function streamRequest(
    url: string,
    body: Record<string, unknown>,
    onPart: (part: StreamPart) => void,
    signal?: AbortSignal,
): Promise<string | null> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok || !response.body) {
        let message = `HTTP ${response.status}`;

        try {
            const json = (await response.json()) as { message?: string };
            message = json.message ?? message;
        } catch {
            // keep the status text
        }

        throw new AssistantRequestError(message, response.status);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { value, done } = await reader.read();

        if (value) {
            buffer += decoder.decode(value, { stream: !done });
            const parsed = parseSseChunk(buffer);
            buffer = parsed.rest;
            parsed.parts.forEach(onPart);
        }

        if (done) {
            break;
        }
    }

    return response.headers.get('X-Conversation-Id');
}

/** DELETE with CSRF; the endpoint answers 204. */
export async function deleteRequest(url: string): Promise<void> {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
    });

    if (!response.ok) {
        throw new AssistantRequestError(
            `HTTP ${response.status}`,
            response.status,
        );
    }
}
