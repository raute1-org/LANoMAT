import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ModuleKey } from '@/types';

export function useModules() {
    const page = usePage();
    const enabled = computed<ModuleKey[]>(() => page.props.modules ?? []);

    return {
        has: (key: ModuleKey): boolean => enabled.value.includes(key),
    };
}
