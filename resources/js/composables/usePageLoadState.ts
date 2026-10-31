import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onMounted, onUnmounted, ref } from 'vue';

export type PageLoadState = {
    /** A GET visit of this page (a reload, a partial refresh) is running. */
    isNavigating: Ref<boolean>;
    /** The last GET visit failed for real: a server or network error. */
    hasError: Ref<boolean>;
};

/**
 * "Loading" and "could not load" state of a page for its own reads.
 *
 * Only full GET visits count (not partial reloads). A form of the page that is submitted (an upload, a vote) must neither
 * swap the page for skeletons nor, when the server answers with validation errors, make the
 * page claim that it failed to load: the form shows its own errors. A page is failed only by a
 * real HTTP error response or a network error of a read, never by Inertia's `error` event,
 * which is just validation.
 */
export function usePageLoadState(): PageLoadState {
    const isNavigating = ref(false);
    const hasError = ref(false);
    let reading = false;
    const removers: Array<() => void> = [];

    onMounted(() => {
        removers.push(
            router.on('start', (event) => {
                // A partial reload (`only`) is a quiet refresh of one prop, like the periodic
                // status polling of a list: no skeleton, and a failed one keeps the old data.
                reading =
                    event.detail.visit.method === 'get' &&
                    event.detail.visit.only.length === 0;

                if (reading) {
                    hasError.value = false;
                    isNavigating.value = true;
                }
            }),
            router.on('finish', () => {
                if (reading) {
                    isNavigating.value = false;
                    reading = false;
                }
            }),
            router.on('httpException', () => {
                if (reading) {
                    hasError.value = true;
                }
            }),
            router.on('networkError', () => {
                if (reading) {
                    hasError.value = true;
                }
            }),
        );
    });

    onUnmounted(() => {
        removers.forEach((remove) => remove());
    });

    return { isNavigating, hasError };
}
