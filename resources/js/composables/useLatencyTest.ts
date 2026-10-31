import type { Room } from 'livekit-client';
import { onUnmounted, ref } from 'vue';
import {
    WebVoiceHttpError,
    webVoiceRequest,
    webVoiceUrls,
} from '@/lib/webVoiceHttp';
import { matchDetections, summarizeDelays } from '@/lib/webVoiceLatency';
import type { LatencySummary } from '@/lib/webVoiceLatency';
import {
    applyJitterBufferTarget,
    roomOptions,
} from '@/lib/webVoiceRoomOptions';
import type { VoiceTuning } from '@/lib/webVoiceRoomOptions';
import {
    computeMetrics,
    reportToRecords,
    sampleReport,
} from '@/lib/webVoiceStats';
import type { ConnectionMetrics } from '@/lib/webVoiceStats';
import type { WebVoiceLatencyTokenResponse } from '@/types';
import detectorUrl from '@/workers/latencyDetector.worklet.ts?worker&url';

/**
 * The in-app latency self-test ("Latenztest").
 *
 * It measures encode + network + SFU + jitter buffer + decode of the REAL publish/subscribe path,
 * without a microphone or a speaker: one browser tab opens two connections to a private probe room
 * (two tokens of the same user, distinct identities), publishes a synthetic tone burst every second
 * from the first and detects it on the receiving MediaStream with an AudioWorklet in the second.
 * Both ends share one `AudioContext`, hence one clock: the delay needs no clock synchronization.
 *
 * What is NOT in the number, and the page says so: the operating system's microphone and speaker
 * buffers (typically 10 to 40 ms more in a real call), the browser's capture processing (echo
 * cancellation, noise suppression: the synthetic track bypasses `getUserMedia`, so the test cannot
 * measure the headset mode either), and the human. What IS in it besides the network path: the
 * Web Audio <-> MediaStream bridge at both ends (about one render quantum of 2.7 ms each) and the
 * 10 ms chunking of the WebRTC audio pipeline.
 */

export type LatencyTestPhase =
    'idle' | 'preparing' | 'running' | 'done' | 'error';

export type LatencyTestFailure = 'unavailable' | 'failed' | 'no_audio';

export type LatencyTestResult = {
    summary: LatencySummary;
    /** Receive-side numbers of the subscriber connection over the run, when the browser reports them. */
    receive: ConnectionMetrics | null;
    settings: {
        bursts: number;
        bitrate: number;
        dtx: boolean;
        red: boolean;
        jitterBufferTargetMs: number | null;
        jitterBufferTargetApplied: boolean;
    };
};

export type LatencyTestOptions = {
    tuning: VoiceTuning;
    /** `null` leaves the browser's adaptive jitter buffer alone. */
    jitterBufferTargetMs: number | null;
    bursts?: number;
};

/** Bursts that are sent first and thrown away: the first packets after a silence are not typical. */
const WARMUP_BURSTS = 3;
const BURST_INTERVAL_SECONDS = 1;
const BURST_SECONDS = 0.02;
const BURST_FREQUENCY_HZ = 1000;
const BURST_AMPLITUDE = 0.5;

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

