/**
 * Pure math of the in-app latency self-test (no DOM, no livekit-client).
 *
 * The test sends a short tone burst once per interval through the real publish -> SFU -> subscribe
 * path and times when it arrives, all on ONE audio clock (generation and detection run in the
 * same `AudioContext`), so the difference needs no clock synchronization between devices.
 */

export type LatencySummary = {
    /** Bursts that were detected. */
    count: number;
    /** Bursts that were sent but never detected before the timeout. */
    lost: number;
    minMs: number;
    medianMs: number;
    p95Ms: number;
    maxMs: number;
    /**
     * "Jitter" in this test = the standard deviation of the per-burst delays. It is not the RFC 3550
     * interarrival jitter of the diagnostics panel (a smoothed estimate from RTP timestamps).
     */
    jitterMs: number;
};

/** Linear-interpolated percentile of an ascending list; `p` in 0..1. */
export function percentile(sorted: readonly number[], p: number): number {
    if (sorted.length === 0) {
        return Number.NaN;
    }

    const rank = (sorted.length - 1) * Math.min(1, Math.max(0, p));
    const low = Math.floor(rank);
    const high = Math.ceil(rank);

    return sorted[low] + (sorted[high] - sorted[low]) * (rank - low);
}

/** Population standard deviation (the bursts are the whole run, not a sample of a larger one). */
export function standardDeviation(values: readonly number[]): number {
    if (values.length === 0) {
        return Number.NaN;
    }

    const mean =
        values.reduce((total, value) => total + value, 0) / values.length;
    const variance =
        values.reduce((total, value) => total + (value - mean) ** 2, 0) /
        values.length;

    return Math.sqrt(variance);
}

/**
 * Summarizes the delays (ms) of the detected bursts. `sent` is how many bursts were generated, so
 * `lost = sent - delays.length`. Returns `null` when nothing was detected.
 */
export function summarizeDelays(
    delays: readonly number[],
    sent: number,
): LatencySummary | null {
    if (delays.length === 0) {
        return null;
    }

    const sorted = [...delays].sort((a, b) => a - b);

    return {
        count: sorted.length,
        lost: Math.max(0, sent - sorted.length),
        minMs: sorted[0],
        medianMs: percentile(sorted, 0.5),
        p95Ms: percentile(sorted, 0.95),
        maxMs: sorted[sorted.length - 1],
        jitterMs: standardDeviation(sorted),
    };
}

/**
 * Matches detections to the bursts that were scheduled. Each detection belongs to the latest burst
 * that started before it; a detection earlier than its burst plus `minPlausibleMs`, or later than
 * `maxMs` after it, is dropped (a repeated echo or noise), and a burst is counted once.
 *
 * Times are in seconds on the shared audio clock; the result is in milliseconds.
 */
export function matchDetections(
    scheduled: readonly number[],
    detected: readonly number[],
    maxMs = 1500,
    minPlausibleMs = 1,
): number[] {
    const delays: number[] = [];
    const used = new Set<number>();

    for (const time of [...detected].sort((a, b) => a - b)) {
        let burst = -1;

        for (let i = 0; i < scheduled.length; i++) {
            if (scheduled[i] <= time) {
                burst = i;
            }
        }

        if (burst === -1 || used.has(burst)) {
            continue;
        }

        const delayMs = (time - scheduled[burst]) * 1000;

        if (delayMs >= minPlausibleMs && delayMs <= maxMs) {
            used.add(burst);
            delays.push(delayMs);
        }
    }

    return delays;
}
