/**
 * Debounce for continuous QR scanning: a camera sees the same code in dozens
 * of consecutive frames, so a value is accepted at most once per cooldown,
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
    cooldownMs = 4000,
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

            if (value === lastValue && now() - lastAt < cooldownMs) {
                return false;
            }

            lastValue = value;
            lastAt = now();
            busy = true;

            return true;
        },
        release() {
            busy = false;
            // The cooldown for the same value starts when processing ends.
            lastAt = now();
        },
    };
}
