<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { ServerInstallDto } from '@/types';
import { useNowLabels } from './useNowLabels';

const props = defineProps<{
    connectString: string;
    connectIsLink: boolean;
    primary?: boolean;
    label?: string;
    /** The row's matching recipe: one quiet way to the game's install page. */
    install?: ServerInstallDto | null;
}>();

const t = useNowLabels();
const copied = ref(false);
const failed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const gameHref = computed(() => {
    const url = props.install?.pageUrl;

    return typeof url === 'string' && url !== '' ? url : null;
});

async function copy() {
    clearTimeout(timer);

    try {
        await navigator.clipboard.writeText(props.connectString);
        copied.value = true;
        failed.value = false;
        timer = setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard unavailable (insecure context, denied): show the address
        // so it can be copied by hand; it stays until the next click.
        copied.value = false;
        failed.value = true;
    }
}

onUnmounted(() => clearTimeout(timer));
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1">
        <Button
            v-if="connectIsLink"
            as-child
            size="sm"
            :variant="primary ? 'default' : 'outline'"
        >
            <a :href="connectString">{{ label ?? t('join') }}</a>
        </Button>
        <template v-else>
            <Button
                type="button"
                size="sm"
                :variant="primary ? 'default' : 'outline'"
                :title="t('copy_address')"
                @click="copy"
            >
                {{ label ?? t('join') }}
                <span class="sr-only">: {{ t('copy_address') }}</span>
            </Button>
            <span
                class="font-mono text-xs tabular-nums"
                :class="[
                    failed ? 'text-foreground' : 'text-ok',
                    !copied && !failed && 'sr-only',
                ]"
                role="status"
                aria-live="polite"
            >
                <template v-if="copied">{{ t('copied') }}</template>
                <template v-else-if="failed">{{
                    t('copy_failed', { address: connectString })
                }}</template>
            </span>
        </template>

        <Link
            v-if="gameHref"
            :href="gameHref"
            class="inline-flex min-h-8 items-center rounded-sm px-1 text-xs text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11"
        >
            {{ t('to_game') }}
        </Link>
    </span>
</template>
