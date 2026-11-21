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
    } catch {
        // Clipboard unavailable (insecure context, denied): show the address
        // so it can be copied by hand.
        copied.value = false;
        failed.value = true;
    }

    timer = setTimeout(
        () => {
            copied.value = false;
            failed.value = false;
        },
        failed.value ? 8000 : 2000,
    );
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
            :aria-label="t('copy_address')"
            :title="t('copy_address')"
            @click="copy"
        >
            {{ label ?? t('join') }}
        </Button>
        <span
            class="font-mono text-xs tabular-nums"
            :class="failed ? 'text-foreground' : 'text-ok'"
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
