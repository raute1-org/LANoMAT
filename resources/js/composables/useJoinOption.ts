import type { ComputedRef } from 'vue';
import { computed, ref } from 'vue';
import type { JoinOptionDto } from '@/types';

const STORAGE_KEY = 'lanomat.join-option';
const ALLOWED = /^(steam|ut2004|https?):\/\//;

/**
 * The one scheme allowlist for join links: a server-built join URL only becomes
 * a clickable link on these schemes. A plain `host:port` or anything else stays
 * copy-only instead of turning into a dead or invented URL.
 */
export function isAllowedJoinUrl(url: string): boolean {
    return ALLOWED.test(url);
}

function remembered(): string | null {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

// One shared choice: picking a way to join in the expanded panel also drives
// the "Verbinden" button of every collapsed row.
const chosenKey = ref<string | null>(remembered());

export function choosePreferredJoinOption(key: string): void {
    chosenKey.value = key;

    try {
        localStorage.setItem(STORAGE_KEY, key);
    } catch {
        // Storage can be blocked; the choice then lasts for this page view only.
    }
}

/** Options on the scheme allowlist, and the one to use (remembered, else first). */
export function useJoinOption(options: () => JoinOptionDto[]): {
    safeOptions: ComputedRef<JoinOptionDto[]>;
    selected: ComputedRef<JoinOptionDto | null>;
} {
    const safeOptions = computed(() =>
        options().filter((option) => isAllowedJoinUrl(option.url)),
    );
    const selected = computed(
        () =>
            safeOptions.value.find((o) => o.key === chosenKey.value) ??
            safeOptions.value[0] ??
            null,
    );

    return { safeOptions, selected };
}
