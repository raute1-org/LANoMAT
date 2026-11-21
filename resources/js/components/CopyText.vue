<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    text: string;
    /** Visible button text. */
    label: string;
    copiedLabel: string;
    failedLabel: string;
    /** Accessible name; defaults to the visible label. */
    ariaLabel?: string;
}>();

const copied = ref(false);
const failed = ref(false);
const announcement = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;

async function announce(message: string) {
    // Clear first so a repeated click is announced again.
    announcement.value = '';
    await nextTick();
    announcement.value = message;
}

async function copy() {
    clearTimeout(timer);

    try {
        await navigator.clipboard.writeText(props.text);
        copied.value = true;
        failed.value = false;
        void announce(props.copiedLabel);
    } catch {
        copied.value = false;
        failed.value = true;
        void announce(props.failedLabel);
    }

    timer = setTimeout(() => {
        copied.value = false;
        failed.value = false;
    }, 4000);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-2">
        <Button
            type="button"
            variant="outline"
            size="sm"
            data-test="copy-text"
            :aria-label="ariaLabel ?? label"
            @click="copy"
        >
            <component :is="copied ? Check : Copy" class="size-4" />
            {{ label }}
        </Button>
        <span class="sr-only" role="status" aria-live="polite">{{
            announcement
        }}</span>
        <span
            v-if="copied"
            aria-hidden="true"
            class="font-mono text-xs text-ok"
            >{{ copiedLabel }}</span
        >
        <span v-if="failed" aria-hidden="true" class="text-xs text-warn">{{
            failedLabel
        }}</span>
    </span>
</template>
