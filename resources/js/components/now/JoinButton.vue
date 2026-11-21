<script setup lang="ts">
import { onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useNowLabels } from './useNowLabels';

const props = defineProps<{
    connectString: string;
    connectIsLink: boolean;
    primary?: boolean;
    label?: string;
}>();

const t = useNowLabels();
const copied = ref(false);
const failed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

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
    <Button
        v-if="connectIsLink"
        as-child
        size="sm"
        :variant="primary ? 'default' : 'outline'"
    >
        <a :href="connectString">{{ label ?? t('join') }}</a>
    </Button>
    <span v-else class="inline-flex flex-wrap items-center gap-2">
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
    </span>
</template>
