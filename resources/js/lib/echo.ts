/**
 * Whether the Echo client exists for this page load.
 *
 * `app.ts` only calls `configureEcho()` when the server actually configured a
 * Reverb key; deployments with `BROADCAST_CONNECTION=log` have no key, and
 * Pusher throws when one is missing. Real-time composables must therefore
 * no-op instead of subscribing.
 */
export function hasEcho(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return Boolean((window as unknown as { Echo?: unknown }).Echo);
}
