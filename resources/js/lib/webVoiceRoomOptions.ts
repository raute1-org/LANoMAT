import type {
    AudioCaptureOptions,
    RoomOptions,
    TrackPublishDefaults,
} from 'livekit-client';
import type { WebVoiceAudioConfig } from '@/types';

/**
 * The one place that decides how a web voice `Room` captures and publishes audio. The real room
 * (`useWebVoiceRoom`) and the latency self-test (`useLatencyTest`) both build their options here,
 * so the test measures the setup people actually use, not a lookalike.
 *
 * Only types are imported from livekit-client: the library itself is loaded on the first join.
 * Rationale for every number: "Latency choices" in
 * docs/superpowers/specs/2026-10-31-web-voice-design.md.
 */

/** What the user chose on this device; see `loadTuning` / `saveTuning`. */
export type VoiceTuning = {
    /**
     * "Headset-Modus (niedrigste Latenz)": no echo cancellation, no noise suppression, no automatic
     * gain control. Those stages add processing delay (and, in Chrome, may add buffering), but
     * without them open speakers feed back, so it is only for headsets.
     */
    headsetMode: boolean;
};

export const DEFAULT_TUNING: VoiceTuning = { headsetMode: false };

const TUNING_STORAGE_NAME = 'lanomat.webvoice.tuning.v1';

/** `localStorage` can throw (private window, blocked site data): every access is guarded. */
export function loadTuning(): VoiceTuning {
    try {
        const raw = window.localStorage.getItem(TUNING_STORAGE_NAME);
        const parsed: unknown = raw ? JSON.parse(raw) : null;

        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            typeof (parsed as VoiceTuning).headsetMode === 'boolean'
        ) {
            return { headsetMode: (parsed as VoiceTuning).headsetMode };
        }
    } catch {
        // Fall through to the defaults: the page must work without storage.
    }

    return { ...DEFAULT_TUNING };
}

export function saveTuning(tuning: VoiceTuning): void {
    try {
        window.localStorage.setItem(
            TUNING_STORAGE_NAME,
            JSON.stringify(tuning),
        );
    } catch {
        // Not remembered, still applied for this page view.
    }
}

/**
 * Capture constraints. The three processing stages follow the server config unless headset mode
 * is on. Headset mode additionally asks for 48 kHz (the Opus clock, so the browser does not
 * resample); `latency` is only a hint that browsers are free to ignore.
 */
export function captureDefaults(
    audio: WebVoiceAudioConfig,
    tuning: VoiceTuning,
): AudioCaptureOptions {
    return {
        echoCancellation: tuning.headsetMode ? false : audio.echo_cancellation,
        noiseSuppression: tuning.headsetMode ? false : audio.noise_suppression,
        autoGainControl: tuning.headsetMode ? false : audio.auto_gain_control,
        // livekit-client asks for `voiceIsolation: true` by default (a further, heavier noise filter
        // in Chromium); headset mode turns it off as well.
        ...(tuning.headsetMode
            ? { voiceIsolation: false, sampleRate: 48000 }
            : {}),
        channelCount: 1,
        latency: { ideal: 0.01 },
    };
}

/**
 * Publish options. `livekit-client` 2.23 has no packet-time (ptime) option: the browser's 20 ms
 * Opus frame is not configurable without rewriting the SDP, which is deliberately not done.
 */
export function publishDefaults(
    audio: WebVoiceAudioConfig,
): TrackPublishDefaults {
    return {
        audioPreset: { maxBitrate: audio.bitrate },
        dtx: audio.dtx,
        red: audio.red,
    };
}

/** Audio only: adaptive stream and dynacast are video features. */
export function roomOptions(
    audio: WebVoiceAudioConfig,
    tuning: VoiceTuning,
): RoomOptions {
    return {
        adaptiveStream: false,
        dynacast: false,
        audioCaptureDefaults: captureDefaults(audio, tuning),
        publishDefaults: publishDefaults(audio),
    };
}

/** `RTCRtpReceiver.jitterBufferTarget` (milliseconds) is not in every browser: detect, never assume. */
export function supportsJitterBufferTarget(receiver: RTCRtpReceiver): boolean {
    return 'jitterBufferTarget' in receiver;
}

/**
 * Sets the receiver's jitter buffer target in milliseconds (`null` restores the browser's adaptive
 * default). It is a target the browser tries to hold, not a hard limit, and the adaptive buffer may
 * stay above it when the network needs more. Returns whether the browser accepted it.
 */
export function applyJitterBufferTarget(
    receiver: RTCRtpReceiver | undefined,
    targetMs: number | null,
): boolean {
    if (!receiver || !supportsJitterBufferTarget(receiver)) {
        return false;
    }

    try {
        (
            receiver as RTCRtpReceiver & { jitterBufferTarget: number | null }
        ).jitterBufferTarget = targetMs;

        return true;
    } catch {
        // Out-of-range values throw a RangeError in browsers that implement it.
        return false;
    }
}
