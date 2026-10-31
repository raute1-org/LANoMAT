import * as tus from 'tus-js-client';
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';

export type UploadStatus =
    | 'queued'
    | 'preparing'
    | 'uploading'
    | 'reconnecting'
    | 'paused'
    | 'done'
    | 'error';

/** Stable error codes: the server's ticket/hook codes plus the client-side ones. */
export type UploadErrorCode =
    | 'too_large'
    | 'blocked_type'
    | 'quota_exceeded'
    | 'ticket_expired'
    | 'ticket_invalid'
    | 'ticket_mismatch'
    | 'uploads_unavailable'
    | 'rate_limited'
    | 'host_full'
    | 'network'
    | 'generic';

export interface UploadItem {
    id: number;
    name: string;
    size: number;
    status: UploadStatus;
    sent: number;
    /** Smoothed transfer speed in bytes per second. */
    speed: number;
    etaSeconds: number | null;
    /** True once an interrupted transfer (lost connection, reload) continued where it stopped. */
    resumed: boolean;
    errorCode: UploadErrorCode | null;
    /** The server's German message, when it sent one. */
    errorMessage: string | null;
}

interface Ticket {
    endpoint: string;
    ticket: string;
    chunkSize: number;
    name: string;
}

interface Handle {
    file: File;
    upload: tus.Upload | null;
    startedAt: number;
    lastSample: number;
    lastBytes: number;
}

const MAX_PARALLEL = 2;

function xsrfToken(): string {
    const match = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

class TicketError extends Error {
    constructor(
        public readonly code: UploadErrorCode,
        public readonly serverMessage: string | null = null,
    ) {
        super(code);
    }
}

const KNOWN_CODES: UploadErrorCode[] = [
    'too_large',
    'blocked_type',
    'quota_exceeded',
    'ticket_expired',
    'ticket_invalid',
    'ticket_mismatch',
    'uploads_unavailable',
    'host_full',
];

function asCode(value: unknown): UploadErrorCode {
    return KNOWN_CODES.find((code) => code === value) ?? 'generic';
}

async function requestTicket(ticketUrl: string, file: File): Promise<Ticket> {
    let response: Response;

    try {
        response = await fetch(ticketUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                name: file.name,
                size: file.size,
                type: file.type || null,
            }),
        });
    } catch {
        throw new TicketError('network');
    }

    if (response.status === 429) {
        throw new TicketError('rate_limited');
    }

    const payload = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    if (!response.ok) {
        throw new TicketError(
            asCode(payload.code),
            typeof payload.message === 'string' ? payload.message : null,
        );
    }

    return payload as unknown as Ticket;
}

/**
 * Resumable uploads straight to the file host with the tus protocol.
 *
 * Per file: ask the app for a single-use ticket (it reserves the quota),
 * then send the file in chunks to tusd. `tus-js-client` keeps the upload URL
 * under the file's fingerprint in localStorage, so after a reload or a closed
 * tab choosing the same file again continues at the stored offset. Asking for
 * a ticket for the same file again does not reserve twice (the server reuses
 * the reservation).
 */
