/**
 * AudioWorklet of the latency self-test: finds the first sample of a tone burst in the received
 * stream. It runs on the audio rendering thread, so the onset is known to one sample, not to a
 * timer tick or animation frame (which would be 4 to 16 ms coarse, the size of the effect that is
 * measured). It posts `{ frame }` where `frame / sampleRate` is on the same clock as the
 * `AudioContext.currentTime` the bursts were scheduled on.
 *
 * Loaded from a same-origin file (Vite `?worker&url`), because worklets are governed by the
 * page's `script-src`, which has no `blob:` source.
 */

// The worklet global scope is not part of the DOM lib; declare the little that is used.
declare const currentFrame: number;
declare const sampleRate: number;
declare class AudioWorkletProcessor {
    readonly port: MessagePort;
}
declare function registerProcessor(
    name: string,
    processor: new () => AudioWorkletProcessor & {
        process(inputs: Float32Array[][]): boolean;
    },
): void;

/** Peak amplitude (full scale = 1) that counts as "the burst has started". Bursts are sent at 0.5. */
const THRESHOLD = 0.1;
/** After a detection, ignore the tail of the same burst and its codec ringing for this long. */
const DEAD_TIME_SECONDS = 0.4;

class LatencyDetector extends AudioWorkletProcessor {
    private nextAllowedFrame = 0;

    process(inputs: Float32Array[][]): boolean {
        const channel = inputs[0]?.[0];

        if (!channel || currentFrame < this.nextAllowedFrame) {
            return true;
        }

        for (let i = 0; i < channel.length; i++) {
            if (Math.abs(channel[i]) >= THRESHOLD) {
                this.nextAllowedFrame =
                    currentFrame + i + DEAD_TIME_SECONDS * sampleRate;
                this.port.postMessage({ frame: currentFrame + i });

                break;
            }
        }

        return true;
    }
}

registerProcessor('latency-detector', LatencyDetector);