export function useLatencyTest() {
    const phase = ref<LatencyTestPhase>('idle');
    const failure = ref<LatencyTestFailure | null>(null);
    const progress = ref({ done: 0, total: 0 });
    const result = ref<LatencyTestResult | null>(null);

    let cancelled = false;
    let teardown: (() => Promise<void>) | null = null;

    async function run(options: LatencyTestOptions): Promise<void> {
        if (phase.value === 'preparing' || phase.value === 'running') {
            return;
        }

        cancelled = false;
        failure.value = null;
        result.value = null;
        phase.value = 'preparing';

        const bursts = options.bursts ?? 20;
        const total = bursts + WARMUP_BURSTS;
        progress.value = { done: 0, total: bursts };

        try {
            const grant = await webVoiceRequest<WebVoiceLatencyTokenResponse>(
                'POST',
                webVoiceUrls.latencyTestToken,
            );
            const { Room, RoomEvent, Track } = await import('livekit-client');

            const context = new AudioContext({ latencyHint: 'interactive' });
            const rooms: Room[] = [];
            const elements: HTMLMediaElement[] = [];

            teardown = async () => {
                elements.forEach((element) => element.remove());
                await Promise.allSettled(
                    rooms.map((room) => room.disconnect()),
                );
                await context.close().catch(() => undefined);
                teardown = null;
            };

            await context.resume();
            await context.audioWorklet.addModule(detectorUrl);

            const detections: number[] = [];
            const detector = new AudioWorkletNode(context, 'latency-detector');
            detector.port.onmessage = (
                event: MessageEvent<{ frame: number }>,
            ) => {
                detections.push(event.data.frame / context.sampleRate);
            };
            // A node without a path to the destination may not be pulled by the renderer.
            const sink = context.createGain();
            sink.gain.value = 0;
            detector.connect(sink).connect(context.destination);

            const destination = context.createMediaStreamDestination();
            const syntheticTrack = destination.stream.getAudioTracks()[0];

            const sender = new Room(roomOptions(grant.audio, options.tuning));
            const receiver = new Room(roomOptions(grant.audio, options.tuning));
            rooms.push(sender, receiver);

            let applied = false;
            let firstSample: ReturnType<typeof sampleReport> | null = null;
            const subscribed = new Promise<void>((resolve) => {
                receiver.on(RoomEvent.TrackSubscribed, (track) => {
                    if (track.kind !== Track.Kind.Audio) {
                        return;
                    }

                    applied = applyJitterBufferTarget(
                        (track as { receiver?: RTCRtpReceiver }).receiver,
                        options.jitterBufferTargetMs,
                    );

                    // Chromium only delivers remote WebRTC audio to Web Audio when the stream
                    // is also attached to a media element; it stays muted, nothing is heard.
                    const element = track.attach();
                    element.muted = true;
                    element.classList.add('hidden');
                    document.body.appendChild(element);
                    elements.push(element);

                    context
                        .createMediaStreamSource(
                            new MediaStream([track.mediaStreamTrack]),
                        )
                        .connect(detector);
                    resolve();
                });
            });

            await receiver.connect(grant.url, grant.receiver.token);
            await sender.connect(grant.url, grant.sender.token);
            await sender.localParticipant.publishTrack(syntheticTrack, {
                source: Track.Source.Microphone,
                name: 'latency-probe',
            });
            await Promise.race([subscribed, wait(8000)]);

            if (cancelled) {
                throw new Error('cancelled');
            }

            const sampleReceiver = async () => {
                const manager = receiver.engine.pcManager;
                const pc = manager?.subscriber ?? manager?.publisher;
                const report = await pc?.getStats();

                return report
                    ? sampleReport(reportToRecords(report), 'receive')
                    : null;
            };

            // Schedule everything on the audio clock; the first burst after a short lead-in.
            const start = context.currentTime + 1;
            const scheduled: number[] = [];

            for (let i = 0; i < total; i++) {
                // A random phase inside the 20 ms packet grid: exactly 1 s spacing would hit the same
                // phase of the 10 ms audio chunking every time and hide its effect on the spread.
                const at =
                    start + i * BURST_INTERVAL_SECONDS + Math.random() * 0.02;
                const oscillator = context.createOscillator();
                const gain = context.createGain();

                oscillator.frequency.value = BURST_FREQUENCY_HZ;
                gain.gain.value = BURST_AMPLITUDE;
                oscillator.connect(gain).connect(destination);
                oscillator.start(at);
                oscillator.stop(at + BURST_SECONDS);

                if (i >= WARMUP_BURSTS) {
                    scheduled.push(at);
                }
            }

            phase.value = 'running';

            // Sample the receiver after the warm-up so the averages cover the measured bursts.
            await wait((1 + WARMUP_BURSTS) * BURST_INTERVAL_SECONDS * 1000);
            firstSample = await sampleReceiver();

            for (let i = 0; i < bursts; i++) {
                if (cancelled) {
                    throw new Error('cancelled');
                }

                await wait(BURST_INTERVAL_SECONDS * 1000);
                progress.value = { done: i + 1, total: bursts };
            }

            await wait(800);
            const lastSample = await sampleReceiver();

            const delays = matchDetections(scheduled, detections);
            const summary = summarizeDelays(delays, bursts);

            await teardown?.();

            if (!summary) {
                failure.value = 'no_audio';
                phase.value = 'error';

                return;
            }

            result.value = {
                summary,
                receive: lastSample
                    ? computeMetrics(lastSample, firstSample)
                    : null,
                settings: {
                    bursts,
                    bitrate: grant.audio.bitrate,
                    dtx: grant.audio.dtx,
                    red: grant.audio.red,
                    jitterBufferTargetMs: options.jitterBufferTargetMs,
                    jitterBufferTargetApplied: applied,
                },
            };
            phase.value = 'done';
        } catch (error) {
            await teardown?.();

            if (cancelled) {
                phase.value = 'idle';

                return;
            }

            failure.value =
                error instanceof WebVoiceHttpError && error.status === 503
                    ? 'unavailable'
                    : 'failed';
            phase.value = 'error';
        }
    }

    function cancel(): void {
        cancelled = true;
    }

    onUnmounted(() => {
        cancelled = true;
        void teardown?.();
    });

    return { phase, failure, progress, result, run, cancel };
}