export function useResumableUploads(options: {
    ticketUrl: string;
    maxFileBytes: number;
    /** Called after a transfer finished, to refresh the list and the quota. */
    onFinished: () => void;
}) {
    const items = reactive<UploadItem[]>([]);
    const handles = new Map<number, Handle>();
    let nextId = 1;

    const active = computed(() =>
        items.some((item) =>
            ['preparing', 'uploading', 'reconnecting', 'queued'].includes(
                item.status,
            ),
        ),
    );

    function guard(event: BeforeUnloadEvent): void {
        if (active.value) {
            event.preventDefault();
        }
    }

    onMounted(() => window.addEventListener('beforeunload', guard));
    onBeforeUnmount(() => window.removeEventListener('beforeunload', guard));

    function find(id: number): UploadItem | undefined {
        return items.find((item) => item.id === id);
    }

    function fail(
        item: UploadItem,
        code: UploadErrorCode,
        message: string | null = null,
    ): void {
        item.status = 'error';
        item.errorCode = code;
        item.errorMessage = message;
        item.speed = 0;
        item.etaSeconds = null;
        pump();
    }

    function sample(item: UploadItem, handle: Handle, sent: number): void {
        const now = Date.now();
        const elapsed = (now - handle.lastSample) / 1000;

        item.sent = sent;

        if (elapsed >= 0.5) {
            const instant = (sent - handle.lastBytes) / elapsed;
            item.speed =
                item.speed === 0 ? instant : item.speed * 0.7 + instant * 0.3;
            handle.lastSample = now;
            handle.lastBytes = sent;
            item.etaSeconds =
                item.speed > 0 ? (item.size - sent) / item.speed : null;
        }
    }

    function describe(error: Error): {
        code: UploadErrorCode;
        message: string | null;
    } {
        const response = (error as tus.DetailedError).originalResponse;

        if (!response) {
            return { code: 'network', message: null };
        }

        const status = response.getStatus();

        if (status === 413) {
            return { code: 'too_large', message: null };
        }

        if (status === 507) {
            return { code: 'host_full', message: null };
        }

        try {
            const body = JSON.parse(response.getBody() || '{}') as {
                code?: unknown;
            };

            if (typeof body.code === 'string') {
                return { code: asCode(body.code), message: null };
            }
        } catch {
            // not JSON: fall through
        }

        return { code: status >= 500 ? 'network' : 'generic', message: null };
    }

    async function begin(item: UploadItem): Promise<void> {
        const handle = handles.get(item.id);

        if (!handle) {
            return;
        }

        item.status = 'preparing';
        item.errorCode = null;
        item.errorMessage = null;

        let ticket: Ticket;

        try {
            ticket = await requestTicket(options.ticketUrl, handle.file);
        } catch (error) {
            const known =
                error instanceof TicketError
                    ? error
                    : new TicketError('network');
            fail(item, known.code, known.serverMessage);

            return;
        }

        if (!find(item.id)) {
            return; // cancelled while the ticket was requested
        }

        const upload = new tus.Upload(handle.file, {
            endpoint: ticket.endpoint,
            // One PATCH per chunk: a failed chunk costs at most this much.
            chunkSize: ticket.chunkSize,
            retryDelays: [0, 1000, 3000, 5000, 10000, 20000, 30000],
            removeFingerprintOnSuccess: true,
            metadata: {
                filename: ticket.name,
                filetype: handle.file.type || 'application/octet-stream',
                ticket: ticket.ticket,
            },
            onShouldRetry: (error, retryAttempt, tusOptions) => {
                const status = (
                    error as tus.DetailedError
                ).originalResponse?.getStatus();

                // The server refused (rules, ticket): retrying cannot help.
                if (status === 403 || status === 413 || status === 507) {
                    return false;
                }

                item.status = 'reconnecting';

                return tus.defaultOptions.onShouldRetry
                    ? tus.defaultOptions.onShouldRetry(
                          error,
                          retryAttempt,
                          tusOptions,
                      )
                    : true;
            },
            onProgress: (sent) => {
                if (item.status === 'reconnecting') {
                    item.resumed = true;
                }

                item.status = 'uploading';
                sample(item, handle, sent);
            },
            onSuccess: () => {
                item.status = 'done';
                item.sent = item.size;
                item.speed = 0;
                item.etaSeconds = null;
                // The finish hook runs asynchronously on the file host: refresh twice.
                window.setTimeout(options.onFinished, 1500);
                window.setTimeout(options.onFinished, 6000);
                pump();
            },
            onError: (error) => {
                const { code, message } = describe(error);
                fail(item, code, message);
            },
        });

        handle.upload = upload;
        handle.lastSample = Date.now();
        handle.lastBytes = 0;

        // A reload or a closed tab: the fingerprint still knows where it stopped.
        const previous = await upload.findPreviousUploads();

        if (previous.length > 0) {
            upload.resumeFromPreviousUpload(previous[0]);
            item.resumed = true;
        }

        upload.start();
    }

    function runningCount(): number {
        return items.filter((item) =>
            ['preparing', 'uploading', 'reconnecting'].includes(item.status),
        ).length;
    }

    function pump(): void {
        for (const item of items) {
            if (runningCount() >= MAX_PARALLEL) {
                return;
            }

            if (item.status === 'queued') {
                void begin(item);
            }
        }
    }

    function add(files: File[]): void {
        for (const file of files) {
            const item = reactive<UploadItem>({
                id: nextId++,
                name: file.name,
                size: file.size,
                status: 'queued',
                sent: 0,
                speed: 0,
                etaSeconds: null,
                resumed: false,
                errorCode: null,
                errorMessage: null,
            });

            items.push(item);
            handles.set(item.id, {
                file,
                upload: null,
                startedAt: Date.now(),
                lastSample: Date.now(),
                lastBytes: 0,
            });

            if (file.size > options.maxFileBytes) {
                fail(item, 'too_large');
            }
        }

        pump();
    }

    function pause(id: number): void {
        const item = find(id);
        const handle = handles.get(id);

        if (!item || !handle?.upload) {
            return;
        }

        void handle.upload.abort(false);
        item.status = 'paused';
        item.speed = 0;
        item.etaSeconds = null;
        pump();
    }

    function resume(id: number): void {
        const item = find(id);
        const handle = handles.get(id);

        if (!item || !handle?.upload) {
            return;
        }

        item.status = 'uploading';
        item.resumed = true;
        handle.lastSample = Date.now();
        handle.lastBytes = item.sent;
        handle.upload.start();
    }

    /** Stops the transfer and tells the file host to delete what it received. */
    function cancel(id: number): void {
        const handle = handles.get(id);

        void handle?.upload?.abort(true).catch(() => undefined);
        dismiss(id);
    }

    function retry(id: number): void {
        const item = find(id);

        if (item?.status === 'error') {
            item.sent = 0;
            item.status = 'queued';
            pump();
        }
    }

    function dismiss(id: number): void {
        const index = items.findIndex((item) => item.id === id);

        if (index >= 0) {
            items.splice(index, 1);
        }

        handles.delete(id);
        pump();
    }

    return { items, active, add, pause, resume, cancel, retry, dismiss };
}
