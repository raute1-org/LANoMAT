/**
 * Debounce for continuous QR scanning: a camera sees the same code in dozens
 * of consecutive frames, so a value is accepted again only after it was out of view for a cooldown,
 * and any value is ignored while another scan is still being processed.
 * Pure (the clock is injectable) so the rule is testable without a camera.
 */
export interface ScanDebouncer {
    /** True when `value` should be handed to the consumer now. */
    accept(value: string): boolean;
    /** Call when the consumer finished processing the last accepted value. */
    release(): void;
}

export function createScanDebouncer(
    cooldownMs = 3000,
    now: () => number = () => Date.now(),
): ScanDebouncer {
    let lastValue: string | null = null;
    let lastAt = 0;
    let busy = false;

    return {
        accept(value) {
            if (busy) {
                return false;
            }

            const at = now();

            // The same code still in view keeps extending its own cooldown, so
            // a ticket held steady is scanned once, not again every few
            // seconds; it counts as new once it was out of frame for a while.
            if (value === lastValue && at - lastAt < cooldownMs) {
                lastAt = at;

                return false;
            }

            lastValue = value;
            lastAt = at;
            busy = true;

            return true;
        },
        release() {
            busy = false;
            lastAt = now();
        },
    };
}
