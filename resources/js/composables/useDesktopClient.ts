import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { DesktopClientOffer } from '@/types/desktopClient';

/** The shared `desktopClient` prop: null for guests and when the WebVoice module is off. */
export function useDesktopClient() {
    const page = usePage();
    const client = computed<DesktopClientOffer | null>(
        () => page.props.desktopClient ?? null,
    );

    return { client };
}
