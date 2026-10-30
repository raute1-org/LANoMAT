import { useConnectionStatus } from '@laravel/echo-vue';
import { onMounted, onUnmounted, watch } from 'vue';

/** Never reload more often than this, however noisy the connection or tab focus is. */
const MIN_RELOAD_INTERVAL_MS = 5_000;

/**
 * Realtime events are signals, not state: a client that was offline (Reverb
 * restart, flaky Wi-Fi, a sleeping phone tab) simply missed them. This
 * re-pulls the page state once the websocket is connected again after a drop
 * and when a hidden tab becomes visible, so the view cannot stay stale until
 * the next unrelated broadcast.
 *
 * `reload` must be a cheap partial reload (`router.reload({ only: [...] })`).
 */
export function useResync(reload: () => void): {
    status: ReturnType<typeof useConnectionStatus>;
} {
    const status = useConnectionStatus();
    let dropped = status.value !== 'connected' && status.value !== 'connecting';
    let lastReload = 0;

    function reloadThrottled(): void {
        const now = Date.now();

        if (now - lastReload < MIN_RELOAD_INTERVAL_MS) {
            return;
        }

        lastReload = now;
        reload();
    }

    const stop = watch(status, (next) => {
        if (next === 'connected') {
            if (dropped) {
                dropped = false;
                reloadThrottled();
            }

            return;
        }

        if (next !== 'connecting') {
            dropped = true;
        }
    });

    function onVisibility(): void {
        if (document.visibilityState === 'visible') {
            reloadThrottled();
        }
    }

    onMounted(() =>
        document.addEventListener('visibilitychange', onVisibility),
    );
    onUnmounted(() => {
        stop();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { status };
}
