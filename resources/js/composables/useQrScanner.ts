import { onBeforeUnmount, ref, shallowRef } from 'vue';
import type { Ref } from 'vue';
import { createScanDebouncer } from '@/lib/scanDebounce';
import type { ScanDebouncer } from '@/lib/scanDebounce';

/** Minimal slice of the Barcode Detection API this scanner needs. */
export interface QrDetector {
    detect(source: HTMLVideoElement): Promise<{ rawValue: string }[]>;
}

export type ScannerState =
    | 'idle'
    | 'starting'
    | 'scanning'
    | 'denied'
    | 'no_camera'
    | 'camera_busy'
    | 'insecure'
    | 'unsupported'
    | 'error';

export interface QrScannerOptions {
    /** Called once per accepted scan; resolve when processing is done to resume. */
    onDecode: (value: string) => void | Promise<void>;
    /** Injectable for tests/browser checks; defaults to the real camera. */
    getStream?: () => Promise<MediaStream>;
    /** Injectable decoder; defaults to native BarcodeDetector, then the WASM ponyfill. */
    createDetector?: () => Promise<QrDetector>;
    debouncer?: ScanDebouncer;
    /** Delay between decode attempts (about 8 per second by default). */
    intervalMs?: number;
}

/**
 * Native `BarcodeDetector` when the browser has it and supports QR codes
 * (Chrome/Android); otherwise the maintained `barcode-detector` ponyfill
 * (ZXing WASM), loaded lazily so native browsers never download it. The WASM
 * binary is served from our own origin, never a CDN.
 */
export async function createDefaultDetector(): Promise<QrDetector> {
    const native = (
        globalThis as unknown as {
            BarcodeDetector?: {
                new (options: { formats: string[] }): QrDetector;
                getSupportedFormats?: () => Promise<string[]>;
            };
        }
    ).BarcodeDetector;

    if (native) {
        const formats = (await native.getSupportedFormats?.()) ?? ['qr_code'];

        if (formats.includes('qr_code')) {
            return new native({ formats: ['qr_code'] });
        }
    }

    const [{ BarcodeDetector, prepareZXingModule }, wasm] = await Promise.all([
        import('barcode-detector/ponyfill'),
        import('zxing-wasm/reader/zxing_reader.wasm?url'),
    ]);

    prepareZXingModule({
        overrides: {
            locateFile: (path: string, prefix: string) =>
                path.endsWith('.wasm') ? wasm.default : prefix + path,
        },
    });

    return new BarcodeDetector({ formats: ['qr_code'] });
}

function defaultGetStream(): Promise<MediaStream> {
    return navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1280 },
            height: { ideal: 720 },
        },
    });
}

function stateForError(error: unknown): ScannerState {
    const name = (error as { name?: string } | null)?.name;

    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return 'denied';
    }

    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        return 'no_camera';
    }

    if (name === 'NotReadableError' || name === 'AbortError') {
        return 'camera_busy';
    }

    return 'error';
}

/**
 * Continuous QR scanning on a `<video>` element. Decoding and the camera are
 * injectable so the state machine and debounce can be exercised without a
 * real camera. The stream is always stopped on `stop()` and unmount.
 */
export function useQrScanner(options: QrScannerOptions): {
    videoRef: Ref<HTMLVideoElement | null>;
    state: Ref<ScannerState>;
    start: () => Promise<void>;
    stop: () => void;
} {
    const videoRef = ref<HTMLVideoElement | null>(null);
    const state = ref<ScannerState>('idle');
    const stream = shallowRef<MediaStream | null>(null);
    const debouncer = options.debouncer ?? createScanDebouncer();
    const intervalMs = options.intervalMs ?? 125;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let detector: QrDetector | null = null;
    let generation = 0;

    function stop(): void {
        generation++;

        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        stream.value?.getTracks().forEach((track) => track.stop());
        stream.value = null;

        if (videoRef.value) {
            videoRef.value.srcObject = null;
        }

        state.value = 'idle';
    }

    async function tick(run: number): Promise<void> {
        const video = videoRef.value;

        if (run !== generation || !video || !detector) {
            return;
        }

        if (video.readyState >= 2) {
            try {
                const codes = await detector.detect(video);
                const value = codes.find((code) => code.rawValue)?.rawValue;

                if (run === generation && value && debouncer.accept(value)) {
                    try {
                        await options.onDecode(value);
                    } finally {
                        debouncer.release();
                    }
                }
            } catch {
                // A single failed frame is not worth interrupting the scan.
            }
        }

        if (run === generation) {
            timer = setTimeout(() => void tick(run), intervalMs);
        }
    }

    async function start(): Promise<void> {
        if (state.value === 'starting' || state.value === 'scanning') {
            return;
        }

        const customStream = options.getStream !== undefined;

        if (!customStream) {
            if (!window.isSecureContext) {
                state.value = 'insecure';

                return;
            }

            if (!navigator.mediaDevices?.getUserMedia) {
                state.value = 'unsupported';

                return;
            }
        }

        const run = ++generation;
        state.value = 'starting';

        try {
            const media = await (options.getStream ?? defaultGetStream)();

            if (run !== generation) {
                media.getTracks().forEach((track) => track.stop());

                return;
            }

            stream.value = media;
            detector = await (
                options.createDetector ?? createDefaultDetector
            )();

            const video = videoRef.value;

            if (!video || run !== generation) {
                stop();

                return;
            }

            video.srcObject = media;
            await video.play();
            state.value = 'scanning';
            void tick(run);
        } catch (error) {
            if (run === generation) {
                stream.value?.getTracks().forEach((track) => track.stop());
                stream.value = null;
                state.value = stateForError(error);
            }
        }
    }

    onBeforeUnmount(stop);

    return { videoRef, state, start, stop };
}
