import type { Room } from 'livekit-client';
import { onUnmounted, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import {
    computeMetrics,
    reportToRecords,
    sampleReport,
} from '@/lib/webVoiceStats';
import type {
    ConnectionMetrics,
    PathDirection,
    RawSample,
} from '@/lib/webVoiceStats';

/**
 * Live connection numbers for the diagnostics panel: about once a second the real WebRTC stats of
 * the two LiveKit peer connections are read and reduced by `lib/webVoiceStats.ts`. Publisher
 * connection = what this browser sends, subscriber connection = what it receives; on a LAN both
 * take the same path, but they are separate connections and can differ (TCP fallback on one).
 *
 * Polling runs only while `enabled` is true and a room is connected, so the panel costs nothing
 * while it is collapsed.
 */
export type ConnectionStatsState = 'idle' | 'waiting' | 'live' | 'unavailable';

const INTERVAL_MS = 1000;

export function useConnectionStats(
    room: Ref<Room | null>,
    enabled: Ref<boolean>,
) {
    const state = ref<ConnectionStatsState>('idle');
    const send = shallowRef<ConnectionMetrics | null>(null);
    const receive = shallowRef<ConnectionMetrics | null>(null);

    const previous: Record<PathDirection, RawSample | null> = {
        send: null,
        receive: null,
    };
    let timer: ReturnType<typeof setInterval> | null = null;

    function reset(): void {
        previous.send = null;
        previous.receive = null;
        send.value = null;
        receive.value = null;
    }

    async function read(
        direction: PathDirection,
        transport:
            { getStats(): Promise<RTCStatsReport> | undefined } | undefined,
    ): Promise<ConnectionMetrics | null> {
        const report = await transport?.getStats();

        if (!report) {
            return null;
        }

        const sample = sampleReport(reportToRecords(report), direction);
        const metrics = computeMetrics(sample, previous[direction]);
        previous[direction] = sample;

        return metrics;
    }

    async function tick(): Promise<void> {
        const current = room.value;

        if (!current) {
            state.value = 'waiting';

            return;
        }

        try {
            const manager = current.engine.pcManager;
            const [out, incoming] = await Promise.all([
                read('send', manager?.publisher),
                // Single-connection mode has no separate subscriber: receiving then runs over the publisher.
                read('receive', manager?.subscriber ?? manager?.publisher),
            ]);

            send.value = out;
            receive.value = incoming;
            state.value = out || incoming ? 'live' : 'unavailable';
        } catch {
            state.value = 'unavailable';
        }
    }

    function stop(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    watch(
        [enabled, room],
        ([isEnabled, current]) => {
            stop();
            reset();

            if (!isEnabled) {
                state.value = 'idle';

                return;
            }

            state.value = current ? 'waiting' : 'idle';

            if (current) {
                void tick();
                timer = setInterval(() => void tick(), INTERVAL_MS);
            }
        },
        { immediate: true },
    );

    onUnmounted(stop);

    return { state, send, receive };
}
